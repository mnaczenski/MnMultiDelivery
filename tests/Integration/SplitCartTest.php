<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Integration;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Event\DeliveryAllocationEvent;
use Mn\MultiDelivery\Rule\DeliveryCountRule;
use Mn\MultiDelivery\Strategy\DeliveryGroup;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Uuid\Uuid;

class SplitCartTest extends IntegrationTestCase
{
    public function testAManualPlanSplitsARealCart(): void
    {
        $product = $this->product('p.stock > 20');
        $context = $this->context();

        $single = $this->calculate([$product => 3], null, $context);
        $split = $this->calculate([$product => 3], $this->plan($product, 2), $context);

        static::assertCount(1, $single->getDeliveries());
        static::assertSame([1, 2], $this->quantities($split));
        static::assertEqualsWithDelta(
            $split->getLineItems()->getPrices()->sum()->getTotalPrice(),
            $this->positionTotal($split),
            0.011,
            'the deliveries carry the full line item price',
        );
    }

    public function testPartialStockShipsTheAvailablePartNow(): void
    {
        $product = $this->product('p.stock BETWEEN 1 AND 3');
        $stock = (int) static::service(\Doctrine\DBAL\Connection::class)->fetchOne('SELECT stock FROM product WHERE id = UNHEX(?)', [$product]);

        $cart = $this->calculate([$product => $stock + 2], new DeliveryPlan(true, 'availability'), $this->context());

        static::assertSame([$stock, 2], $this->quantities($cart));
    }

    public function testRuleBuilderSeesTheSplit(): void
    {
        $product = $this->product('p.stock > 20');
        $context = $this->context();
        $rule = (new DeliveryCountRule())->assign(['count' => 1, 'operator' => Rule::OPERATOR_GT]);

        static::assertFalse($rule->match(new CartRuleScope($this->calculate([$product => 3], null, $context), $context)));
        static::assertTrue($rule->match(new CartRuleScope($this->calculate([$product => 3], $this->plan($product, 1), $context), $context)));
    }

    public function testAllocationListenersChangeRealCarts(): void
    {
        $product = $this->product('p.stock > 20');
        $dispatcher = static::dispatcher();
        $listener = static function (DeliveryAllocationEvent $event) use ($product): void {
            $event->setAllocation($event->getAllocation()
                ->withGroup(new DeliveryGroup('project-group', 'project'))
                ->withQuantities($product, ['main' => 1, 'project-group' => 2]));
        };
        $dispatcher->addListener(DeliveryAllocationEvent::class, $listener);
        try {
            $cart = $this->calculate([$product => 3], $this->plan($product, 0), $this->context());
        } finally {
            $dispatcher->removeListener(DeliveryAllocationEvent::class, $listener);
        }

        static::assertSame(['main', 'project-group'], $this->groupIds($cart));
    }

    public function testBlockedPaymentHandlersKeepOneDelivery(): void
    {
        $product = $this->product('p.stock > 20');
        $context = $this->context();
        $this->configure(['blockedPaymentHandlers' => $context->getPaymentMethod()->getHandlerIdentifier()]);

        static::assertCount(1, $this->calculate([$product => 3], $this->plan($product, 2), $context)->getDeliveries());
    }

    public function testShippingMethodsOfOtherSalesChannelsAreIgnored(): void
    {
        $product = $this->product('p.stock > 20');
        $context = $this->context();
        $repository = static::container()->get('shipping_method.repository');
        \assert($repository instanceof EntityRepository);
        $foreignId = Uuid::randomHex();
        $repository->create([[
            'id' => $foreignId,
            'name' => 'MD CI not in this sales channel',
            'technicalName' => 'md_ci_' . $foreignId,
            'active' => true,
            'deliveryTime' => ['name' => 'MD CI', 'min' => 1, 'max' => 2, 'unit' => 'day'],
        ]], Context::createDefaultContext());

        try {
            $plan = $this->plan($product, 2);
            $plan->setGroupSettings($plan->getManualGroups()[0], null, $foreignId);
            $split = $this->calculate([$product => 3], $plan, $context);

            static::assertCount(2, $split->getDeliveries());
            foreach ($split->getDeliveries() as $delivery) {
                static::assertNotSame($foreignId, $delivery->getShippingMethod()->getId());
            }
        } finally {
            $repository->delete([['id' => $foreignId]], Context::createDefaultContext());
        }
    }

    protected function plan(string $product, int $toSecond): DeliveryPlan
    {
        $plan = new DeliveryPlan(true, 'manual');
        $plan->setAssignments($product, [$plan->addManualGroup() => $toSecond]);

        return $plan;
    }

    /**
     * @return list<int>
     */
    protected function quantities(Cart $cart): array
    {
        return array_values(array_map(static fn ($delivery) => (int) array_sum(array_map(static fn ($p) => $p->getQuantity(), $delivery->getPositions()->getElements())), $cart->getDeliveries()->getElements()));
    }

    /**
     * @return list<string>
     */
    protected function groupIds(Cart $cart): array
    {
        return array_values(array_map(static fn ($delivery) => $delivery->getExtensionOfType('mnDeliveryGroup', \Mn\MultiDelivery\Cart\DeliveryGroupInfo::class)?->getGroupId() ?? '-', $cart->getDeliveries()->getElements()));
    }

    private function positionTotal(Cart $cart): float
    {
        $total = 0.0;
        foreach ($cart->getDeliveries() as $delivery) {
            foreach ($delivery->getPositions() as $position) {
                $total += $position->getPrice()->getTotalPrice();
            }
        }

        return $total;
    }
}
