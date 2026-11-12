<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Service;

use Mn\MultiDelivery\Cart\DeliveryGroupInfo;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Cart\SplitDeliveryProcessor;
use Mn\MultiDelivery\Event\DeliveryPlanChangedEvent;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderCalculator;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Changes the delivery plan of the current cart and recalculates it. Shared
 * by the storefront routes and the Store API, so both behave the same.
 * Every change dispatches DeliveryPlanChangedEvent.
 *
 * Public API: the public methods are stable within a major version.
 */
class DeliveryPlanService
{
    public const MAX_MANUAL_GROUPS = 5;

    /** Deliveries with their own address or shipping method, at most. */
    public const MAX_CONFIGURED_GROUPS = 30;

    public function __construct(
        private readonly CartService $cartService,
        private readonly SplitGuard $guard,
        private readonly StrategyRegistry $strategies,
        private readonly SplitSettings $settings,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly ?SeparateOrderCalculator $separateOrders = null,
    ) {
    }

    public function getPlan(Cart $cart, SalesChannelContext $context): DeliveryPlan
    {
        $plan = $cart->getExtension(DeliveryPlan::EXTENSION);

        return $plan instanceof DeliveryPlan ? $plan : $this->settings->defaultPlan($context);
    }

    public function toggle(SalesChannelContext $context, bool $enabled): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_TOGGLE, static function (DeliveryPlan $plan) use ($enabled): void {
            $plan->setEnabled($enabled);
        });
    }

    public function setStrategy(SalesChannelContext $context, string $name): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_STRATEGY, function (DeliveryPlan $plan) use ($name, $context): void {
            if (\in_array($name, $this->strategies->availableNames($context), true)) {
                $plan->setStrategy($name);
            }
        });
    }

    /**
     * Address and shipping method of a delivery. Only for deliveries the
     * planner shows (the first one, added ones, those of the current split),
     * so the stored plan can't grow without limit. The address and shipping
     * method are checked when the cart is calculated (SplitDeliveryProcessor).
     */
    public function setGroup(SalesChannelContext $context, string $groupId, ?string $addressId, ?string $shippingMethodId): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_GROUP, function (DeliveryPlan $plan, Cart $cart) use ($groupId, $addressId, $shippingMethodId): void {
            $known = [DeliveryPlan::MAIN_GROUP, ...$plan->getManualGroups(), ...$this->deliveryGroupIds($cart)];
            $configured = $plan->getConfiguredGroupIds();
            if (!\in_array($groupId, $known, true)) {
                return;
            }
            if (!\in_array($groupId, $configured, true) && \count($configured) >= self::MAX_CONFIGURED_GROUPS) {
                return;
            }
            $plan->setGroupSettings($groupId, self::hexOrNull($addressId), self::hexOrNull($shippingMethodId));
        });
    }

    /**
     * Sends a delivery to another address and keeps its shipping method,
     * for example to an address the customer has just created in the planner.
     * Same limits and checks as setGroup().
     */
    public function setGroupAddress(SalesChannelContext $context, string $groupId, string $addressId): Cart
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $shippingMethodId = $this->getPlan($cart, $context)->getGroupSettings($groupId)['shippingMethodId'] ?? null;

        return $this->setGroup($context, $groupId, $addressId, $shippingMethodId);
    }

    public function addGroup(SalesChannelContext $context): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_ADD_GROUP, static function (DeliveryPlan $plan): void {
            if (\count($plan->getManualGroups()) < self::MAX_MANUAL_GROUPS) {
                $plan->addManualGroup();
            }
        });
    }

    public function removeGroup(SalesChannelContext $context, string $groupId): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_REMOVE_GROUP, static function (DeliveryPlan $plan) use ($groupId): void {
            $plan->removeManualGroup($groupId);
        });
    }

    /**
     * Moves a quantity of a top-level cart item from one delivery to another.
     * With the manual strategy this edits the customer's own deliveries; with
     * an automatic strategy it is stored as an adjustment of the split.
     */
    public function move(SalesChannelContext $context, string $lineItemId, string $fromGroupId, string $toGroupId, int $quantity): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_MOVE, function (DeliveryPlan $plan, Cart $cart) use ($context, $lineItemId, $fromGroupId, $toGroupId, $quantity): void {
            $strategy = $this->strategies->get($plan->getStrategy(), $context);
            if (!$strategy->allowsManualMoves() && !$strategy->allowsAdjustments()) {
                return;
            }

            $current = $this->distribution($cart, $lineItemId);
            $available = $current[$fromGroupId] ?? 0;
            if ($available === 0 || $fromGroupId === $toGroupId || !\in_array($toGroupId, $this->moveTargets($plan, $cart, $context), true)) {
                return;
            }

            $moved = $quantity > 0 ? min($quantity, $available) : $available;
            $current[$fromGroupId] = $available - $moved;
            $current[$toGroupId] = ($current[$toGroupId] ?? 0) + $moved;

            if ($strategy->allowsManualMoves()) {
                // Whatever is not assigned to a manual delivery stays in the first one.
                unset($current[DeliveryPlan::MAIN_GROUP]);
                $plan->setAssignments($lineItemId, $current);

                return;
            }
            $plan->setAdjustment($strategy->getName(), $lineItemId, $current);
        });
    }

    /** Drops the customer's adjustments of the current automatic split. */
    public function reset(SalesChannelContext $context): Cart
    {
        return $this->change($context, DeliveryPlanChangedEvent::ACTION_RESET, static function (DeliveryPlan $plan): void {
            $plan->resetAdjustments($plan->getStrategy());
        });
    }

    /**
     * Everything a client needs to render the planner.
     *
     * "separateOrders" describes deliveries to other countries (setting
     * countryMode): whether they are possible and, when the cart has some,
     * every order the checkout will place with its calculated cart.
     *
     * @return array{plan: DeliveryPlan, blockReason: ?string, strategies: list<string>, strategy: string, manualMoves: bool, adjustable: bool, adjusted: bool, manualGroups: list<string>, moveTargets: list<string>, groupNumbers: array<string, int>, separateOrders: array<string, mixed>, extraShippingCosts: ?float}
     */
    public function describe(Cart $cart, SalesChannelContext $context): array
    {
        $plan = $this->getPlan($cart, $context);
        $strategy = $this->strategies->get($plan->getStrategy(), $context);

        $groupNumbers = [];
        foreach ($this->deliveryGroupIds($cart) as $groupId) {
            $groupNumbers[$groupId] = \count($groupNumbers) + 1;
        }
        if ($strategy->allowsManualMoves()) {
            foreach ([DeliveryPlan::MAIN_GROUP, ...$plan->getManualGroups()] as $groupId) {
                $groupNumbers[$groupId] ??= \count($groupNumbers) + 1;
            }
        }

        return [
            'plan' => $plan,
            'blockReason' => $this->guard->blockReason($cart, $context),
            'strategies' => $this->strategies->availableNames($context),
            'strategy' => $strategy->getName(),
            'manualMoves' => $strategy->allowsManualMoves(),
            'adjustable' => $strategy->allowsAdjustments(),
            'adjusted' => $strategy->allowsAdjustments() && $plan->getAdjustments($strategy->getName()) !== [],
            'manualGroups' => $plan->getManualGroups(),
            'moveTargets' => $this->moveTargets($plan, $cart, $context),
            'groupNumbers' => $groupNumbers,
            'separateOrders' => $this->describeSeparateOrders($cart, $context),
            'extraShippingCosts' => $this->extraShippingCosts($cart),
        ];
    }

    /**
     * What the split adds to the shipping costs, compared with the single
     * delivery Shopware would have built: what the customer saves by shipping
     * everything together. Null when the cart isn't split.
     */
    private function extraShippingCosts(Cart $cart): ?float
    {
        $single = $cart->getExtension(SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION);
        if (!$single instanceof ArrayStruct || $cart->getDeliveries()->count() < 2) {
            return null;
        }

        return max(0.0, round($cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice() - (float) $single->get('shippingCosts'), 2));
    }

    /**
     * @return array{enabled: bool, mode: string, paymentAllowed: bool, orders: list<array<string, mixed>>, separateGroupIds: list<string>, totalPrice: float}
     */
    private function describeSeparateOrders(Cart $cart, SalesChannelContext $context): array
    {
        $mode = $this->settings->countryMode($context);
        $enabled = $mode !== SplitSettings::COUNTRY_SAME;
        $result = [
            'enabled' => $enabled,
            'mode' => $mode,
            'paymentAllowed' => $enabled && $this->settings->separateOrderPaymentAllowed($context),
            'orders' => [],
            'separateGroupIds' => [],
            'totalPrice' => 0.0,
        ];

        $calculated = $result['paymentAllowed'] ? $this->separateOrders?->calculate($cart, $context) : null;
        if ($calculated === null) {
            return $result;
        }

        $split = $calculated['split'];
        foreach ($split->parts as $part) {
            $result['orders'][] = $this->describeOrder($part->cart, $part->groupId, false, $part->address->getId(), $part->address->getCountry());
            $result['separateGroupIds'][] = $part->groupId;
        }
        if ($split->hasMainOrder()) {
            $result['orders'][] = $this->describeOrder($split->mainCart, null, true, null, $context->getShippingLocation()->getCountry());
        }
        $result['totalPrice'] = round(array_sum(array_map(static fn (array $order) => $order['totalPrice'], $result['orders'])), 2);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function describeOrder(Cart $cart, ?string $groupId, bool $main, ?string $addressId, ?CountryEntity $country): array
    {
        $price = $cart->getPrice();
        $taxes = [];
        foreach ($price->getCalculatedTaxes() as $tax) {
            $taxes[] = ['taxRate' => $tax->getTaxRate(), 'tax' => $tax->getTax()];
        }
        $lineItems = [];
        foreach ($cart->getLineItems() as $lineItem) {
            $lineItems[$lineItem->getId()] = $lineItem->getQuantity();
        }
        $errors = [];
        foreach ($cart->getErrors() as $error) {
            if ($error->blockOrder()) {
                $errors[] = $error->getMessageKey();
            }
        }

        return [
            'main' => $main,
            'groupId' => $groupId,
            'addressId' => $addressId,
            'countryId' => $country?->getId(),
            'country' => $country,
            'lineItems' => $lineItems,
            'positionPrice' => $price->getPositionPrice(),
            'shippingCosts' => $cart->getShippingCosts()->getTotalPrice(),
            'netPrice' => $price->getNetPrice(),
            'totalPrice' => $price->getTotalPrice(),
            'taxStatus' => $price->getTaxStatus(),
            'calculatedTaxes' => $taxes,
            'blockingErrors' => $errors,
            'cart' => $cart,
        ];
    }

    /**
     * @param callable(DeliveryPlan, Cart): void $change
     */
    private function change(SalesChannelContext $context, string $action, callable $change): Cart
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        if (!$this->guard->isActive($context)) {
            return $cart;
        }

        $plan = $this->getPlan($cart, $context);
        $cart->addExtension(DeliveryPlan::EXTENSION, $plan);
        $change($plan, $cart);
        $cart = $this->cartService->recalculate($cart, $context);
        $this->dispatcher->dispatch(new DeliveryPlanChangedEvent($action, $plan, $cart, $context));

        return $cart;
    }

    /**
     * @return list<string>
     */
    private function moveTargets(DeliveryPlan $plan, Cart $cart, SalesChannelContext $context): array
    {
        $strategy = $this->strategies->get($plan->getStrategy(), $context);
        if ($strategy->allowsManualMoves()) {
            return [DeliveryPlan::MAIN_GROUP, ...$plan->getManualGroups()];
        }

        return $strategy->allowsAdjustments() ? $this->deliveryGroupIds($cart) : [];
    }

    /**
     * @return list<string>
     */
    private function deliveryGroupIds(Cart $cart): array
    {
        $ids = [];
        foreach ($cart->getDeliveries() as $delivery) {
            $info = $delivery->getExtension(DeliveryGroupInfo::EXTENSION);
            $ids[] = $info instanceof DeliveryGroupInfo ? $info->getGroupId() : DeliveryPlan::MAIN_GROUP;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Current quantity of a top-level item per delivery group.
     *
     * @return array<string, int>
     */
    private function distribution(Cart $cart, string $lineItemId): array
    {
        $quantities = [];
        foreach ($cart->getDeliveries() as $delivery) {
            $info = $delivery->getExtension(DeliveryGroupInfo::EXTENSION);
            $groupId = $info instanceof DeliveryGroupInfo ? $info->getGroupId() : DeliveryPlan::MAIN_GROUP;
            $position = $delivery->getPositions()->get($lineItemId);
            if ($position !== null) {
                $quantities[$groupId] = ($quantities[$groupId] ?? 0) + $position->getQuantity();
            }
        }

        return $quantities;
    }

    private static function hexOrNull(?string $value): ?string
    {
        return $value !== null && preg_match('/^[0-9a-f]{32}$/', $value) ? $value : null;
    }
}
