<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Event;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched in the cart calculation when the split deliveries are built and
 * priced, right before they replace Shopware's single delivery. Deliveries
 * can be changed in place or replaced; fewer than two keeps the single delivery.
 *
 * Shipping discounts (PromotionDeliveryProcessor) are applied afterwards.
 *
 * Public API: stable within a major version.
 */
class SplitDeliveriesBuiltEvent extends Event implements ShopwareSalesChannelEvent
{
    public function __construct(
        private DeliveryCollection $deliveries,
        private readonly DeliveryPlan $plan,
        private readonly Cart $cart,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    public function getDeliveries(): DeliveryCollection
    {
        return $this->deliveries;
    }

    public function setDeliveries(DeliveryCollection $deliveries): void
    {
        $this->deliveries = $deliveries;
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
