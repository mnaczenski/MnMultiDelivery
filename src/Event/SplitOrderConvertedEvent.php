<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Event;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched when a cart with split deliveries is converted into an order,
 * after the delivery group data was written to the order deliveries'
 * customFields.mn_multi_delivery. Change the order payload with
 * setConvertedCart(), for example to add fulfilment data per delivery.
 *
 * Public API: stable within a major version.
 */
class SplitOrderConvertedEvent extends Event implements ShopwareSalesChannelEvent
{
    /**
     * @param array<string, mixed> $convertedCart
     */
    public function __construct(
        private array $convertedCart,
        private readonly Cart $cart,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    /**
     * @return array<string, mixed> order payload as written by Shopware's OrderConverter
     */
    public function getConvertedCart(): array
    {
        return $this->convertedCart;
    }

    /**
     * @param array<string, mixed> $convertedCart
     */
    public function setConvertedCart(array $convertedCart): void
    {
        $this->convertedCart = $convertedCart;
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
