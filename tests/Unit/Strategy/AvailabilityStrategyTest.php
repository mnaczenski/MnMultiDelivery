<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Strategy\AvailabilityStrategy;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Test\Generator;

#[CoversClass(AvailabilityStrategy::class)]
class AvailabilityStrategyTest extends TestCase
{
    public function testPartialStockShipsTheAvailablePartNow(): void
    {
        $allocation = (new AvailabilityStrategy())->allocate(
            ['chair' => Fixtures::root('chair', 4, 2), 'sofa' => Fixtures::root('sofa', 1)],
            new DeliveryPlan(true, 'availability'),
            Generator::generateSalesChannelContext(),
        );

        static::assertSame(['now' => 2, 'later' => 2], $allocation->quantities['chair']);
        static::assertSame(['now' => 1], $allocation->quantities['sofa']);
        static::assertTrue($allocation->groups['now']->inStockDates);
    }

    public function testItemsWithALongerDeliveryTimeShipLater(): void
    {
        $allocation = (new AvailabilityStrategy())->allocate(
            ['lamp' => Fixtures::root('lamp', 1), 'table' => Fixtures::root('table', 1, null, [2, 3, 'week'])],
            new DeliveryPlan(true, 'availability'),
            Generator::generateSalesChannelContext(),
        );

        static::assertSame(['now' => 1], $allocation->quantities['lamp']);
        static::assertSame(['later' => 1], $allocation->quantities['table']);
    }
}
