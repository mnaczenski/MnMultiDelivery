<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Example;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Event\DeliveryAllocationEvent;
use Mn\MultiDelivery\Event\SplitOrderConvertedEvent;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\DeliveryGroup;
use Mn\MultiDelivery\Tests\Fixtures;
use Mn\MultiDeliveryExample\Blocker\PreorderBlocker;
use Mn\MultiDeliveryExample\Strategy\BulkyGoodsStrategy;
use Mn\MultiDeliveryExample\Subscriber\ErpShipmentReference;
use Mn\MultiDeliveryExample\Subscriber\KeepGiftCardsWithFirstDelivery;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Test\Generator;

/**
 * The example project plugin, built only on the public extension points.
 */
#[CoversNothing]
class ExamplePluginTest extends TestCase
{
    public function testBulkyItemsShipByFreight(): void
    {
        $sofa = Fixtures::root('sofa', 1);
        $sofa->lineItem->setDeliveryInformation(new DeliveryInformation(10, 45.0, false));
        $lamp = Fixtures::root('lamp', 2);
        $lamp->lineItem->setDeliveryInformation(new DeliveryInformation(10, 2.0, false));

        $allocation = (new BulkyGoodsStrategy())->allocate(['sofa' => $sofa, 'lamp' => $lamp], new DeliveryPlan(true, 'bulky'), Generator::generateSalesChannelContext());

        static::assertSame(['freight' => 1], $allocation->quantities['sofa']);
        static::assertSame(['parcel' => 2], $allocation->quantities['lamp']);
    }

    public function testGiftCardsMoveToTheFirstDelivery(): void
    {
        $card = Fixtures::root('card', 1);
        $card->lineItem->setPayloadValue('productNumber', 'GIFT-CARD');
        $event = new DeliveryAllocationEvent(
            new Allocation([new DeliveryGroup('parcel'), new DeliveryGroup('freight')], ['card' => ['freight' => 1]]),
            ['card' => $card],
            'bulky',
            new DeliveryPlan(true, 'bulky'),
            new Cart('token'),
            Generator::generateSalesChannelContext(),
        );

        (new KeepGiftCardsWithFirstDelivery())->onAllocation($event);

        static::assertSame(['parcel' => 1], $event->getAllocation()->quantities['card']);
    }

    public function testPreordersBlockTheSplit(): void
    {
        $cart = new Cart('token');
        $item = new LineItem('ps6', LineItem::PRODUCT_LINE_ITEM_TYPE, 'ps6', 1);
        $item->setPayloadValue('customFields', ['example_preorder' => true]);
        $cart->add($item);

        static::assertSame('preorder', (new PreorderBlocker())->blockReason($cart, Generator::generateSalesChannelContext(), new CartBehavior()));
    }

    public function testEveryDeliveryGetsAnErpReference(): void
    {
        $event = new SplitOrderConvertedEvent(
            ['deliveries' => [['customFields' => ['mn_multi_delivery' => ['group' => 'parcel']]], ['customFields' => ['mn_multi_delivery' => ['group' => 'freight']]]]],
            new Cart('token'),
            Generator::generateSalesChannelContext(),
        );

        (new ErpShipmentReference())->onConverted($event);

        static::assertSame(['SHIP-PARCEL-1', 'SHIP-FREIGHT-2'], array_map(
            static fn (array $delivery) => $delivery['customFields']['example_erp_shipment'],
            $event->getConvertedCart()['deliveries'],
        ));
    }
}
