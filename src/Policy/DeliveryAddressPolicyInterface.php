<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Decides which customer addresses a delivery may go to. Tag implementations
 * with "mn.multi_delivery.address_policy"; an address must be allowed
 * by every policy. The default SameCountryAddressPolicy keeps deliveries in
 * the shipping country, because Shopware taxes one country per order.
 *
 * Public API: stable within a major version.
 */
interface DeliveryAddressPolicyInterface
{
    public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool;
}
