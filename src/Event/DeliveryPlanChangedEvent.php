<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Event;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\ShopwareSalesChannelEvent;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after the customer changed the delivery plan (storefront or
 * Store API) and the cart was recalculated.
 *
 * Public API: stable within a major version.
 */
class DeliveryPlanChangedEvent extends Event implements ShopwareSalesChannelEvent
{
    public const ACTION_TOGGLE = 'toggle';
    public const ACTION_STRATEGY = 'strategy';
    public const ACTION_GROUP = 'group';
    public const ACTION_ADD_GROUP = 'add-group';
    public const ACTION_REMOVE_GROUP = 'remove-group';
    public const ACTION_MOVE = 'move';
    public const ACTION_RESET = 'reset';

    public function __construct(
        private readonly string $action,
        private readonly DeliveryPlan $plan,
        private readonly Cart $cart,
        private readonly SalesChannelContext $salesChannelContext,
    ) {
    }

    /**
     * @return self::ACTION_*
     */
    public function getAction(): string
    {
        /** @var self::ACTION_* $action set by the constructor from these constants */
        $action = $this->action;

        return $action;
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
