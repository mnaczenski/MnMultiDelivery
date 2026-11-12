<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Subscriber;

use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Subscriber\CartPageSubscriber;
use Mn\MultiDelivery\Tests\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPage;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(CartPageSubscriber::class)]
class CartPageSubscriberTest extends TestCase
{
    public function testTellsHowManyDeliveriesASplitCartHas(): void
    {
        $cart = $this->cart(['chair' => 1, 'sofa' => 1]);
        $cart->setDeliveries(new DeliveryCollection([Fixtures::delivery('now', ['chair' => 1]), Fixtures::delivery('later', ['sofa' => 1])]));

        static::assertSame(2, $this->hint($cart)?->get('deliveries'));
    }

    public function testOffersTheSplitForCartsWithSeveralItems(): void
    {
        static::assertSame(1, $this->hint($this->cart(['chair' => 2]))?->get('deliveries'));
    }

    public function testNoHintForASingleItem(): void
    {
        static::assertNull($this->hint($this->cart(['chair' => 1])));
    }

    public function testNoHintWhenSwitchedOffOrWhenTheCartCantBeSplit(): void
    {
        static::assertNull($this->hint($this->cart(['chair' => 2]), ['MnMultiDelivery.config.cartHint' => false]));
        static::assertNull($this->hint($this->cart(['chair' => 2]), ['MnMultiDelivery.config.enabled' => false]));
    }

    /**
     * @param array<string, int> $items
     */
    private function cart(array $items): Cart
    {
        $cart = new Cart('token');
        foreach ($items as $id => $quantity) {
            $cart->add(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity));
        }
        $cart->setDeliveries(new DeliveryCollection([Fixtures::delivery('main', $items)]));

        return $cart;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return ArrayStruct<array<string, mixed>>|null
     */
    private function hint(Cart $cart, array $config = []): ?ArrayStruct
    {
        $config += ['MnMultiDelivery.config.enabled' => true];
        $page = new CheckoutCartPage();
        $page->setCart($cart);
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('0190a1b2c3d4e5f60718293a4b5c6d70');
        $paymentMethod->setHandlerIdentifier('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment');
        $context = Generator::generateSalesChannelContext(paymentMethod: $paymentMethod);

        $prefixed = [];
        foreach ($config as $key => $value) {
            $prefixed[str_replace('MnMultiDelivery.config.', '', $key)] = $value;
        }
        (new CartPageSubscriber(new SplitGuard(new StaticSystemConfigService($config)), Fixtures::settings($prefixed)))
            ->onCartPageLoaded(new CheckoutCartPageLoadedEvent($page, $context, new Request()));

        $hint = $page->getExtension(CartPageSubscriber::EXTENSION);

        return $hint instanceof ArrayStruct ? $hint : null;
    }
}
