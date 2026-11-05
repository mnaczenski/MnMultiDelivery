<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Service;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * The merchant's split settings per sales channel (see config.xml).
 *
 * Public API: stable within a major version.
 */
class SplitSettings
{
    public const COSTS_EACH = 'each';
    public const COSTS_FREE = 'free';
    public const COSTS_FLAT = 'flat';

    /** Deliveries stay in the shipping country (default). */
    public const COUNTRY_SAME = 'sameCountry';

    /** A delivery to another country becomes its own order. */
    public const COUNTRY_SEPARATE_ORDERS = 'separateOrders';

    private const PREFIX = 'MnMultiDelivery.config.';

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    /**
     * Strategy names customers may choose; null means all of them.
     *
     * @return list<string>|null
     */
    public function allowedStrategies(SalesChannelContext $context): ?array
    {
        $value = $this->systemConfig->get(self::PREFIX . 'strategies', $context->getSalesChannelId());
        if (!\is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_filter($value, 'is_string'));
    }

    public function defaultStrategy(SalesChannelContext $context): string
    {
        return $this->systemConfig->getString(self::PREFIX . 'defaultStrategy', $context->getSalesChannelId()) ?: 'manual';
    }

    /**
     * The plan of a cart the customer has not touched yet: split automatically
     * with the default strategy when the merchant enabled that.
     */
    public function defaultPlan(SalesChannelContext $context): DeliveryPlan
    {
        return new DeliveryPlan(
            $this->systemConfig->getBool(self::PREFIX . 'autoSplit', $context->getSalesChannelId()),
            $this->defaultStrategy($context),
        );
    }

    /** Deliveries whose dates start at most this many days apart ship together. */
    public function deliveryTimeGroupDays(SalesChannelContext $context): int
    {
        return max(0, $this->systemConfig->getInt(self::PREFIX . 'deliveryTimeGroupDays', $context->getSalesChannelId()));
    }

    public function deliveryTimeMaxDeliveries(SalesChannelContext $context): int
    {
        $value = $this->systemConfig->getInt(self::PREFIX . 'deliveryTimeMaxDeliveries', $context->getSalesChannelId());

        return $value > 0 ? $value : 3;
    }

    /**
     * @return self::COSTS_*
     */
    public function extraDeliveryCosts(SalesChannelContext $context): string
    {
        $value = $this->systemConfig->getString(self::PREFIX . 'extraDeliveryCosts', $context->getSalesChannelId());

        return \in_array($value, [self::COSTS_FREE, self::COSTS_FLAT], true) ? $value : self::COSTS_EACH;
    }

    /** Gross maximum price of every additional delivery when extraDeliveryCosts is "flat". */
    public function extraDeliveryFlatPrice(SalesChannelContext $context): float
    {
        return max(0.0, $this->systemConfig->getFloat(self::PREFIX . 'extraDeliveryFlatPrice', $context->getSalesChannelId()));
    }

    /**
     * @return self::COUNTRY_*
     */
    public function countryMode(SalesChannelContext $context): string
    {
        $value = $this->systemConfig->getString(self::PREFIX . 'countryMode', $context->getSalesChannelId());

        return $value === self::COUNTRY_SEPARATE_ORDERS ? $value : self::COUNTRY_SAME;
    }

    /**
     * Whether deliveries to another country may become separate orders right
     * now: the merchant enabled it and the selected payment method has no
     * online payment step (see separateOrderPaymentHandlers).
     */
    public function separateOrdersAllowed(SalesChannelContext $context): bool
    {
        return $this->countryMode($context) === self::COUNTRY_SEPARATE_ORDERS
            && $this->separateOrderPaymentAllowed($context);
    }

    public function separateOrderPaymentAllowed(SalesChannelContext $context): bool
    {
        $handler = $context->getPaymentMethod()->getHandlerIdentifier();
        $value = $this->systemConfig->getString(self::PREFIX . 'separateOrderPaymentHandlers', $context->getSalesChannelId());
        foreach (preg_split('/[\n,]+/', $value) ?: [] as $allowed) {
            $allowed = trim($allowed);
            if ($allowed !== '' && str_contains($handler, $allowed)) {
                return true;
            }
        }

        return false;
    }
}
