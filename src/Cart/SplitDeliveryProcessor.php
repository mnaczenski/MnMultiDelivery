<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Cart;

use Mn\MultiDelivery\Event\DeliveryAllocationEvent;
use Mn\MultiDelivery\Event\SplitDeliveriesBuiltEvent;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Policy\ShippingCostPolicyInterface;
use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Service\SplitSettings;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\RootItem;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Splits the single delivery Shopware builds into several deliveries.
 *
 * Runs at priority -5050: after DeliveryProcessor (-5000) has built and
 * priced the default delivery, before PromotionDeliveryProcessor (-5100)
 * applies shipping discounts, so discounts land on the final deliveries.
 * Without an enabled delivery plan, or for carts the SplitGuard blocks,
 * the cart is left exactly as Shopware built it. Carts without a plan use
 * the merchant's default plan (auto split).
 *
 * Extension points, in order: the strategy (DeliverySplitStrategyInterface),
 * DeliveryAllocationEvent, the address policies, the shipping cost policy
 * and SplitDeliveriesBuiltEvent.
 *
 * @internal use the extension points above; the processor itself may change
 */
class SplitDeliveryProcessor implements CartProcessorInterface, CartDataCollectorInterface
{
    private const ADDRESS_KEY = 'mn-md-address-';

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection> $addressRepository
     * @param EntityRepository<\Shopware\Core\Checkout\Shipping\ShippingMethodCollection>                           $shippingMethodRepository
     */
    public function __construct(
        private readonly SplitGuard $guard,
        private readonly StrategyRegistry $strategies,
        private readonly DeliveryCalculator $deliveryCalculator,
        private readonly EntityRepository $addressRepository,
        private readonly EntityRepository $shippingMethodRepository,
        private readonly SplitSettings $settings,
        private readonly ShippingCostPolicyInterface $shippingCostPolicy,
        private readonly DeliveryAddressPolicies $addressPolicies,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function collect(CartDataCollection $data, Cart $original, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $plan = $this->plan($original, $context);
        if (!$plan->isEnabled() || $this->guard->blockReason($original, $context, $behavior) !== null) {
            return;
        }

        $addressIds = [];
        $shippingMethodIds = [];
        foreach ($plan->getConfiguredGroupIds() as $groupId) {
            $settings = $plan->getGroupSettings($groupId);
            if (!empty($settings['addressId']) && !$data->has(self::ADDRESS_KEY . $settings['addressId'])) {
                $addressIds[] = $settings['addressId'];
            }
            if (!empty($settings['shippingMethodId']) && !$data->has(DeliveryProcessor::buildKey($settings['shippingMethodId']))) {
                $shippingMethodIds[] = $settings['shippingMethodId'];
            }
        }

        $customerId = $context->getCustomer()?->getId();
        if ($addressIds !== [] && $customerId !== null) {
            $criteria = (new Criteria(array_values(array_unique($addressIds))))
                ->addAssociations(['country', 'countryState', 'salutation'])
                ->addFilter(new EqualsFilter('customerId', $customerId));
            foreach ($this->addressRepository->search($criteria, $context->getContext())->getEntities() as $address) {
                $data->set(self::ADDRESS_KEY . $address->getId(), $address);
            }
        }

        if ($shippingMethodIds !== []) {
            // Same associations as Shopware's DeliveryProcessor, so DeliveryCalculator can price them.
            // Only active methods of this sales channel: the ids come from the client, and unlike
            // Shopware's context switch nothing else checks the assignment. Availability rules are
            // checked by Shopware's DeliveryValidator for every delivery.
            $criteria = (new Criteria(array_values(array_unique($shippingMethodIds))))
                ->addAssociations(['prices', 'deliveryTime', 'tax'])
                ->addFilter(new EqualsFilter('active', true))
                ->addFilter(new EqualsFilter('salesChannels.id', $context->getSalesChannelId()));
            foreach ($this->shippingMethodRepository->search($criteria, $context->getContext())->getEntities() as $method) {
                $data->set(DeliveryProcessor::buildKey($method->getId()), $method);
            }
        }
    }

    public function process(CartDataCollection $data, Cart $original, Cart $toCalculate, SalesChannelContext $context, CartBehavior $behavior): void
    {
        $plan = $this->plan($toCalculate, $context);
        if (!$plan->isEnabled()) {
            return;
        }
        if ($this->guard->blockReason($toCalculate, $context, $behavior) !== null) {
            return;
        }

        $deliveries = $toCalculate->getDeliveries();
        $base = $deliveries->first();
        if ($deliveries->count() !== 1 || !$base instanceof Delivery) {
            return;
        }

        $roots = $this->collectRoots($toCalculate, $base);
        if ($roots === []) {
            return;
        }

        $strategy = $this->strategies->get($plan->getStrategy(), $context);
        $allocation = $strategy->allocate($roots, $plan, $context);
        if ($strategy->allowsAdjustments()) {
            $allocation = $this->applyAdjustments($allocation, $plan->getAdjustments($strategy->getName()), $roots);
        }
        $event = new DeliveryAllocationEvent($allocation, $roots, $strategy->getName(), $plan, $toCalculate, $context);
        $allocation = $this->dispatcher->dispatch($event)->getAllocation();

        $split = $this->buildDeliveries($allocation, $roots, $base, $plan, $data, $context);
        if ($split->count() < 2) {
            return;
        }

        $this->deliveryCalculator->calculate($data, $toCalculate, $split, $context);
        $this->shippingCostPolicy->apply($split, $context);

        $split = $this->dispatcher->dispatch(new SplitDeliveriesBuiltEvent($split, $plan, $toCalculate, $context))->getDeliveries();
        if ($split->count() < 2) {
            return;
        }
        $toCalculate->setDeliveries($split);
    }

    /**
     * Applies the customer's moves on top of an automatic split. An adjustment
     * is ignored when it no longer fits the cart (quantity changed, group gone).
     *
     * @param array<string, array<string, int>> $adjustments
     * @param array<string, RootItem>           $roots
     */
    private function applyAdjustments(Allocation $allocation, array $adjustments, array $roots): Allocation
    {
        foreach ($adjustments as $rootId => $wanted) {
            $root = $roots[$rootId] ?? null;
            if ($root === null || $wanted === [] || array_sum($wanted) !== $root->getQuantity()
                || array_diff_key($wanted, $allocation->groups) !== [] || (!$root->isSplittable() && \count($wanted) > 1)) {
                continue;
            }
            foreach ($wanted as $groupId => $quantity) {
                // A group receiving items it did not have must not use in-stock dates for them.
                $group = $allocation->groups[$groupId];
                if ($group->inStockDates && $quantity > ($allocation->quantities[$rootId][$groupId] ?? 0)) {
                    $allocation = $allocation->withGroup($group->withInStockDates(false));
                }
            }
            $allocation = $allocation->withQuantities($rootId, $wanted);
        }

        return $allocation;
    }

    private function plan(Cart $cart, SalesChannelContext $context): DeliveryPlan
    {
        $plan = $cart->getExtension(DeliveryPlan::EXTENSION);

        return $plan instanceof DeliveryPlan ? $plan : $this->settings->defaultPlan($context);
    }


    /**
     * @return array<string, RootItem>
     */
    private function collectRoots(Cart $cart, Delivery $base): array
    {
        $positionsById = [];
        foreach ($base->getPositions() as $position) {
            $positionsById[$position->getIdentifier()] = $position;
        }

        $roots = [];
        foreach ($cart->getLineItems() as $lineItem) {
            $positions = [];
            foreach ($this->flatIds($lineItem) as $id) {
                if (isset($positionsById[$id])) {
                    $positions[] = $positionsById[$id];
                }
            }
            if ($positions !== []) {
                $roots[$lineItem->getId()] = new RootItem($lineItem, $positions);
            }
        }

        return $roots;
    }

    /**
     * @return list<string>
     */
    private function flatIds(LineItem $lineItem): array
    {
        $ids = [$lineItem->getId()];
        foreach ($lineItem->getChildren() as $child) {
            $ids = [...$ids, ...$this->flatIds($child)];
        }

        return $ids;
    }

    /**
     * @param array<string, RootItem> $roots
     */
    private function buildDeliveries(Allocation $allocation, array $roots, Delivery $base, DeliveryPlan $plan, CartDataCollection $data, SalesChannelContext $context): DeliveryCollection
    {
        /** @var array<string, list<DeliveryPosition>> $positionsByGroup */
        $positionsByGroup = [];
        foreach ($allocation->quantities as $rootId => $groups) {
            $root = $roots[$rootId] ?? null;
            if ($root === null) {
                continue;
            }
            foreach ($groups as $groupId => $quantity) {
                $group = $allocation->getGroup($groupId);
                if ($quantity <= 0 || $group === null) {
                    continue;
                }
                $inStockDates = $group->inStockDates;
                foreach ($root->positions as $position) {
                    $positionsByGroup[$groupId][] = $root->isSplittable()
                        ? $this->partialPosition($position, $quantity, $inStockDates, $base->getShippingMethod())
                        : $position;
                }
            }
        }

        $deliveries = new DeliveryCollection();
        foreach ($allocation->groups as $groupId => $group) {
            $positions = $positionsByGroup[$groupId] ?? [];
            if ($positions === []) {
                continue;
            }

            $settings = $plan->getGroupSettings($groupId);
            $delivery = new Delivery(
                new DeliveryPositionCollection($positions),
                $this->latestDate($positions),
                $this->shippingMethod($settings['shippingMethodId'] ?? null, $base, $data),
                $this->location($settings['addressId'] ?? null, $base, $data, $context),
                new CalculatedPrice(0, 0, new CalculatedTaxCollection(), $base->getShippingCosts()->getTaxRules())
            );
            $delivery->addExtension(DeliveryGroupInfo::EXTENSION, new DeliveryGroupInfo(
                $groupId,
                $group->label,
                $group->locked,
                $group->warehouseId,
                $group->warehouseName,
            ));
            $deliveries->add($delivery);
        }

        return $deliveries;
    }

    private function partialPosition(DeliveryPosition $position, int $quantity, bool $inStockDates, ShippingMethodEntity $shippingMethod): DeliveryPosition
    {
        $date = $position->getDeliveryDate();
        if ($inStockDates) {
            // The in-stock part ships on the normal delivery time, not the restock date of the whole position.
            $deliveryTime = $position->getLineItem()->getDeliveryInformation()?->getDeliveryTime()
                ?? ($shippingMethod->getDeliveryTime() !== null ? DeliveryTime::createFromEntity($shippingMethod->getDeliveryTime()) : null);
            if ($deliveryTime !== null) {
                $date = DeliveryDate::createFromDeliveryTime($deliveryTime);
            }
        }

        if ($quantity === $position->getQuantity()) {
            return new DeliveryPosition($position->getIdentifier(), $position->getLineItem(), $quantity, $position->getPrice(), $date);
        }

        $price = $position->getPrice();
        $factor = $quantity / max(1, $position->getQuantity());
        $taxes = new CalculatedTaxCollection();
        foreach ($price->getCalculatedTaxes() as $tax) {
            $taxes->add(new CalculatedTax($tax->getTax() * $factor, $tax->getTaxRate(), $tax->getPrice() * $factor, $tax->getLabel()));
        }
        $partialPrice = new CalculatedPrice(
            $price->getUnitPrice(),
            round($price->getUnitPrice() * $quantity, 2),
            $taxes,
            $price->getTaxRules(),
            $quantity,
            $price->getReferencePrice(),
            $price->getListPrice(),
            $price->getRegulationPrice(),
        );

        return new DeliveryPosition($position->getIdentifier(), $position->getLineItem(), $quantity, $partialPrice, $date);
    }

    /**
     * @param list<DeliveryPosition> $positions
     */
    private function latestDate(array $positions): DeliveryDate
    {
        $earliest = $positions[0]->getDeliveryDate()->getEarliest();
        $latest = $positions[0]->getDeliveryDate()->getLatest();
        foreach ($positions as $position) {
            $earliest = max($earliest, $position->getDeliveryDate()->getEarliest());
            $latest = max($latest, $position->getDeliveryDate()->getLatest());
        }
        if ($earliest->format('Y-m-d') === $latest->format('Y-m-d')) {
            $latest = $latest->add(new \DateInterval('P1D'));
        }

        return new DeliveryDate($earliest, $latest);
    }

    private function shippingMethod(?string $shippingMethodId, Delivery $base, CartDataCollection $data): ShippingMethodEntity
    {
        if ($shippingMethodId !== null) {
            $method = $data->get(DeliveryProcessor::buildKey($shippingMethodId));
            if ($method instanceof ShippingMethodEntity) {
                return $method;
            }
        }

        return $base->getShippingMethod();
    }

    /**
     * The chosen address, if the address policies allow it (by default: same
     * country as the main shipping address, see SameCountryAddressPolicy).
     */
    private function location(?string $addressId, Delivery $base, CartDataCollection $data, SalesChannelContext $context): ShippingLocation
    {
        if ($addressId !== null) {
            $address = $data->get(self::ADDRESS_KEY . $addressId);
            if ($address instanceof CustomerAddressEntity
                && $address->getCustomerId() === $context->getCustomer()?->getId()
                && $address->getCountry() !== null
                && $this->addressPolicies->isAllowed($address, $context)) {
                return ShippingLocation::createFromAddress($address);
            }
        }

        return $base->getLocation();
    }
}
