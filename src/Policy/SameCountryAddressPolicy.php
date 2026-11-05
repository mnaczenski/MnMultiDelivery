<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Deliveries stay in the shipping country: Shopware calculates taxes for the
 * context's country, not per delivery.
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
class SameCountryAddressPolicy implements DeliveryAddressPolicyInterface
{
    public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool
    {
        return $address->getCountryId() === $context->getShippingLocation()->getCountry()->getId();
    }
}
