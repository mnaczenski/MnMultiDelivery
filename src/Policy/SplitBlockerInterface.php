<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Keeps Shopware's single delivery for carts that must not be split.
 * Tag implementations with "mn.multi_delivery.blocker".
 *
 * Customers see the snippet "mnMultiDelivery.blocked.<reason>".
 *
 * Public API: stable within a major version.
 */
interface SplitBlockerInterface
{
    /**
     * @return string|null a short reason (letters, digits, dashes) when the cart must not be split, otherwise null
     */
    public function blockReason(Cart $cart, SalesChannelContext $context, CartBehavior $behavior): ?string;
}
