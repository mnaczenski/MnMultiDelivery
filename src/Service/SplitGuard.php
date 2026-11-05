<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Service;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Mn\MultiDelivery\Policy\SplitBlockerInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Decides whether a cart may be split. Everything not explicitly supported
 * keeps Shopware's single delivery. Projects add their own reasons with
 * services tagged "mn.multi_delivery.blocker" (SplitBlockerInterface),
 * which run after the built-in checks.
 *
 * Public API: stable within a major version.
 */
class SplitGuard
{
    public const REASON_DISABLED = 'disabled';
    public const REASON_QUOTE = 'quote';
    public const REASON_SUBSCRIPTION = 'subscription';
    public const REASON_RECALCULATION = 'recalculation';
    public const REASON_PAYMENT = 'payment';
    public const REASON_EMPTY = 'empty';

    /** SwagCommercial QuoteToCartConverter::ORIGINAL_QUOTE_ID */
    private const QUOTE_CART_EXTENSION = 'originalQuoteId';

    /** SwagCommercial SubscriptionContextStruct::SUBSCRIPTION_EXTENSION */
    private const SUBSCRIPTION_CONTEXT_EXTENSION = 'subscription';

    /**
     * @param iterable<SplitBlockerInterface> $blockers
     */
    public function __construct(
        private readonly SystemConfigService $systemConfig,
        private readonly iterable $blockers = [],
    ) {
    }

    public function isActive(SalesChannelContext $context): bool
    {
        return $this->systemConfig->getBool('MnMultiDelivery.config.enabled', $context->getSalesChannelId());
    }

    /**
     * @return string|null null when the cart may be split, otherwise one of the REASON_* constants
     */
    public function blockReason(Cart $cart, SalesChannelContext $context, ?CartBehavior $behavior = null): ?string
    {
        if (!$this->isActive($context)) {
            return self::REASON_DISABLED;
        }

        if ($cart->getLineItems()->count() === 0) {
            return self::REASON_EMPTY;
        }

        if ($cart->hasExtension(self::QUOTE_CART_EXTENSION)) {
            return self::REASON_QUOTE;
        }

        if ($context->hasExtension(self::SUBSCRIPTION_CONTEXT_EXTENSION)) {
            return self::REASON_SUBSCRIPTION;
        }

        $behavior ??= $cart->getBehavior();
        if ($behavior !== null
            && ($behavior->hasPermission(DeliveryProcessor::SKIP_DELIVERY_PRICE_RECALCULATION) || $behavior->isRecalculation())) {
            return self::REASON_RECALCULATION;
        }

        if ($cart->hasExtension(DeliveryProcessor::MANUAL_SHIPPING_COSTS)) {
            return self::REASON_RECALCULATION;
        }

        $handler = $context->getPaymentMethod()->getHandlerIdentifier();
        foreach ($this->blockedPaymentHandlers($context) as $blocked) {
            if ($blocked !== '' && str_contains($handler, $blocked)) {
                return self::REASON_PAYMENT;
            }
        }

        $behavior ??= new CartBehavior();
        foreach ($this->blockers as $blocker) {
            $reason = $blocker->blockReason($cart, $context, $behavior);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function blockedPaymentHandlers(SalesChannelContext $context): array
    {
        $value = $this->systemConfig->getString('MnMultiDelivery.config.blockedPaymentHandlers', $context->getSalesChannelId());

        return array_values(array_filter(array_map('trim', preg_split('/[\n,]+/', $value) ?: [])));
    }
}
