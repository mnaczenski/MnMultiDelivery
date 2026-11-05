<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\SeparateOrder;

use Mn\MultiDelivery\SeparateOrder\SeparateOrderCalculator;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderContextFactory;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderPart;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderSplit;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderSplitter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Test\Generator;

#[CoversClass(SeparateOrderCalculator::class)]
class SeparateOrderCalculatorTest extends TestCase
{
    public function testTheCustomersCartIsNeverStoredOrDeleted(): void
    {
        $context = Generator::generateSalesChannelContext(token: 'customer-token');
        $partContext = Generator::generateSalesChannelContext(token: 'part-context-token');

        $main = new Cart('main-throwaway');
        $main->add(new LineItem('chair', LineItem::PRODUCT_LINE_ITEM_TYPE, 'chair', 1));
        $address = new CustomerAddressEntity();
        $address->setId('address-at');
        $split = new SeparateOrderSplit($main, [new SeparateOrderPart('delivery-2', 'delivery-2', $address, null, new Cart('part-throwaway'))]);

        $splitter = $this->createMock(SeparateOrderSplitter::class);
        $splitter->method('split')->willReturn($split);
        $contexts = $this->createMock(SeparateOrderContextFactory::class);
        $contexts->method('create')->willReturn($partContext);

        $calculated = [];
        $calculator = $this->createMock(CartCalculator::class);
        $calculator->method('calculate')->willReturnCallback(static function (Cart $cart) use (&$calculated): Cart {
            $calculated[] = $cart->getToken();
            $result = clone $cart;
            $result->setPersisted(true); // as after CartRuleLoader stored it

            return $result;
        });

        $deleted = [];
        $persister = $this->createMock(AbstractCartPersister::class);
        $persister->method('delete')->willReturnCallback(static function (string $token) use (&$deleted): void {
            $deleted[] = $token;
        });

        $result = (new SeparateOrderCalculator($splitter, $contexts, $calculator, $persister))->calculate(new Cart('customer-token'), $context);

        static::assertNotNull($result);
        static::assertSame(['part-context-token', 'main-throwaway'], $calculated, 'separate orders use their context token, the main cart a throwaway one');
        static::assertSame(['part-context-token', 'main-throwaway'], $deleted);
        static::assertNotContains('customer-token', $deleted);
        static::assertFalse($result['split']->mainCart->isPersisted(), 'Shopware\'s order route must not look for the deleted copy');
        static::assertFalse($result['split']->parts[0]->cart->isPersisted());
    }

    public function testDiscardNeverDeletesTheCustomersCart(): void
    {
        $persister = $this->createMock(AbstractCartPersister::class);
        $persister->expects(static::never())->method('delete');

        $calculator = new SeparateOrderCalculator(
            $this->createMock(SeparateOrderSplitter::class),
            $this->createMock(SeparateOrderContextFactory::class),
            $this->createMock(CartCalculator::class),
            $persister,
        );

        $calculator->discard(new Cart('customer-token'), Generator::generateSalesChannelContext(), 'customer-token');
    }
}
