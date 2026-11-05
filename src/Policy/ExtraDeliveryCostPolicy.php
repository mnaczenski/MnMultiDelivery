<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Policy;

use Mn\MultiDelivery\Service\SplitSettings;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\QuantityPriceDefinition;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Default shipping cost policy: the most expensive delivery keeps its price;
 * the others are priced normally, free, or capped at a flat price, depending
 * on the "extra delivery costs" setting.
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
class ExtraDeliveryCostPolicy implements ShippingCostPolicyInterface
{
    public function __construct(
        private readonly SplitSettings $settings,
        private readonly QuantityPriceCalculator $priceCalculator,
        private readonly TaxCalculator $taxCalculator,
    ) {
    }

    public function apply(DeliveryCollection $deliveries, SalesChannelContext $context): void
    {
        $mode = $this->settings->extraDeliveryCosts($context);
        if ($mode === SplitSettings::COSTS_EACH) {
            return;
        }

        // The most expensive delivery is the one Shopware's OrderConverter makes the primary delivery.
        $charged = null;
        foreach ($deliveries as $delivery) {
            if ($charged === null || $delivery->getShippingCosts()->getTotalPrice() > $charged->getShippingCosts()->getTotalPrice()) {
                $charged = $delivery;
            }
        }

        foreach ($deliveries as $delivery) {
            if ($delivery === $charged) {
                continue;
            }
            $rules = $delivery->getShippingCosts()->getTaxRules();
            $price = $mode === SplitSettings::COSTS_FLAT ? $this->settings->extraDeliveryFlatPrice($context) : 0.0;
            if ($price > 0 && $context->getTaxState() === CartPrice::TAX_STATE_NET) {
                // The setting is a gross price; net customers pay it without tax.
                $price -= $this->taxCalculator->calculateGrossTaxes($price, $rules)->getAmount();
            }
            $costs = $price > 0
                ? $this->priceCalculator->calculate(new QuantityPriceDefinition($price, $rules, 1), $context)
                : new CalculatedPrice(0, 0, new CalculatedTaxCollection(), $rules);
            // A flat price caps the costs; it never makes a delivery more expensive than its shipping method.
            if ($costs->getTotalPrice() < $delivery->getShippingCosts()->getTotalPrice()) {
                $delivery->setShippingCosts($costs);
            }
        }
    }
}
