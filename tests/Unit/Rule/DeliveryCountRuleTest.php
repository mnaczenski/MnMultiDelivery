<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Rule\DeliveryCountRule;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Flow\Rule\FlowRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Test\Generator;

#[CoversClass(DeliveryCountRule::class)]
class DeliveryCountRuleTest extends TestCase
{
    public function testMatchesSplitCarts(): void
    {
        $rule = (new DeliveryCountRule())->assign(['count' => 1, 'operator' => Rule::OPERATOR_GT]);
        $context = Generator::generateSalesChannelContext();

        static::assertFalse($rule->match(new CartRuleScope($this->cart(1), $context)));
        static::assertTrue($rule->match(new CartRuleScope($this->cart(2), $context)));
    }

    public function testCountsOrderDeliveriesInFlows(): void
    {
        $rule = (new DeliveryCountRule())->assign(['count' => 3, 'operator' => Rule::OPERATOR_EQ]);
        $order = new OrderEntity();
        $deliveries = new OrderDeliveryCollection();
        foreach (['a', 'b', 'c'] as $id) {
            $delivery = new OrderDeliveryEntity();
            $delivery->setId(str_repeat($id, 32));
            $deliveries->add($delivery);
        }
        $order->setDeliveries($deliveries);

        static::assertTrue($rule->match(new FlowRuleScope($order, new Cart('token'), Generator::generateSalesChannelContext())));
    }

    public function testOtherScopesNeverMatch(): void
    {
        $rule = (new DeliveryCountRule())->assign(['count' => 0, 'operator' => Rule::OPERATOR_GTE]);

        static::assertFalse($rule->match(new CheckoutRuleScope(Generator::generateSalesChannelContext())));
    }

    private function cart(int $deliveries): Cart
    {
        $cart = new Cart('token');
        $collection = new DeliveryCollection();
        for ($i = 1; $i <= $deliveries; ++$i) {
            $collection->add(Fixtures::delivery('group-' . $i, ['item-' . $i => 1]));
        }
        $cart->setDeliveries($collection);

        return $cart;
    }
}
