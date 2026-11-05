<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\DeliveryGroup;

#[CoversClass(Allocation::class)]
#[CoversClass(DeliveryGroup::class)]
class AllocationTest extends TestCase
{
    public function testGroupsAreKeyedByIdInOrder(): void
    {
        $allocation = new Allocation([new DeliveryGroup('b'), new DeliveryGroup('a')], ['sofa' => ['a' => 1]]);

        static::assertSame(['b', 'a'], array_keys($allocation->groups));
        static::assertSame('a', $allocation->getGroup('a')?->id);
        static::assertNull($allocation->getGroup('c'));
    }

    public function testQuantitiesMustUseKnownGroups(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown delivery group "c"');

        new Allocation([new DeliveryGroup('a')], ['sofa' => ['c' => 1]]);
    }

    public function testGroupIdsAreValidated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DeliveryGroup('no spaces');
    }

    public function testWithMethodsReturnNewAllocations(): void
    {
        $original = new Allocation([new DeliveryGroup('a')], ['sofa' => ['a' => 1]]);
        $changed = $original->withGroup(new DeliveryGroup('b'))->withQuantities('sofa', ['b' => 1]);

        static::assertSame(['sofa' => ['a' => 1]], $original->quantities);
        static::assertSame(['sofa' => ['b' => 1]], $changed->quantities);
        static::assertTrue((new DeliveryGroup('a', '', true, true))->withInStockDates(false)->locked);
    }
}
