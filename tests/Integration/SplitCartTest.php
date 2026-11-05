<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Integration;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Event\DeliveryAllocationEvent;
use Mn\MultiDelivery\Rule\DeliveryCountRule;
use Mn\MultiDelivery\Strategy\DeliveryGroup;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Rule\Rule;

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
        $stock = (int) static::container()->get(\Doctrine\DBAL\Connection::class)->fetchOne('SELECT stock FROM product WHERE id = UNHEX(?)', [$product]);

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
        $dispatcher = static::container()->get('event_dispatcher');
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
        return array_values(array_map(static fn ($delivery) => $delivery->getExtension('mnDeliveryGroup')?->getGroupId() ?? '-', $cart->getDeliveries()->getElements()));
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
