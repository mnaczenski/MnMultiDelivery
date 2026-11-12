<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Cart\SplitDeliveryProcessor;
use Mn\MultiDelivery\Event\DeliveryPlanChangedEvent;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Strategy\AvailabilityStrategy;
use Mn\MultiDelivery\Strategy\ManualStrategy;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(DeliveryPlanService::class)]
class DeliveryPlanServiceTest extends TestCase
{
    private EventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
    }

    public function testEveryChangeDispatchesAnEvent(): void
    {
        $events = [];
        $this->dispatcher->addListener(DeliveryPlanChangedEvent::class, static function (DeliveryPlanChangedEvent $event) use (&$events): void {
            $events[] = [$event->getAction(), $event->getPlan()->getStrategy()];
        });
        $plan = new DeliveryPlan(true, 'manual');
        $service = $this->service($this->cart($plan, ['main' => ['chair' => 1]]));
        $context = Generator::generateSalesChannelContext();

        $service->setStrategy($context, 'availability');
        $service->toggle($context, false);

        static::assertSame([['strategy', 'availability'], ['toggle', 'availability']], $events);
    }

    public function testMovingFromTheFirstDeliveryToAnAddedOne(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $cart = $this->cart($plan, ['main' => ['chair' => 3]]);

        $this->service($cart)->move(Generator::generateSalesChannelContext(), 'chair', 'main', $second, 2);

        static::assertSame(['chair' => [$second => 2]], $plan->getAssignments());
    }

    public function testMovingBetweenTwoAddedDeliveries(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $third = $plan->addManualGroup();
        $plan->setAssignments('chair', [$second => 2]);
        $cart = $this->cart($plan, ['main' => ['sofa' => 1], $second => ['chair' => 2]]);

        $this->service($cart)->move(Generator::generateSalesChannelContext(), 'chair', $second, $third, 2);

        static::assertSame(['chair' => [$third => 2]], $plan->getAssignments());
    }

    public function testMovingBackToTheFirstDeliveryClearsTheAssignment(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $plan->setAssignments('chair', [$second => 2]);
        $cart = $this->cart($plan, ['main' => ['chair' => 1], $second => ['chair' => 2]]);

        $this->service($cart)->move(Generator::generateSalesChannelContext(), 'chair', $second, 'main', 0);

        static::assertSame(['chair' => []], $plan->getAssignments());
    }

    public function testMovingWithinAnAutomaticSplitStoresAnAdjustment(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $cart = $this->cart($plan, ['now' => ['chair' => 2], 'later' => ['chair' => 3]]);

        $this->service($cart)->move(Generator::generateSalesChannelContext(), 'chair', 'later', 'now', 1);

        static::assertSame(['chair' => ['now' => 3, 'later' => 2]], $plan->getAdjustments('availability'));
        static::assertSame([], $plan->getAssignments());
    }

    public function testMovesToUnknownDeliveriesAreIgnored(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $cart = $this->cart($plan, ['now' => ['chair' => 2], 'later' => ['chair' => 3]]);

        $this->service($cart)->move(Generator::generateSalesChannelContext(), 'chair', 'later', 'delivery-9', 1);

        static::assertSame([], $plan->getAdjustments('availability'));
    }

    public function testSettingsOnlyForDeliveriesThePlannerShows(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $service = $this->service($this->cart($plan, ['now' => ['chair' => 2], 'later' => ['chair' => 3]]));
        $context = Generator::generateSalesChannelContext();
        $address = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

        $service->setGroup($context, 'later', $address, null);
        $service->setGroup($context, 'made-up', $address, null);

        static::assertSame(['later'], $plan->getConfiguredGroupIds());
    }

    public function testANewAddressKeepsTheShippingMethodOfTheDelivery(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $plan->setGroupSettings('later', null, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $service = $this->service($this->cart($plan, ['now' => ['chair' => 2], 'later' => ['chair' => 3]]));

        $service->setGroupAddress(Generator::generateSalesChannelContext(), 'later', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');

        static::assertSame(['addressId' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'shippingMethodId' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], $plan->getGroupSettings('later'));
    }

    public function testDescribeShowsWhatShippingTogetherSaves(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $cart = $this->cart($plan, ['main' => ['sofa' => 1]]);
        $cart->setDeliveries(new DeliveryCollection([
            Fixtures::delivery('main', ['sofa' => 1], 4.9),
            Fixtures::delivery($plan->addManualGroup(), ['chair' => 1], 4.9),
        ]));
        $cart->addArrayExtension(SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION, ['shippingCosts' => 5.9]);
        $service = $this->service($cart);
        $context = Generator::generateSalesChannelContext();

        static::assertSame(3.9, $service->describe($cart, $context)['extraShippingCosts']);

        $cart->removeExtension(SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION);
        static::assertNull($service->describe($cart, $context)['extraShippingCosts'], 'not split');
    }

    public function testTheNumberOfConfiguredDeliveriesIsLimited(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        for ($i = 0; $i < DeliveryPlanService::MAX_CONFIGURED_GROUPS; ++$i) {
            $plan->setGroupSettings('old-' . $i, null, null);
        }
        $service = $this->service($this->cart($plan, ['now' => ['chair' => 2]]));

        $service->setGroup(Generator::generateSalesChannelContext(), 'now', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', null);

        static::assertNotContains('now', $plan->getConfiguredGroupIds());
        static::assertCount(DeliveryPlanService::MAX_CONFIGURED_GROUPS, $plan->getConfiguredGroupIds());
    }

    public function testResetDropsTheAdjustments(): void
    {
        $plan = new DeliveryPlan(true, 'availability');
        $plan->setAdjustment('availability', 'chair', ['now' => 5]);
        $cart = $this->cart($plan, ['now' => ['chair' => 5]]);

        $this->service($cart)->reset(Generator::generateSalesChannelContext());

        static::assertSame([], $plan->getAdjustments('availability'));
    }

    public function testDescribeNumbersDeliveriesInDisplayOrder(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $third = $plan->addManualGroup();
        $cart = $this->cart($plan, ['main' => ['sofa' => 1], $third => ['chair' => 1]]);

        $view = $this->service($cart)->describe($cart, Generator::generateSalesChannelContext());

        // The empty delivery is shown last.
        static::assertSame(['main' => 1, $third => 2, $second => 3], $view['groupNumbers']);
        static::assertSame(['main', $second, $third], $view['moveTargets']);
    }

    /**
     * @param array<string, array<string, int>> $deliveries group id => [line item id => quantity]
     */
    private function cart(DeliveryPlan $plan, array $deliveries): Cart
    {
        $cart = new Cart('token');
        $quantities = [];
        foreach ($deliveries as $groupId => $items) {
            foreach ($items as $id => $quantity) {
                $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
            }
        }
        foreach ($quantities as $id => $quantity) {
            $cart->add(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity));
        }
        $cart->setDeliveries(new DeliveryCollection(array_map(
            static fn (string $groupId, array $items) => Fixtures::delivery($groupId, $items),
            array_keys($deliveries),
            $deliveries,
        )));
        $cart->addExtension(DeliveryPlan::EXTENSION, $plan);

        return $cart;
    }

    private function service(Cart $cart): DeliveryPlanService
    {
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($cart);
        $cartService->method('recalculate')->willReturnArgument(0);
        $settings = Fixtures::settings();

        return new DeliveryPlanService(
            $cartService,
            new SplitGuard(new StaticSystemConfigService(['MnMultiDelivery.config.enabled' => true])),
            new StrategyRegistry([new ManualStrategy(), new AvailabilityStrategy()], $settings),
            $settings,
            $this->dispatcher,
        );
    }
}
