<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Strategy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Strategy\ManualStrategy;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Test\Generator;

#[CoversClass(ManualStrategy::class)]
class ManualStrategyTest extends TestCase
{
    public function testUnassignedQuantitiesStayInTheFirstDelivery(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $plan->setAssignments('chair', [$second => 2]);

        $allocation = (new ManualStrategy())->allocate(
            ['chair' => Fixtures::root('chair', 5), 'sofa' => Fixtures::root('sofa', 1)],
            $plan,
            Generator::generateSalesChannelContext(),
        );

        static::assertSame([$second => 2, 'main' => 3], $allocation->quantities['chair']);
        static::assertSame(['main' => 1], $allocation->quantities['sofa']);
    }

    public function testAssignmentsAreCappedAtTheCartQuantity(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $plan->setAssignments('chair', [$second => 9]);

        $allocation = (new ManualStrategy())->allocate(['chair' => Fixtures::root('chair', 2)], $plan, Generator::generateSalesChannelContext());

        static::assertSame([$second => 2], $allocation->quantities['chair']);
    }

    public function testAssignmentsToRemovedGroupsAreIgnored(): void
    {
        $plan = new DeliveryPlan(true, 'manual', [], ['chair' => ['delivery-7' => 1]]);

        $allocation = (new ManualStrategy())->allocate(['chair' => Fixtures::root('chair', 2)], $plan, Generator::generateSalesChannelContext());

        static::assertSame(['main' => 2], $allocation->quantities['chair']);
    }
}
