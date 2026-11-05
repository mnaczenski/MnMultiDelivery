<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Policy\SameCountryAddressPolicy;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Test\Generator;

#[CoversClass(SameCountryAddressPolicy::class)]
#[CoversClass(DeliveryAddressPolicies::class)]
class SameCountryAddressPolicyTest extends TestCase
{
    public function testOnlyAddressesInTheShippingCountryAreAllowed(): void
    {
        $context = Generator::generateSalesChannelContext();
        $home = new CustomerAddressEntity();
        $home->setCountryId($context->getShippingLocation()->getCountry()->getId());
        $abroad = new CustomerAddressEntity();
        $abroad->setCountryId('ffffffffffffffffffffffffffffffff');

        $policies = new DeliveryAddressPolicies([new SameCountryAddressPolicy()]);

        static::assertTrue($policies->isAllowed($home, $context));
        static::assertFalse($policies->isAllowed($abroad, $context));
        static::assertTrue((new DeliveryAddressPolicies([]))->isAllowed($abroad, $context), 'without policies every address is allowed');
    }
}
