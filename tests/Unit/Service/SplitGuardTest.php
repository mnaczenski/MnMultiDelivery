<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Policy\SplitBlockerInterface;
use Mn\MultiDelivery\Service\SplitGuard;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

#[CoversClass(SplitGuard::class)]
class SplitGuardTest extends TestCase
{
    public function testAnOrdinaryCartMayBeSplit(): void
    {
        static::assertNull($this->guard()->blockReason($this->cart(), $this->context()));
    }

    public function testDisabledSalesChannel(): void
    {
        static::assertSame(SplitGuard::REASON_DISABLED, $this->guard(false)->blockReason($this->cart(), $this->context()));
    }

    public function testEmptyCart(): void
    {
        static::assertSame(SplitGuard::REASON_EMPTY, $this->guard()->blockReason(new Cart('token'), $this->context()));
    }

    public function testQuoteCart(): void
    {
        $cart = $this->cart();
        $cart->addExtension('originalQuoteId', new ArrayStruct());

        static::assertSame(SplitGuard::REASON_QUOTE, $this->guard()->blockReason($cart, $this->context()));
    }

    public function testSubscriptionContext(): void
    {
        $context = $this->context();
        $context->addExtension('subscription', new ArrayStruct());

        static::assertSame(SplitGuard::REASON_SUBSCRIPTION, $this->guard()->blockReason($this->cart(), $context));
    }

    public function testAdminRecalculationKeepsTheStoredDeliveries(): void
    {
        $guard = $this->guard();
        $skip = new CartBehavior([DeliveryProcessor::SKIP_DELIVERY_PRICE_RECALCULATION => true]);
        $recalculation = new CartBehavior([], true, true);
        $manualCosts = $this->cart();
        $manualCosts->addExtension(DeliveryProcessor::MANUAL_SHIPPING_COSTS, new ArrayStruct());

        static::assertSame(SplitGuard::REASON_RECALCULATION, $guard->blockReason($this->cart(), $this->context(), $skip));
        static::assertSame(SplitGuard::REASON_RECALCULATION, $guard->blockReason($this->cart(), $this->context(), $recalculation));
        static::assertSame(SplitGuard::REASON_RECALCULATION, $guard->blockReason($manualCosts, $this->context()));
    }

    public function testBlockedPaymentHandler(): void
    {
        $guard = $this->guard(true, "Swag\\PayPal\nOther\\Handler");

        static::assertSame(SplitGuard::REASON_PAYMENT, $guard->blockReason($this->cart(), $this->context('Swag\\PayPal\\Checkout\\Payment\\PayPalPaymentHandler')));
        static::assertNull($guard->blockReason($this->cart(), $this->context('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\InvoicePayment')));
    }

    public function testProjectBlockersRunAfterTheBuiltInChecks(): void
    {
        $blocker = new class implements SplitBlockerInterface {
            public function blockReason(Cart $cart, SalesChannelContext $context, CartBehavior $behavior): ?string
            {
                return $cart->has('gift-card') ? 'gift-card' : null;
            }
        };
        $guard = $this->guard(true, 'Swag\\PayPal', [$blocker]);
        $cart = $this->cart();

        static::assertNull($guard->blockReason($cart, $this->context()));
        $cart->add(new LineItem('gift-card', LineItem::PRODUCT_LINE_ITEM_TYPE, 'gift-card', 1));
        static::assertSame('gift-card', $guard->blockReason($cart, $this->context()));
        static::assertSame(SplitGuard::REASON_DISABLED, $this->guard(false, '', [$blocker])->blockReason($cart, $this->context()));
    }

    /**
     * @param list<SplitBlockerInterface> $blockers
     */
    private function guard(bool $enabled = true, string $blocked = 'Swag\\PayPal', array $blockers = []): SplitGuard
    {
        return new SplitGuard(new StaticSystemConfigService([
            'MnMultiDelivery.config.enabled' => $enabled,
            'MnMultiDelivery.config.blockedPaymentHandlers' => $blocked,
        ]), $blockers);
    }

    private function cart(): Cart
    {
        $cart = new Cart('token');
        $cart->add(new LineItem('sofa', LineItem::PRODUCT_LINE_ITEM_TYPE, 'sofa', 1));

        return $cart;
    }

    private function context(string $handler = 'Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment'): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('0190a1b2c3d4e5f60718293a4b5c6d70');
        $paymentMethod->setHandlerIdentifier($handler);

        return Generator::generateSalesChannelContext(paymentMethod: $paymentMethod);
    }
}
