<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Strategy;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Strategy\WarehouseStrategy;

/**
 * The warehouse strategy must predict exactly what SwagCommercial's
 * Multi-Warehouse will allocate when the order is placed. Compares both
 * allocations; skipped when SwagCommercial is not installed.
 */
#[CoversClass(WarehouseStrategy::class)]
class WarehouseStrategyParityTest extends TestCase
{
    private const COMMERCIAL = 'Shopware\\Commercial\\MultiWarehouse\\Domain\\Order\\MultiWarehouseStockUpdater';

    /**
     * @param array<string, int> $stocks warehouse => stock, in priority order
     */
    #[DataProvider('scenarios')]
    public function testMatchesCommercialAllocation(array $stocks, int $quantity): void
    {
        if (!class_exists(self::COMMERCIAL)) {
            static::markTestSkipped('SwagCommercial is not installed.');
        }

        $ours = (new \ReflectionMethod(WarehouseStrategy::class, 'allocateProduct'))
            ->invoke(new WarehouseStrategy($this->createMock(Connection::class)), $quantity, $stocks);

        $commercial = (new \ReflectionClass(self::COMMERCIAL))->newInstanceWithoutConstructor(); // @phpstan-ignore argument.type (Commercial is optional)
        $rows = (new \ReflectionMethod(self::COMMERCIAL, 'calculateOrderStocks'))
            ->invoke($commercial, ['p' => ['quantity' => $quantity]], ['p' => $stocks]);
        $theirs = [];
        foreach ($rows as $row) {
            if ($row['quantity'] > 0) {
                $theirs[$row['warehouseId']] = ($theirs[$row['warehouseId']] ?? 0) + $row['quantity'];
            }
        }

        ksort($ours);
        ksort($theirs);
        static::assertSame($theirs, $ours);
    }

    /**
     * @return iterable<string, array{array<string, int>, int}>
     */
    public static function scenarios(): iterable
    {
        yield 'first warehouse has enough' => [['A' => 5, 'B' => 3], 4];
        yield 'spills into the second' => [['A' => 5, 'B' => 3], 7];
        yield 'not enough anywhere' => [['A' => 5, 'B' => 3], 10];
        yield 'first is empty' => [['A' => 0, 'B' => 3], 2];
        yield 'all empty' => [['A' => 0, 'B' => 0], 3];
        yield 'skips an empty middle warehouse' => [['A' => 2, 'B' => 0, 'C' => 4], 5];
        yield 'remainder goes to the last with stock' => [['A' => 2, 'B' => 0, 'C' => 4], 9];
        yield 'single warehouse' => [['A' => 1], 1];
    }
}
