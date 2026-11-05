<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Mn\MultiDelivery\Service\SplitSettings;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Keeps deliveries in the shipping country: Shopware calculates taxes for one
 * country per order. Addresses in other countries are allowed only when the
 * merchant lets them become separate orders (setting countryMode) and the
 * payment method allows that.
 *
 * Built-in implementation: decorate it to change the rule; the class itself may change.
 */
class SameCountryAddressPolicy implements DeliveryAddressPolicyInterface
{
    public function __construct(private readonly ?SplitSettings $settings = null)
    {
    }

    public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool
    {
        if ($address->getCountryId() === $context->getShippingLocation()->getCountry()->getId()) {
            return true;
        }

        return $this->settings?->separateOrdersAllowed($context) ?? false;
    }
}
