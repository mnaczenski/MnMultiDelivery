<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Subscriber;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Subscriber\CartHashSubscriber;
use Mn\MultiDelivery\Tests\Fixtures;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartContextHashStruct;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Event\CartContextHashEvent;
use Shopware\Core\Test\Generator;

#[CoversClass(CartHashSubscriber::class)]
class CartHashSubscriberTest extends TestCase
{
    public function testTheDeliveryAddressIsPartOfTheHash(): void
    {
        static::assertNotSame($this->hash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $this->hash('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'));
        static::assertSame($this->hash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'), $this->hash('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'));
    }

    public function testCartsWithOneDeliveryKeepShopwaresHash(): void
    {
        $cart = new Cart('token');
        $cart->setDeliveries(new DeliveryCollection([Fixtures::delivery('main', ['sofa' => 1])]));
        $event = new CartContextHashEvent(Generator::generateSalesChannelContext(), $cart, new CartContextHashStruct());

        (new CartHashSubscriber())->onHash($event);

        static::assertFalse($event->getHashStruct()->hasExtension('mnMultiDelivery'));
    }

    private function hash(string $secondAddressId): string
    {
        $second = Fixtures::delivery('delivery-2', ['lamp' => 1], 0.0, $secondAddressId);

        $cart = new Cart('token');
        $cart->setDeliveries(new DeliveryCollection([Fixtures::delivery('main', ['sofa' => 1]), $second]));
        $event = new CartContextHashEvent(Generator::generateSalesChannelContext(), $cart, new CartContextHashStruct());
        (new CartHashSubscriber())->onHash($event);

        return json_encode($event->getHashStruct(), \JSON_THROW_ON_ERROR);
    }
}
