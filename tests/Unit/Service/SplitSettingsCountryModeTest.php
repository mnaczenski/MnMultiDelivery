<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Service;

use Mn\MultiDelivery\Policy\SameCountryAddressPolicy;
use Mn\MultiDelivery\Service\SplitSettings;
use Mn\MultiDelivery\Tests\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\DefaultPayment;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;

#[CoversClass(SplitSettings::class)]
#[CoversClass(SameCountryAddressPolicy::class)]
class SplitSettingsCountryModeTest extends TestCase
{
    public function testSameCountryIsTheDefault(): void
    {
        $settings = Fixtures::settings();
        $context = $this->context(DefaultPayment::class);

        static::assertSame(SplitSettings::COUNTRY_SAME, $settings->countryMode($context));
        static::assertSame(SplitSettings::COUNTRY_SAME, Fixtures::settings(['countryMode' => 'unknown'])->countryMode($context));
        static::assertFalse($settings->separateOrdersAllowed($context));
    }

    public function testSeparateOrdersNeedAnAllowedPaymentHandler(): void
    {
        $settings = Fixtures::settings([
            'countryMode' => SplitSettings::COUNTRY_SEPARATE_ORDERS,
            'separateOrderPaymentHandlers' => "Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment\nDefaultPayment",
        ]);

        static::assertTrue($settings->separateOrdersAllowed($this->context(DefaultPayment::class)));
        static::assertFalse($settings->separateOrdersAllowed($this->context('Swag\\PayPal\\Checkout\\Payment\\PayPalPaymentHandler')));
        static::assertFalse(Fixtures::settings(['countryMode' => SplitSettings::COUNTRY_SEPARATE_ORDERS])->separateOrdersAllowed($this->context(DefaultPayment::class)), 'no allowed handlers configured');
    }

    public function testEveryDeliveryModeEnablesSeparateOrders(): void
    {
        $settings = Fixtures::settings([
            'countryMode' => SplitSettings::COUNTRY_ALL_SEPARATE,
            'separateOrderPaymentHandlers' => 'DefaultPayment',
        ]);

        static::assertSame(SplitSettings::COUNTRY_ALL_SEPARATE, $settings->countryMode($this->context(DefaultPayment::class)));
        static::assertTrue($settings->separateOrdersEnabled($this->context(DefaultPayment::class)));
        static::assertTrue($settings->separateOrdersAllowed($this->context(DefaultPayment::class)));
        static::assertFalse($settings->separateOrdersAllowed($this->context('Swag\\PayPal\\Handler')));
        static::assertFalse(Fixtures::settings()->separateOrdersEnabled($this->context(DefaultPayment::class)));
    }

    public function testTheAddressPolicyFollowsTheCountryMode(): void
    {
        $context = $this->context(DefaultPayment::class);
        $abroad = new CustomerAddressEntity();
        $abroad->setCountryId('ffffffffffffffffffffffffffffffff');
        $home = new CustomerAddressEntity();
        $home->setCountryId($context->getShippingLocation()->getCountry()->getId());

        $same = new SameCountryAddressPolicy(Fixtures::settings());
        $separate = new SameCountryAddressPolicy(Fixtures::settings([
            'countryMode' => SplitSettings::COUNTRY_SEPARATE_ORDERS,
            'separateOrderPaymentHandlers' => 'DefaultPayment',
        ]));

        static::assertTrue($same->isAllowed($home, $context));
        static::assertFalse($same->isAllowed($abroad, $context));
        static::assertTrue($separate->isAllowed($abroad, $context));
        static::assertFalse($separate->isAllowed($abroad, $this->context('Swag\\PayPal\\Handler')));
    }

    private function context(string $handler): SalesChannelContext
    {
        $payment = new PaymentMethodEntity();
        $payment->setId('0190bbbbbbbbbbbbbbbbbbbbbbbbbb01');
        $payment->setHandlerIdentifier($handler);

        return Generator::generateSalesChannelContext(paymentMethod: $payment);
    }
}
