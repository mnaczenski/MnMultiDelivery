<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
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
}
