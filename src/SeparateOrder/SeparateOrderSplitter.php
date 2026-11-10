<?php declare(strict_types=1);

namespace Mn\MultiDelivery\SeparateOrder;

use Mn\MultiDelivery\Cart\DeliveryGroupInfo;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Service\SplitSettings;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Divides a calculated cart along its deliveries. Hybrid (separateOrders):
 * every delivery to an address in another country than the shipping country
 * becomes a cart of its own, the rest stays in the main cart. Every delivery
 * (allSeparate): only the main delivery in the shipping country stays in the
 * main cart, every other delivery becomes a cart of its own. Without a
 * payment method that allows separate orders, allSeparate splits like the
 * hybrid mode, so deliveries in the shipping country stay one order. The carts are not calculated;
 * Shopware does that for each with the matching country when they are
 * previewed or placed.
 *
 * Items are moved as whole cart items, so containers (bundles, sets) go with
 * all their children. Promotions, vouchers and other non-physical items stay
 * in the main cart.
 *
 * @internal
 */
class SeparateOrderSplitter
{
    public function __construct(private readonly SplitSettings $settings)
    {
    }

    /**
     * @return SeparateOrderSplit|null null when no delivery becomes a separate order or separate orders are not enabled
     */
    public function split(Cart $cart, SalesChannelContext $context): ?SeparateOrderSplit
    {
        $mode = $this->settings->countryMode($context);
        if ($mode === SplitSettings::COUNTRY_SAME) {
            return null;
        }

        $homeCountryId = $context->getShippingLocation()->getCountry()->getId();
        $rootOf = $this->rootIds($cart);
        $keep = $mode === SplitSettings::COUNTRY_ALL_SEPARATE && $this->settings->separateOrderPaymentAllowed($context)
            ? $this->mainDelivery($cart, $homeCountryId)
            : null;

        $foreign = [];
        foreach ($cart->getDeliveries() as $delivery) {
            $address = $delivery->getLocation()->getAddress();
            if (!$address instanceof CustomerAddressEntity) {
                continue;
            }
            $home = $delivery->getLocation()->getCountry()->getId() === $homeCountryId;
            if ($keep === null ? $home : $delivery === $keep) {
                continue;
            }
            $quantities = [];
            foreach ($delivery->getPositions() as $position) {
                $rootId = $rootOf[$position->getIdentifier()] ?? null;
                if ($rootId === null) {
                    continue;
                }
                $root = $cart->getLineItems()->get($rootId);
                // Children of a container: the whole container moves.
                $quantities[$rootId] = $position->getIdentifier() === $rootId
                    ? ($quantities[$rootId] ?? 0) + $position->getQuantity()
                    : (int) $root?->getQuantity();
            }
            if ($quantities === []) {
                continue;
            }
            $info = $delivery->getExtension(DeliveryGroupInfo::EXTENSION);
            $foreign[] = [
                'groupId' => $info instanceof DeliveryGroupInfo ? $info->getGroupId() : DeliveryPlan::MAIN_GROUP,
                'label' => $info instanceof DeliveryGroupInfo ? $info->getLabel() : '',
                'address' => $address,
                'shippingMethodId' => $delivery->getShippingMethod()->getId(),
                'quantities' => $quantities,
            ];
        }

        if ($foreign === []) {
            return null;
        }

        $main = $this->copy($cart);
        // A token of its own: calculating a cart can store it under its token
        // (CartRuleLoader), which must never overwrite the customer's cart.
        $main->setToken(Uuid::randomHex());
        $plan = $main->getExtension(DeliveryPlan::EXTENSION);
        $parts = [];
        foreach ($foreign as $group) {
            $partCart = new Cart(Uuid::randomHex());
            // A separate order is one delivery: no further splitting.
            $partCart->addExtension(DeliveryPlan::EXTENSION, new DeliveryPlan(false));

            foreach ($group['quantities'] as $rootId => $quantity) {
                $root = $cart->getLineItems()->get($rootId);
                if ($root === null || $quantity <= 0) {
                    continue;
                }
                $item = $this->refreshed($this->copy($root));
                if ($quantity !== $item->getQuantity()) {
                    $item->setQuantity($quantity);
                }
                $partCart->add($item);
                $this->reduce($main, $rootId, $quantity);
            }

            if ($plan instanceof DeliveryPlan) {
                $this->forget($plan, $group['groupId']);
            }
            $parts[] = new SeparateOrderPart($group['groupId'], $group['label'], $group['address'], $group['shippingMethodId'], $partCart);
        }

        foreach ($main->getLineItems() as $item) {
            $this->refreshed($item);
        }

        return new SeparateOrderSplit($main, $parts);
    }

    /**
     * The delivery that stays in the main order when every delivery becomes
     * its own order: the main group's delivery when it is in the shipping
     * country, otherwise the first delivery there. With none in the shipping
     * country, every delivery becomes a separate order.
     */
    private function mainDelivery(Cart $cart, string $homeCountryId): ?Delivery
    {
        $first = null;
        foreach ($cart->getDeliveries() as $delivery) {
            if ($delivery->getLocation()->getCountry()->getId() !== $homeCountryId) {
                continue;
            }
            $info = $delivery->getExtension(DeliveryGroupInfo::EXTENSION);
            $groupId = $info instanceof DeliveryGroupInfo ? $info->getGroupId() : DeliveryPlan::MAIN_GROUP;
            if ($groupId === DeliveryPlan::MAIN_GROUP) {
                return $delivery;
            }
            $first ??= $delivery;
        }

        return $first;
    }

    /**
     * Root cart item id of every item and child id.
     *
     * @return array<string, string>
     */
    private function rootIds(Cart $cart): array
    {
        $map = [];
        foreach ($cart->getLineItems() as $root) {
            $this->mapRoot($root, $root->getId(), $map);
        }

        return $map;
    }

    /**
     * @param array<string, string> $map
     */
    private function mapRoot(LineItem $item, string $rootId, array &$map): void
    {
        $map[$item->getId()] = $rootId;
        foreach ($item->getChildren() as $child) {
            $this->mapRoot($child, $rootId, $map);
        }
    }

    private function reduce(Cart $main, string $rootId, int $quantity): void
    {
        $item = $main->getLineItems()->get($rootId);
        if ($item === null) {
            return;
        }
        $left = $item->getQuantity() - $quantity;
        if ($left <= 0) {
            $main->getLineItems()->remove($rootId);

            return;
        }
        $item->setQuantity($left);
    }

    /**
     * The group's items are gone from the main cart, so its settings must not
     * send anything else abroad.
     */
    private function forget(DeliveryPlan $plan, string $groupId): void
    {
        if (\in_array($groupId, $plan->getManualGroups(), true)) {
            $plan->removeManualGroup($groupId);

            return;
        }
        $settings = $plan->getGroupSettings($groupId);
        $plan->setGroupSettings($groupId, null, $settings['shippingMethodId'] ?? null);
    }

    /**
     * Lets Shopware load product data again for the new country (prices and
     * tax rules depend on it).
     */
    private function refreshed(LineItem $item): LineItem
    {
        $item->setDataTimestamp(null);
        $item->setDataContextHash(null);
        foreach ($item->getChildren() as $child) {
            $this->refreshed($child);
        }

        return $item;
    }

    /**
     * Deep copy through Shopware's own clone support (CloneTrait; Cart::__clone
     * keeps the error instances). Not serialize(): calculated carts and items
     * can hold closures, which can be cloned but not serialized.
     *
     * @template T of Cart|LineItem
     *
     * @param T $value
     *
     * @return T
     */
    private function copy(Cart|LineItem $value): Cart|LineItem
    {
        return clone $value;
    }
}
