<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Route;

use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Store API for the delivery plan. Decorate this class to change or extend
 * the routes (Shopware's usual getDecorated() pattern).
 *
 * Public API: stable within a major version.
 */
abstract class AbstractDeliveryPlanRoute
{
    abstract public function getDecorated(): AbstractDeliveryPlanRoute;

    abstract public function load(Request $request, SalesChannelContext $context): DeliveryPlanRouteResponse;

    /**
     * @param string $action one of the DeliveryPlanChangedEvent::ACTION_* values
     */
    abstract public function change(string $action, Request $request, SalesChannelContext $context): CartResponse;
}
