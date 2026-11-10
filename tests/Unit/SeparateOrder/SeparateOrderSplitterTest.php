<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\SeparateOrder;

use Mn\MultiDelivery\Cart\DeliveryGroupInfo;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderSplitter;
use Mn\MultiDelivery\Service\SplitSettings;
use Mn\MultiDelivery\Tests\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;

#[CoversClass(SeparateOrderSplitter::class)]
class SeparateOrderSplitterTest extends TestCase
{
    private const AUSTRIA = '0190aaaaaaaaaaaaaaaaaaaaaaaaaa01';
    private const SWITZERLAND = '0190aaaaaaaaaaaaaaaaaaaaaaaaaa02';

    private SalesChannelContext $context;

    protected function setUp(): void
    {
        $this->context = Generator::generateSalesChannelContext();
    }

    public function testNothingHappensInSameCountryMode(): void
    {
        $cart = $this->cart(['sofa' => 2], [['main', null, ['sofa' => 1]], ['delivery-2', self::AUSTRIA, ['sofa' => 1]]]);

        static::assertNull($this->splitter(SplitSettings::COUNTRY_SAME)->split($cart, $this->context));
    }

    public function testNothingHappensWithoutDeliveriesAbroad(): void
    {
        $cart = $this->cart(['sofa' => 2], [['main', null, ['sofa' => 1]], ['delivery-2', null, ['sofa' => 1]]]);

        static::assertNull($this->splitter()->split($cart, $this->context));
    }

    public function testEachDeliveryAbroadBecomesACartOfItsOwn(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $third = $plan->addManualGroup();
        $plan->setGroupSettings($second, 'address-at', null);
        $plan->setGroupSettings($third, 'address-ch', null);
        $plan->setAssignments('sofa', [$second => 1]);
        $plan->setAssignments('lamp', [$third => 3]);

        $cart = $this->cart(['sofa' => 2, 'lamp' => 3, 'chair' => 1], [
            ['main', null, ['sofa' => 1, 'chair' => 1]],
            [$second, self::AUSTRIA, ['sofa' => 1]],
            [$third, self::SWITZERLAND, ['lamp' => 3]],
        ], $plan);

        $split = $this->splitter()->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertCount(2, $split->parts);
        static::assertSame([$second, $third], array_map(static fn ($part) => $part->groupId, $split->parts));
        static::assertSame(['sofa' => 1], $this->quantities($split->parts[0]->cart));
        static::assertSame(['lamp' => 3], $this->quantities($split->parts[1]->cart));
        static::assertSame('address-at', $split->parts[0]->address->getId());

        // The main cart keeps what stays in the shipping country; the original cart is untouched.
        static::assertSame(['sofa' => 1, 'chair' => 1], $this->quantities($split->mainCart));
        static::assertSame(['sofa' => 2, 'lamp' => 3, 'chair' => 1], $this->quantities($cart));
        static::assertNotSame('token', $split->mainCart->getToken(), 'the main cart must not be stored under the customer\'s token');
        static::assertSame('token', $cart->getToken());
        static::assertTrue($split->hasMainOrder());

        // The foreign groups are gone from the main cart's plan.
        $mainPlan = $split->mainCart->getExtension(DeliveryPlan::EXTENSION);
        static::assertInstanceOf(DeliveryPlan::class, $mainPlan);
        static::assertSame([], $mainPlan->getManualGroups());
        static::assertSame(['delivery-2', 'delivery-3'], $plan->getManualGroups(), 'the original plan is untouched');

        // Separate orders are not split again, and product data is loaded again for the new country.
        $partPlan = $split->parts[0]->cart->getExtension(DeliveryPlan::EXTENSION);
        static::assertInstanceOf(DeliveryPlan::class, $partPlan);
        static::assertFalse($partPlan->isEnabled());
        static::assertNull($split->parts[0]->cart->getLineItems()->get('sofa')?->getDataTimestamp());
    }

    public function testEverythingAbroadLeavesAnEmptyMainCart(): void
    {
        $cart = $this->cart(['sofa' => 2], [['main', self::AUSTRIA, ['sofa' => 2]]]);

        $split = $this->splitter()->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertFalse($split->hasMainOrder());
        static::assertSame(['sofa' => 2], $this->quantities($split->parts[0]->cart));
    }

    public function testAContainerMovesWithAllItsChildren(): void
    {
        $bundle = new LineItem('bundle', LineItem::CONTAINER_LINE_ITEM, null, 1);
        $bundle->addChild(new LineItem('bed', LineItem::PRODUCT_LINE_ITEM_TYPE, 'bed', 1));
        $bundle->addChild(new LineItem('mattress', LineItem::PRODUCT_LINE_ITEM_TYPE, 'mattress', 1));
        $cart = $this->cart(['chair' => 1], [['main', null, ['chair' => 1]], ['delivery-2', self::AUSTRIA, ['bed' => 1, 'mattress' => 1]]]);
        $cart->add($bundle);

        $split = $this->splitter()->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertSame(['bundle' => 1], $this->quantities($split->parts[0]->cart));
        static::assertCount(2, $split->parts[0]->cart->getLineItems()->get('bundle')?->getChildren() ?? []);
        static::assertSame(['chair' => 1], $this->quantities($split->mainCart));
    }

    public function testCartsHoldingClosuresCanBeSplit(): void
    {
        // Calculated carts carry closures (e.g. in extensions), which serialize() rejects.
        $cart = $this->cart(['sofa' => 2], [['main', null, ['sofa' => 1]], ['delivery-2', self::AUSTRIA, ['sofa' => 1]]]);
        $cart->addExtension('withClosure', new ArrayStruct(['callback' => static fn () => true]));
        $cart->getLineItems()->get('sofa')?->addExtension('withClosure', new ArrayStruct(['callback' => static fn () => true]));

        $split = $this->splitter()->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertSame(['sofa' => 1], $this->quantities($split->mainCart));
        static::assertSame(['sofa' => 1], $this->quantities($split->parts[0]->cart));
        static::assertSame(['sofa' => 2], $this->quantities($cart), 'the original cart is untouched');
    }

    public function testEveryDeliveryBecomesASeparateOrder(): void
    {
        $plan = new DeliveryPlan(true, 'manual');
        $second = $plan->addManualGroup();
        $third = $plan->addManualGroup();
        $plan->setAssignments('sofa', [$second => 1]);
        $plan->setAssignments('lamp', [$third => 3]);

        $cart = $this->cart(['sofa' => 2, 'lamp' => 3, 'chair' => 1], [
            ['main', null, ['sofa' => 1, 'chair' => 1]],
            [$second, null, ['sofa' => 1]],
            [$third, self::AUSTRIA, ['lamp' => 3]],
        ], $plan);

        $split = $this->splitter(SplitSettings::COUNTRY_ALL_SEPARATE, true)->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertSame([$second, $third], array_map(static fn ($part) => $part->groupId, $split->parts));
        static::assertSame(['sofa' => 1], $this->quantities($split->parts[0]->cart));
        static::assertSame(['lamp' => 3], $this->quantities($split->parts[1]->cart));
        static::assertSame('address-home-' . $second, $split->parts[0]->address->getId());
        static::assertSame(['sofa' => 1, 'chair' => 1], $this->quantities($split->mainCart));
        static::assertTrue($split->hasMainOrder());
    }

    public function testEveryDeliveryModeKeepsTheFirstHomeDeliveryWhenTheMainOneIsAbroad(): void
    {
        $cart = $this->cart(['sofa' => 3], [
            ['main', self::AUSTRIA, ['sofa' => 1]],
            ['delivery-2', null, ['sofa' => 1]],
            ['delivery-3', null, ['sofa' => 1]],
        ]);

        $split = $this->splitter(SplitSettings::COUNTRY_ALL_SEPARATE, true)->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertSame(['main', 'delivery-3'], array_map(static fn ($part) => $part->groupId, $split->parts));
        static::assertSame(['sofa' => 1], $this->quantities($split->mainCart));
    }

    public function testEveryDeliveryModeSplitsLikeHybridWithoutAnAllowedPayment(): void
    {
        $cart = $this->cart(['sofa' => 3], [
            ['main', null, ['sofa' => 1]],
            ['delivery-2', null, ['sofa' => 1]],
            ['delivery-3', self::AUSTRIA, ['sofa' => 1]],
        ]);

        $split = $this->splitter(SplitSettings::COUNTRY_ALL_SEPARATE, false)->split($cart, $this->context);

        static::assertNotNull($split);
        static::assertSame(['delivery-3'], array_map(static fn ($part) => $part->groupId, $split->parts));
        static::assertSame(['sofa' => 2], $this->quantities($split->mainCart));
    }

    public function testEveryDeliveryModeWithOneDeliveryDoesNothing(): void
    {
        $cart = $this->cart(['sofa' => 1], [['main', null, ['sofa' => 1]]]);

        static::assertNull($this->splitter(SplitSettings::COUNTRY_ALL_SEPARATE, true)->split($cart, $this->context));
    }

    private function splitter(string $mode = SplitSettings::COUNTRY_SEPARATE_ORDERS, bool $paymentAllowed = false): SeparateOrderSplitter
    {
        return new SeparateOrderSplitter(Fixtures::settings([
            'countryMode' => $mode,
            'separateOrderPaymentHandlers' => $paymentAllowed ? $this->context->getPaymentMethod()->getHandlerIdentifier() : '',
        ]));
    }

    /**
     * @param array<string, int>                                         $items
     * @param list<array{string, ?string, array<string, int>}> $deliveries [group id, country id (null = shipping country), quantities]
     */
    private function cart(array $items, array $deliveries, ?DeliveryPlan $plan = null): Cart
    {
        $cart = new Cart('token');
        foreach ($items as $id => $quantity) {
            $item = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity);
            $item->setStackable(true);
            $cart->add($item);
        }

        $collection = new DeliveryCollection();
        foreach ($deliveries as [$groupId, $countryId, $quantities]) {
            $collection->add($this->delivery($groupId, $countryId, $quantities));
        }
        $cart->setDeliveries($collection);
        $cart->addExtension(DeliveryPlan::EXTENSION, $plan ?? new DeliveryPlan(true, 'manual'));

        return $cart;
    }

    /**
     * @param array<string, int> $quantities
     */
    private function delivery(string $groupId, ?string $countryId, array $quantities): Delivery
    {
        $positions = new DeliveryPositionCollection();
        foreach ($quantities as $id => $quantity) {
            $positions->add(Fixtures::position(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity), $quantity));
        }

        $home = $this->context->getShippingLocation();
        $address = new CustomerAddressEntity();
        $address->setId('address-home-' . $groupId);
        $address->setCountryId($home->getCountry()->getId());
        $address->setCountry($home->getCountry());
        $location = new ShippingLocation($home->getCountry(), $home->getState(), $address);
        if ($countryId !== null) {
            $country = new CountryEntity();
            $country->setId($countryId);
            $address = new CustomerAddressEntity();
            $address->setId($countryId === self::AUSTRIA ? 'address-at' : 'address-ch');
            $address->setCountryId($countryId);
            $address->setCountry($country);
            $location = new ShippingLocation($country, null, $address);
        }

        $method = new ShippingMethodEntity();
        $method->setId('0190a1b2c3d4e5f60718293a4b5c6d7e');
        $delivery = new Delivery(
            $positions,
            new DeliveryDate(new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+3 days')),
            $method,
            $location,
            Fixtures::price(0),
        );
        $delivery->addExtension(DeliveryGroupInfo::EXTENSION, new DeliveryGroupInfo($groupId, $groupId, false, null, null));

        return $delivery;
    }

    /**
     * @return array<string, int>
     */
    private function quantities(Cart $cart): array
    {
        $result = [];
        foreach ($cart->getLineItems() as $item) {
            $result[$item->getId()] = $item->getQuantity();
        }

        return $result;
    }
}
