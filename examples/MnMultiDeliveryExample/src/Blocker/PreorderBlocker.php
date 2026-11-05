<?php declare(strict_types=1);

namespace Mn\MultiDeliveryExample\Blocker;

use Mn\MultiDelivery\Policy\SplitBlockerInterface;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Carts with pre-order items (custom field "example_preorder" on the
 * product) ship in one delivery. Customers see the snippet
 * mnMultiDelivery.blocked.preorder.
 */
class PreorderBlocker implements SplitBlockerInterface
{
    public const REASON = 'preorder';

    public function blockReason(Cart $cart, SalesChannelContext $context, CartBehavior $behavior): ?string
    {
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            if (($lineItem->getPayload()['customFields']['example_preorder'] ?? false) === true) {
                return self::REASON;
            }
        }

        return null;
    }
}
