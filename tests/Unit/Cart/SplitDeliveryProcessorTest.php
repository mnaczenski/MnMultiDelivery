<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Cart;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Cart\SplitDeliveryProcessor;
use Mn\MultiDelivery\Event\DeliveryAllocationEvent;
use Mn\MultiDelivery\Event\SplitDeliveriesBuiltEvent;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicyInterface;
use Mn\MultiDelivery\Policy\ShippingCostPolicyInterface;
use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\AvailabilityStrategy;
use Mn\MultiDelivery\Strategy\DeliveryGroup;
use Mn\MultiDelivery\Strategy\ManualStrategy;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(SplitDeliveryProcessor::class)]
class SplitDeliveryProcessorTest extends TestCase
{
    public function testSplitsByThePlan(): void
    {
        $cart = $this->process($this->manualPlan());

        static::assertSame([['main', ['sofa' => 1, 'chair' => 1]], ['delivery-2', ['chair' => 2]]], $this->layout($cart));
    }

    public function testWithoutAPlanTheCartKeepsOneDelivery(): void
    {
        static::assertCount(1, $this->process(null)->getDeliveries());
    }

    public function testRemembersTheCostsOfTheSingleDeliveryItReplaced(): void
    {
        $cart = $this->process($this->manualPlan());

        $single = $cart->getExtension(SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION);
        static::assertInstanceOf(ArrayStruct::class, $single);
        static::assertSame(4.9, $single->get('shippingCosts'));
    }

    public function testForgetsTheSingleDeliveryWhenTheCartIsNoLongerSplit(): void
    {
        $plan = $this->manualPlan();
        $plan->setEnabled(false);
        $cart = $this->process($plan, null, null, null, null, true);

        static::assertFalse($cart->hasExtension(SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION));
    }

    public function testTheAllocationEventCanChangeTheSplit(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(DeliveryAllocationEvent::class, static function (DeliveryAllocationEvent $event): void {
            // Project rule: sofas always ship on their own.
            $event->setAllocation($event->getAllocation()
                ->withGroup(new DeliveryGroup('sofa-only', 'sofa'))
                ->withQuantities('sofa', ['sofa-only' => 1]));
        });

        $cart = $this->process($this->manualPlan(), $dispatcher);

        static::assertSame([['main', ['chair' => 1]], ['delivery-2', ['chair' => 2]], ['sofa-only', ['sofa' => 1]]], $this->layout($cart));
    }

    public function testTheBuiltEventCanCancelTheSplit(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SplitDeliveriesBuiltEvent::class, static function (SplitDeliveriesBuiltEvent $event): void {
            $first = $event->getDeliveries()->first();
            \assert($first !== null);
            $event->setDeliveries(new DeliveryCollection([$first]));
        });

        $cart = $this->process($this->manualPlan(), $dispatcher);

        static::assertSame([['main', ['sofa' => 1, 'chair' => 3]]], $this->layout($cart), 'Shopware\'s single delivery stays');
    }

    public function testTheShippingCostPolicyRunsOnTheSplitDeliveries(): void
    {
        $policy = $this->createMock(ShippingCostPolicyInterface::class);
        $policy->expects(static::once())->method('apply')->with(static::callback(static fn (DeliveryCollection $d) => $d->count() === 2));

        $this->process($this->manualPlan(), null, $policy);
    }

    public function testAddressPoliciesDecideWhetherAChosenAddressIsUsed(): void
    {
        $plan = $this->manualPlan();
        $plan->setGroupSettings('delivery-2', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', null);
        $data = new CartDataCollection();
        $address = new CustomerAddressEntity();
        $address->setId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $address->setCustomerId((string) $this->context()->getCustomer()?->getId());
        $address->setCountry(new CountryEntity());
        $data->set('mn-md-address-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $address);

        $deny = new class implements DeliveryAddressPolicyInterface {
            public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool
            {
                return false;
            }
        };
        $allow = new class implements DeliveryAddressPolicyInterface {
            public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool
            {
                return true;
            }
        };

        $denied = $this->process($plan, null, null, [$deny], $data)->getDeliveries()->last();
        $allowed = $this->process($plan, null, null, [$allow], $data)->getDeliveries()->last();

        static::assertNull($denied?->getLocation()->getAddress());
        static::assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $allowed?->getLocation()->getAddress()?->getId());
    }

    public function testAddressesOfOtherCustomersAreNeverUsed(): void
    {
        $plan = $this->manualPlan();
        $plan->setGroupSettings('delivery-2', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', null);
        $address = new CustomerAddressEntity();
        $address->setId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        $address->setCustomerId('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
        $address->setCountry(new CountryEntity());
        $data = new CartDataCollection();
        $data->set('mn-md-address-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $address);
        $allow = new class implements DeliveryAddressPolicyInterface {
            public function isAllowed(CustomerAddressEntity $address, SalesChannelContext $context): bool
            {
                return true;
            }
        };

        static::assertNull($this->process($plan, null, null, [$allow], $data)->getDeliveries()->last()?->getLocation()->getAddress());
    }

    public function testOnlyActiveShippingMethodsOfTheSalesChannelAreLoaded(): void
    {
        $plan = $this->manualPlan();
        $plan->setGroupSettings('delivery-2', null, 'cccccccccccccccccccccccccccccccc');
        $cart = new Cart('token');
        $cart->add(new LineItem('chair', LineItem::PRODUCT_LINE_ITEM_TYPE, 'chair', 3));
        $cart->addExtension(DeliveryPlan::EXTENSION, $plan);

        $criteria = null;
        $shippingMethods = $this->createMock(EntityRepository::class);
        $shippingMethods->expects(static::once())->method('search')->willReturnCallback(function (Criteria $given) use (&$criteria) {
            $criteria = $given;

            return new EntitySearchResult('shipping_method', 0, new ShippingMethodCollection(), null, $given, Context::createDefaultContext());
        });
        $context = $this->context();
        $this->processor(shippingMethods: $shippingMethods)->collect(new CartDataCollection(), $cart, $context, new CartBehavior());

        static::assertInstanceOf(Criteria::class, $criteria);
        static::assertEquals(
            [new EqualsFilter('active', true), new EqualsFilter('salesChannels.id', $context->getSalesChannelId())],
            $criteria->getFilters(),
        );
    }

    public function testAdjustmentsMoveQuantitiesBetweenAutomaticGroups(): void
    {
        $allocation = new Allocation(
            [new DeliveryGroup('now', 'now', true, true), new DeliveryGroup('later', 'later')],
            ['chair' => ['now' => 2, 'later' => 3]],
        );

        $adjusted = $this->adjust($allocation, ['chair' => ['now' => 5]]);

        static::assertSame(['now' => 5], $adjusted->quantities['chair']);
        // "now" received backordered items, so its dates must come from them, not from the in-stock delivery time.
        static::assertFalse($adjusted->groups['now']->inStockDates);
    }

    public function testAdjustmentsThatNoLongerFitTheCartAreIgnored(): void
    {
        $allocation = new Allocation(
            [new DeliveryGroup('now', 'now'), new DeliveryGroup('later', 'later')],
            ['chair' => ['now' => 2, 'later' => 3]],
        );

        static::assertSame(['now' => 2, 'later' => 3], $this->adjust($allocation, ['chair' => ['now' => 4]])->quantities['chair']);
        static::assertSame(['now' => 2, 'later' => 3], $this->adjust($allocation, ['chair' => ['gone' => 5]])->quantities['chair']);
    }

    private function manualPlan(): DeliveryPlan
    {
        $plan = new DeliveryPlan(true, 'manual');
        $plan->setAssignments('chair', [$plan->addManualGroup() => 2]);

        return $plan;
    }

    /**
     * @param list<DeliveryAddressPolicyInterface>|null $addressPolicies
     */
    private function process(
        ?DeliveryPlan $plan,
        ?EventDispatcher $dispatcher = null,
        ?ShippingCostPolicyInterface $costPolicy = null,
        ?array $addressPolicies = null,
        ?CartDataCollection $data = null,
        bool $previouslySplit = false,
    ): Cart {
        $cart = new Cart('token');
        $cart->add(new LineItem('sofa', LineItem::PRODUCT_LINE_ITEM_TYPE, 'sofa', 1));
        $cart->add(new LineItem('chair', LineItem::PRODUCT_LINE_ITEM_TYPE, 'chair', 3));
        $cart->setDeliveries(new DeliveryCollection([Fixtures::delivery('main', ['sofa' => 1, 'chair' => 3], 4.9)]));
        if ($previouslySplit) {
            $cart->addArrayExtension(SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION, ['shippingCosts' => 4.9]);
        }
        if ($plan !== null) {
            $cart->addExtension(DeliveryPlan::EXTENSION, $plan);
        }

        $this->processor($dispatcher, $costPolicy, $addressPolicies)
            ->process($data ?? new CartDataCollection(), $cart, $cart, $this->context(), new CartBehavior());

        return $cart;
    }

    /**
     * @return list<array{string, array<string, int>}>
     */
    private function layout(Cart $cart): array
    {
        $layout = [];
        foreach ($cart->getDeliveries() as $delivery) {
            $positions = [];
            foreach ($delivery->getPositions() as $position) {
                $positions[$position->getIdentifier()] = $position->getQuantity();
            }
            $layout[] = [$delivery->getExtensionOfType('mnDeliveryGroup', \Mn\MultiDelivery\Cart\DeliveryGroupInfo::class)?->getGroupId() ?? '-', $positions];
        }

        return $layout;
    }

    /**
     * @param array<string, array<string, int>> $adjustments
     */
    private function adjust(Allocation $allocation, array $adjustments): Allocation
    {
        return (new \ReflectionMethod(SplitDeliveryProcessor::class, 'applyAdjustments'))
            ->invoke($this->processor(), $allocation, $adjustments, ['chair' => Fixtures::root('chair', 5)]);
    }

    /**
     * @param list<DeliveryAddressPolicyInterface>|null $addressPolicies
     * @param EntityRepository<ShippingMethodCollection>|null $shippingMethods
     */
    private function processor(?EventDispatcher $dispatcher = null, ?ShippingCostPolicyInterface $costPolicy = null, ?array $addressPolicies = null, ?EntityRepository $shippingMethods = null): SplitDeliveryProcessor
    {
        $settings = Fixtures::settings();

        return new SplitDeliveryProcessor(
            new SplitGuard(new StaticSystemConfigService(['MnMultiDelivery.config.enabled' => true])),
            new StrategyRegistry([new ManualStrategy(), new AvailabilityStrategy()], $settings),
            $this->createMock(DeliveryCalculator::class),
            $this->createMock(EntityRepository::class),
            $shippingMethods ?? $this->createMock(EntityRepository::class),
            $settings,
            $costPolicy ?? $this->createMock(ShippingCostPolicyInterface::class),
            new DeliveryAddressPolicies($addressPolicies ?? []),
            $dispatcher ?? new EventDispatcher(),
        );
    }

    private function context(): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('0190a1b2c3d4e5f60718293a4b5c6d70');
        $paymentMethod->setHandlerIdentifier('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment');

        return Generator::generateSalesChannelContext(paymentMethod: $paymentMethod);
    }
}
