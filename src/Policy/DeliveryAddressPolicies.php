<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * All registered address policies; an address must be allowed by each.
 *
 * @internal
 */
class DeliveryAddressPolicies
{
    /**
     * @param iterable<DeliveryAddressPolicyInterface> $policies
     */
    public function __construct(private readonly iterable $policies)
    {
    }

    public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool
    {
        foreach ($this->policies as $policy) {
            if (!$policy->isAllowed($address, $context)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an address in this country could be allowed: for offering and
     * checking countries before an address exists (the planner's address form).
     */
    public function isCountryAllowed(string $countryId, SalesChannelContext $context, ?CountryEntity $country = null): bool
    {
        $address = new CustomerAddressEntity();
        $address->setId('');
        $address->setCountryId($countryId);
        if ($country !== null) {
            $address->setCountry($country);
        }

        return $this->isAllowed($address, $context);
    }
}
