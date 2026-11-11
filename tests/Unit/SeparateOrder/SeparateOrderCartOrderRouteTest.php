<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\SeparateOrder;

use Mn\MultiDelivery\Event\SeparateOrdersPlacedEvent;
use Mn\MultiDelivery\Exception\MultiDeliveryException;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderCalculator;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderCartOrderRoute;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderPart;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderSplit;
use Mn\MultiDelivery\Service\SplitSettings;
use Mn\MultiDelivery\Tests\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartContextHasher;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRouteResponse;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\DefaultPayment;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SeparateOrderCartOrderRoute::class)]
class SeparateOrderCartOrderRouteTest extends TestCase
{
    /** @var list<array{string, string, ?string}> cart token, context token, hash */
    private array $placed = [];

    private ?string $failOnToken = null;

    /** @var list<string> */
    private array $cancelled = [];

    /** @var StaticEntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> */
    private StaticEntityRepository $orders;

    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->context = Generator::generateSalesChannelContext();
        $this->orders = new StaticEntityRepository([]);
    }

    public function testCartsWithoutDeliveriesAbroadGoToShopwareUnchanged(): void
    {
        $cart = new Cart('main-token');

        $response = $this->route(null)->order($cart, $this->context, new RequestDataBag(['hash' => 'abc']));

        static::assertSame([['main-token', $this->context->getToken(), 'abc']], $this->placed);
        static::assertSame('order-main-token', $response->getOrder()->getId());
        static::assertSame([], $this->orders->updates);
    }

    public function testSeparateOrdersArePlacedFirstAndLinked(): void
    {
        $events = [];
        $split = $this->split(['at-token', 'ch-token'], true);

        $response = $this->route($split, $events)->order(new Cart('main-token'), $this->context, new RequestDataBag(['hash' => 'abc']));

        static::assertSame(['at-token', 'ch-token', 'main-token'], array_column($this->placed, 0));
        static::assertSame([null, null, null], array_column($this->placed, 2), 'the hash is checked once for the whole cart');
        static::assertSame('order-main-token', $response->getOrder()->getId());

        $update = $this->orders->updates[0] ?? [];
        static::assertCount(3, $update);
        $link = $update[0]['customFields'][SeparateOrderCartOrderRoute::CUSTOM_FIELD];
        static::assertSame(['order-at-token', 'order-ch-token', 'order-main-token'], array_column($link['orders'], 'id'));
        static::assertCount(1, $events);
        static::assertSame($link['group'], $events[0]->getOrderGroupId());
    }

    public function testEverythingAbroadReturnsTheLastSeparateOrder(): void
    {
        $response = $this->route($this->split(['at-token'], false))->order(new Cart('main-token'), $this->context, new RequestDataBag());

        static::assertSame(['at-token'], array_column($this->placed, 0));
        static::assertSame('order-at-token', $response->getOrder()->getId());
    }

    public function testPlacedOrdersAreCancelledWhenALaterOneFails(): void
    {
        $this->failOnToken = 'main-token';

        try {
            $this->route($this->split(['at-token', 'ch-token'], true))->order(new Cart('main-token'), $this->context, new RequestDataBag());
            static::fail('the failure must reach the caller');
        } catch (\RuntimeException $e) {
            static::assertSame('placing failed', $e->getMessage());
        }

        static::assertSame(['order-at-token', 'order-ch-token'], $this->cancelled);
        static::assertSame([], $this->orders->updates);
    }

    public function testNothingIsPlacedWithAPaymentMethodThatIsNotAllowed(): void
    {
        $this->expectException(MultiDeliveryException::class);

        try {
            $this->route($this->split(['at-token'], true), settings: ['countryMode' => SplitSettings::COUNTRY_SEPARATE_ORDERS])
                ->order(new Cart('main-token'), $this->context, new RequestDataBag());
        } finally {
            static::assertSame([], $this->placed);
        }
    }

    /**
     * @param list<string> $partTokens
     */
    private function split(array $partTokens, bool $withMain): SeparateOrderSplit
    {
        $main = new Cart('main-token');
        if ($withMain) {
            $main->add(new LineItem('chair', LineItem::PRODUCT_LINE_ITEM_TYPE, 'chair', 1));
        }
        $parts = [];
        foreach ($partTokens as $token) {
            $address = new CustomerAddressEntity();
            $address->setId('address-' . $token);
            $parts[] = new SeparateOrderPart('group-' . $token, $token, $address, null, new Cart($token));
        }

        return new SeparateOrderSplit($main, $parts);
    }

    /**
     * @param list<SeparateOrdersPlacedEvent> $events
     * @param array<string, mixed>|null       $settings
     */
    private function route(?SeparateOrderSplit $split, array &$events = [], ?array $settings = null): SeparateOrderCartOrderRoute
    {
        $decorated = $this->createMock(AbstractCartOrderRoute::class);
        $decorated->method('order')->willReturnCallback(function (Cart $cart, SalesChannelContext $context, RequestDataBag $data): CartOrderRouteResponse {
            if ($cart->getToken() === $this->failOnToken) {
                throw new \RuntimeException('placing failed');
            }
            $this->placed[] = [$cart->getToken(), $context->getToken(), $data->get('hash')];
            $order = new OrderEntity();
            $order->setId('order-' . $cart->getToken());
            $order->setOrderNumber($cart->getToken());
            $order->setAmountTotal(10.0);

            return new CartOrderRouteResponse($order);
        });

        $calculator = $this->createMock(SeparateOrderCalculator::class);
        $contexts = [];
        foreach ($split->parts ?? [] as $part) {
            $contexts[$part->groupId] = Generator::generateSalesChannelContext(token: 'context-' . $part->groupId);
        }
        $calculator->method('calculate')->willReturn($split === null ? null : ['split' => $split, 'contexts' => $contexts]);

        $hasher = $this->createMock(CartContextHasher::class);
        $hasher->method('isMatching')->willReturn(true);

        $stateMachine = $this->createMock(StateMachineRegistry::class);
        $stateMachine->method('transition')->willReturnCallback(function (Transition $transition) {
            $this->cancelled[] = $transition->getEntityId();

            return new \Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateCollection();
        });

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SeparateOrdersPlacedEvent::class, static function (SeparateOrdersPlacedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        return new SeparateOrderCartOrderRoute(
            $decorated,
            $calculator,
            Fixtures::settings($settings ?? [
                'countryMode' => SplitSettings::COUNTRY_SEPARATE_ORDERS,
                'separateOrderPaymentHandlers' => DefaultPayment::class,
            ]),
            $hasher,
            $this->createMock(AbstractCartPersister::class),
            $this->orders,
            $stateMachine,
            $dispatcher,
            new NullLogger(),
        );
    }
}
