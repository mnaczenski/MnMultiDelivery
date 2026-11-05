<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Strategy\AvailabilityStrategy;
use Mn\MultiDelivery\Strategy\DeliverySplitStrategyInterface;
use Mn\MultiDelivery\Strategy\DeliveryTimeStrategy;
use Mn\MultiDelivery\Strategy\ManualStrategy;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Test\Generator;

#[CoversClass(StrategyRegistry::class)]
class StrategyRegistryTest extends TestCase
{
    public function testAnEmptySelectionOffersEveryStrategy(): void
    {
        static::assertSame(['manual', 'availability', 'deliveryTime'], $this->registry([])->availableNames(Generator::generateSalesChannelContext()));
    }

    public function testOnlyOfferedStrategiesAreListed(): void
    {
        $registry = $this->registry(['strategies' => ['manual', 'deliveryTime']]);

        static::assertSame(['manual', 'deliveryTime'], $registry->availableNames(Generator::generateSalesChannelContext()));
    }

    public function testTheSettingOnlyLimitsTheBuiltInStrategies(): void
    {
        $settings = Fixtures::settings(['strategies' => ['manual']]);
        $custom = $this->createMock(DeliverySplitStrategyInterface::class);
        $custom->method('getName')->willReturn('bulky');
        $custom->method('isAvailable')->willReturn(true);

        $registry = new StrategyRegistry([new ManualStrategy(), new AvailabilityStrategy(), $custom], $settings);

        static::assertSame(['manual', 'bulky'], $registry->availableNames(Generator::generateSalesChannelContext()));
    }

    public function testAStrategyThatIsNotOfferedFallsBackToTheDefault(): void
    {
        $registry = $this->registry(['strategies' => ['manual', 'deliveryTime'], 'defaultStrategy' => 'deliveryTime']);

        static::assertSame('deliveryTime', $registry->get('availability', Generator::generateSalesChannelContext())->getName());
    }

    public function testAnInvalidDefaultFallsBackToTheFirstOfferedStrategy(): void
    {
        $registry = $this->registry(['strategies' => ['availability'], 'defaultStrategy' => 'warehouse']);

        static::assertSame('availability', $registry->get('unknown', Generator::generateSalesChannelContext())->getName());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registry(array $config): StrategyRegistry
    {
        $settings = Fixtures::settings($config);

        return new StrategyRegistry([new ManualStrategy(), new AvailabilityStrategy(), new DeliveryTimeStrategy($settings)], $settings);
    }
}
