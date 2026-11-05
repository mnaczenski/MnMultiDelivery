<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests;

use Mn\MultiDelivery\Cart\DeliveryGroupInfo;
use Mn\MultiDelivery\Service\SplitSettings;
use Mn\MultiDelivery\Strategy\RootItem;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryTime;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRule;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

/**
 * Small builders for cart structures used across the tests.
 */
final class Fixtures
{
    /**
     * @param array{int, int, string} $time  [min, max, unit]
     */
    public static function root(string $id, int $quantity, ?int $stock = null, array $time = [1, 3, 'day'], int $restockDays = 0): RootItem
    {
        $deliveryTime = new DeliveryTime();
        $deliveryTime->setMin($time[0]);
        $deliveryTime->setMax($time[1]);
        $deliveryTime->setUnit($time[2]);

        $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity);
        $lineItem->setDeliveryInformation(new DeliveryInformation($stock ?? 999, 1.0, false, $restockDays ?: null, $deliveryTime));

        $date = DeliveryDate::createFromDeliveryTime($deliveryTime);
        if ($stock !== null && $stock < $quantity && $restockDays > 0) {
            $date = new DeliveryDate(
                $date->getEarliest()->modify("+{$restockDays} days"),
                $date->getLatest()->modify("+{$restockDays} days"),
            );
        }

        return new RootItem($lineItem, [self::position($lineItem, $quantity, $date)]);
    }

    public static function position(LineItem $lineItem, int $quantity, ?DeliveryDate $date = null): DeliveryPosition
    {
        return new DeliveryPosition(
            $lineItem->getId(),
            $lineItem,
            $quantity,
            new CalculatedPrice(10.0, 10.0 * $quantity, new CalculatedTaxCollection(), new TaxRuleCollection(), $quantity),
            $date ?? new DeliveryDate(new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+3 days')),
        );
    }

    /**
     * @param array<string, int> $quantities line item id => quantity
     */
    public static function delivery(string $groupId, array $quantities, float $shippingCosts = 0.0, ?string $addressId = null): Delivery
    {
        $positions = new DeliveryPositionCollection();
        foreach ($quantities as $id => $quantity) {
            $positions->add(self::position(new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity), $quantity));
        }
        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId('0190a1b2c3d4e5f60718293a4b5c6d7e');
        $country = new CountryEntity();
        $country->setId('0190a1b2c3d4e5f60718293a4b5c6d7f');
        $address = null;
        if ($addressId !== null) {
            $address = new CustomerAddressEntity();
            $address->setId($addressId);
        }

        $delivery = new Delivery(
            $positions,
            new DeliveryDate(new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+3 days')),
            $shippingMethod,
            new ShippingLocation($country, null, $address),
            self::price($shippingCosts),
        );
        $delivery->addExtension(DeliveryGroupInfo::EXTENSION, new DeliveryGroupInfo($groupId, $groupId, false, null, null));

        return $delivery;
    }

    /** A gross price with 19 % VAT. */
    public static function price(float $gross): CalculatedPrice
    {
        $tax = round($gross / 1.19 * 0.19, 2);

        return new CalculatedPrice($gross, $gross, new CalculatedTaxCollection([new CalculatedTax($tax, 19, $gross)]), new TaxRuleCollection([new TaxRule(19)]));
    }

    /**
     * @param array<string, mixed> $config plugin settings without the "MnMultiDelivery.config." prefix
     */
    public static function settings(array $config = []): SplitSettings
    {
        $prefixed = [];
        foreach ($config as $key => $value) {
            $prefixed['MnMultiDelivery.config.' . $key] = $value;
        }

        return new SplitSettings(new StaticSystemConfigService($prefixed));
    }
}
