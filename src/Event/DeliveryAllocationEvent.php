<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Event;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\RootItem;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched in the cart calculation after the strategy allocated the items
 * and the customer's adjustments were applied. Change the allocation with
 * setAllocation(), for example to keep certain products together.
 *
 * Public API: stable within a major version.
 */
class DeliveryAllocationEvent extends Event implements ShopwareSalesChannelEvent
{
    /**
     * @param array<string, RootItem> $roots
     */
    public function __construct(
        private Allocation $allocation,
        private readonly array $roots,
        private readonly string $strategy,
        private readonly DeliveryPlan $plan,
        private readonly Cart $cart,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    public function getAllocation(): Allocation
    {
        return $this->allocation;
    }

    public function setAllocation(Allocation $allocation): void
    {
        $this->allocation = $allocation;
    }

    /**
     * @return array<string, RootItem> top-level cart items with their delivery positions, keyed by line item id
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function getPlan(): DeliveryPlan
    {
        return $this->plan;
    }

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function getSalesChannelContext(): SalesChannelContext
    {
        return $this->salesChannelContext;
    }

    public function getContext(): Context
    {
        return $this->salesChannelContext->getContext();
    }
}
