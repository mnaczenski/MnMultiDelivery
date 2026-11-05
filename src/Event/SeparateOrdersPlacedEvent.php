<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Event;

use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a checkout placed separate orders for deliveries to other
 * countries, once all orders exist and are linked. Each order also went
 * through Shopware's own CheckoutOrderPlacedEvent (mails, flows).
 *
 * Public API: stable within a major version.
 */
class SeparateOrdersPlacedEvent extends Event implements ShopwareSalesChannelEvent
{
    /**
     * @param list<OrderEntity> $orders all orders of the checkout, the main order last
     */
    public function __construct(
        private readonly string $orderGroupId,
        private readonly array $orders,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    public function getOrderGroupId(): string
    {
        return $this->orderGroupId;
    }

    /**
     * @return list<OrderEntity>
     */
    public function getOrders(): array
    {
        return $this->orders;
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
