<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\DeliveryTimeStrategy;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Test\Generator;

#[CoversClass(DeliveryTimeStrategy::class)]
class DeliveryTimeStrategyTest extends TestCase
{
    public function testOneDeliveryPerDeliveryTimeBand(): void
    {
        $allocation = $this->allocate(['deliveryTimeGroupDays' => 2, 'deliveryTimeMaxDeliveries' => 3]);

        static::assertCount(3, $allocation->groups);
        static::assertSame(['time-1' => 1], $allocation->quantities['sofa']);
        static::assertSame(['time-1' => 2], $allocation->quantities['lamp']);
        static::assertSame(['time-2' => 1], $allocation->quantities['table']);
        static::assertSame(['time-3' => 1], $allocation->quantities['shelf']);
    }

    public function testTheLatestBandsMergeAtTheLimit(): void
    {
        $allocation = $this->allocate(['deliveryTimeGroupDays' => 2, 'deliveryTimeMaxDeliveries' => 2]);

        static::assertCount(2, $allocation->groups);
        static::assertSame(['time-2' => 1], $allocation->quantities['table']);
        static::assertSame(['time-2' => 1], $allocation->quantities['shelf']);
    }

    public function testAWideWindowKeepsEverythingTogether(): void
    {
        static::assertCount(1, $this->allocate(['deliveryTimeGroupDays' => 60])->groups);
    }

    public function testPartialStockShipsTheAvailablePartWithItsBand(): void
    {
        $strategy = new DeliveryTimeStrategy(Fixtures::settings(['deliveryTimeGroupDays' => 2]));
        $allocation = $strategy->allocate(
            ['chair' => Fixtures::root('chair', 5, 2, [1, 3, 'day'], 21), 'sofa' => Fixtures::root('sofa', 1)],
            new DeliveryPlan(true, 'deliveryTime'),
            Generator::generateSalesChannelContext(),
        );

        static::assertSame(['time-1' => 2, 'time-2' => 3], $allocation->quantities['chair']);
        static::assertSame(['time-1' => 1], $allocation->quantities['sofa']);
        static::assertTrue($allocation->groups['time-1']->inStockDates);
        static::assertFalse($allocation->groups['time-2']->inStockDates);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function allocate(array $config): Allocation
    {
        $roots = [
            'sofa' => Fixtures::root('sofa', 1),
            'lamp' => Fixtures::root('lamp', 2),
            'table' => Fixtures::root('table', 1, null, [2, 3, 'week']),
            'shelf' => Fixtures::root('shelf', 1, null, [6, 8, 'week']),
        ];

        return (new DeliveryTimeStrategy(Fixtures::settings($config)))
            ->allocate($roots, new DeliveryPlan(true, 'deliveryTime'), Generator::generateSalesChannelContext());
    }
}
