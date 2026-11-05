<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Policy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Policy\ExtraDeliveryCostPolicy;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Price\CashRounding;
use Shopware\Core\Checkout\Cart\Price\GrossPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\NetPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;

#[CoversClass(ExtraDeliveryCostPolicy::class)]
class ExtraDeliveryCostPolicyTest extends TestCase
{
    public function testEveryDeliveryIsChargedByDefault(): void
    {
        static::assertSame([9.9, 12.9], $this->costs([]));
    }

    public function testAdditionalDeliveriesCanBeFree(): void
    {
        static::assertSame([0.0, 12.9], $this->costs(['extraDeliveryCosts' => 'free']));
    }

    public function testAFlatPriceCapsAdditionalDeliveries(): void
    {
        $deliveries = $this->apply(['extraDeliveryCosts' => 'flat', 'extraDeliveryFlatPrice' => 4.9]);

        static::assertSame([4.9, 12.9], $this->totals($deliveries));
        static::assertEqualsWithDelta(0.78, $deliveries->first()?->getShippingCosts()->getCalculatedTaxes()->getAmount(), 0.001);
    }

    public function testAFlatPriceNeverRaisesTheCosts(): void
    {
        static::assertSame([9.9, 12.9], $this->costs(['extraDeliveryCosts' => 'flat', 'extraDeliveryFlatPrice' => 20.0]));
    }

    public function testNetCustomersPayTheFlatPriceWithoutTax(): void
    {
        $context = Generator::generateSalesChannelContext();
        $context->setTaxState(CartPrice::TAX_STATE_NET);
        $deliveries = $this->apply(['extraDeliveryCosts' => 'flat', 'extraDeliveryFlatPrice' => 5.95], $context);

        static::assertEqualsWithDelta(5.0, $deliveries->first()?->getShippingCosts()->getTotalPrice(), 0.001);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<float>
     */
    private function costs(array $config): array
    {
        return $this->totals($this->apply($config));
    }

    /**
     * @return list<float>
     */
    private function totals(DeliveryCollection $deliveries): array
    {
        return array_values(array_map(static fn ($delivery) => $delivery->getShippingCosts()->getTotalPrice(), $deliveries->getElements()));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function apply(array $config, ?SalesChannelContext $context = null): DeliveryCollection
    {
        $deliveries = new DeliveryCollection([
            Fixtures::delivery('delivery-2', ['lamp' => 1], 9.9),
            Fixtures::delivery('main', ['sofa' => 1], 12.9),
        ]);
        $taxCalculator = new TaxCalculator();
        $rounding = new CashRounding();
        (new ExtraDeliveryCostPolicy(
            Fixtures::settings($config),
            new QuantityPriceCalculator(new GrossPriceCalculator($taxCalculator, $rounding), new NetPriceCalculator($taxCalculator, $rounding)),
            $taxCalculator,
        ))->apply($deliveries, $context ?? Generator::generateSalesChannelContext());

        return $deliveries;
    }
}
