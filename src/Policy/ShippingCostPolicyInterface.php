<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Adjusts the shipping costs of split deliveries after Shopware priced each
 * of them by its shipping method. Decorate the service
 * Mn\MultiDelivery\Policy\ShippingCostPolicyInterface to change the
 * pricing; the default is ExtraDeliveryCostPolicy (plugin settings).
 *
 * Public API: stable within a major version.
 */
interface ShippingCostPolicyInterface
{
    public function apply(DeliveryCollection $deliveries, SalesChannelContext $context): void;
}
