<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;

#[CoversClass(DeliveryPlan::class)]
class DeliveryPlanTest extends TestCase
{
    public function testManualGroupsGetUniqueIds(): void
    {
        $plan = new DeliveryPlan(true, 'manual');

        static::assertSame('delivery-2', $plan->addManualGroup());
        static::assertSame('delivery-3', $plan->addManualGroup());
        $plan->removeManualGroup('delivery-2');
        static::assertSame('delivery-4', $plan->addManualGroup());
        static::assertSame(['delivery-3', 'delivery-4'], $plan->getManualGroups());
    }

    public function testRemovingAGroupDropsItsSettingsAndAssignments(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $group = $plan->addManualGroup();
        $plan->setGroupSettings($group, str_repeat('a', 32), null);
        $plan->setAssignments('sofa', [$group => 1]);

        $plan->removeManualGroup($group);

        static::assertSame([], $plan->getGroupSettings($group));
        static::assertSame(['sofa' => []], $plan->getAssignments());
    }

    public function testAssignmentsAndAdjustmentsDropEmptyGroups(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $plan->setAssignments('sofa', ['delivery-2' => 0, 'delivery-3' => 2]);
        $plan->setAdjustment('availability', 'lamp', ['now' => 0, 'later' => 3]);

        static::assertSame(['sofa' => ['delivery-3' => 2]], $plan->getAssignments());
        static::assertSame(['lamp' => ['later' => 3]], $plan->getAdjustments('availability'));
        static::assertSame([], $plan->getAdjustments('deliveryTime'));
    }

    public function testResetOnlyTouchesOneStrategy(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $plan->setAdjustment('availability', 'lamp', ['later' => 1]);
        $plan->setAdjustment('deliveryTime', 'lamp', ['time-2' => 1]);

        $plan->resetAdjustments('availability');

        static::assertSame([], $plan->getAdjustments('availability'));
        static::assertSame(['lamp' => ['time-2' => 1]], $plan->getAdjustments('deliveryTime'));
    }
}
