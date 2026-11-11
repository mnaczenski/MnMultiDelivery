<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Integration;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Event\SplitOrderConvertedEvent;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;

/**
 * Converts a split cart into the order payload Shopware would write,
 * without writing it.
 */
class OrderConversionTest extends IntegrationTestCase
{
    public function testEveryDeliveryBecomesAnOrderDeliveryWithItsGroup(): void
    {
        $customerId = getenv('MN_MULTI_DELIVERY_CUSTOMER_ID');
        if (!\is_string($customerId) || $customerId === '') {
            static::markTestSkipped('Set MN_MULTI_DELIVERY_CUSTOMER_ID to run the order conversion test.');
        }

        $product = $this->product('p.stock > 20');
        $context = $this->context($customerId);
        $plan = new DeliveryPlan(true, 'manual');
        $plan->setAssignments($product, [$plan->addManualGroup() => 2]);
        $cart = $this->calculate([$product => 3], $plan, $context);

        $events = [];
        $dispatcher = static::dispatcher();
        $listener = static function (SplitOrderConvertedEvent $event) use (&$events): void {
            $events[] = $event;
        };
        $dispatcher->addListener(SplitOrderConvertedEvent::class, $listener);
        try {
            // the route may be decorated (by this plugin's separate orders, among others)
            $route = static::container()->get(CartOrderRoute::class);
            while ($route instanceof AbstractCartOrderRoute && !$route instanceof CartOrderRoute) {
                $route = $route->getDecorated();
            }
            \assert($route instanceof CartOrderRoute);
            /** @var OrderConverter $converter */
            $converter = $this->property($this->property($route, 'orderPersister'), 'converter');
            $order = $converter->convertToOrder($cart, $context, new OrderConversionContext());
        } finally {
            $dispatcher->removeListener(SplitOrderConvertedEvent::class, $listener);
        }

        static::assertCount(2, $order['deliveries']);
        $groups = array_map(static fn (array $delivery) => $delivery['customFields']['mn_multi_delivery']['group'] ?? null, $order['deliveries']);
        sort($groups);
        static::assertSame(['delivery-2', 'main'], $groups);
        static::assertCount(1, $events, 'SplitOrderConvertedEvent is dispatched once');
        static::assertEqualsWithDelta($cart->getPrice()->getTotalPrice(), $order['price']->getTotalPrice(), 0.001);
    }
}
