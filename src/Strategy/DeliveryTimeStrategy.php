<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Service\SplitSettings;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * One delivery per delivery-time band: items whose delivery dates start
 * close together ship together. The dates are the ones Shopware calculated
 * (delivery time, plus restock time for items out of stock), so "1-3 days",
 * "2-3 weeks" and backorders end up in separate deliveries. A simple product
 * with partial stock ships its in-stock part with its band, the rest later.
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
class DeliveryTimeStrategy implements DeliverySplitStrategyInterface
{
    public const NAME = 'deliveryTime';

    public function __construct(private readonly SplitSettings $settings)
    {
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function isAvailable(SalesChannelContext $context): bool
    {
        return true;
    }

    public function allowsManualMoves(): bool
    {
        return false;
    }

    public function allowsAdjustments(): bool
    {
        return true;
    }

    public function allocate(array $roots, DeliveryPlan $plan, SalesChannelContext $context): Allocation
    {
        // Parts: [root id, quantity, earliest date, ships on in-stock dates]
        $parts = [];
        foreach ($roots as $rootId => $root) {
            $stock = $root->lineItem->getDeliveryInformation()?->getStock();
            $deliveryTime = $root->lineItem->getDeliveryInformation()?->getDeliveryTime();
            if ($root->isSplittable() && $deliveryTime !== null && $stock !== null && $stock > 0 && $stock < $root->getQuantity()) {
                $parts[] = [$rootId, $stock, DeliveryDate::createFromDeliveryTime($deliveryTime)->getEarliest(), true];
                $parts[] = [$rootId, $root->getQuantity() - $stock, $root->positions[0]->getDeliveryDate()->getEarliest(), false];
                continue;
            }

            $earliest = null;
            $backorder = false;
            foreach ($root->positions as $position) {
                $date = $position->getDeliveryDate()->getEarliest();
                $earliest = $earliest === null || $date > $earliest ? $date : $earliest;
                $positionStock = $position->getLineItem()->getDeliveryInformation()?->getStock();
                $backorder = $backorder || ($positionStock !== null && $positionStock < $position->getQuantity());
            }
            $parts[] = [$rootId, $root->getQuantity(), $earliest ?? new \DateTimeImmutable(), !$backorder];
        }

        usort($parts, static fn (array $a, array $b) => $a[2] <=> $b[2]);

        // Bands: a part joins the current band when it starts within the configured days of the band's start.
        $groupDays = $this->settings->deliveryTimeGroupDays($context);
        $bands = [];
        foreach ($parts as $part) {
            $band = array_key_last($bands);
            if ($band !== null && $this->days($bands[$band]['start'], $part[2]) <= $groupDays) {
                $bands[$band]['parts'][] = $part;
                continue;
            }
            $bands[] = ['start' => $part[2], 'parts' => [$part]];
        }

        // Too many bands: the latest ones ship together.
        $max = $this->settings->deliveryTimeMaxDeliveries($context);
        while (\count($bands) > $max) {
            $last = array_pop($bands);
            $bands[array_key_last($bands)]['parts'] = [...$bands[array_key_last($bands)]['parts'], ...$last['parts']];
        }

        $groups = [];
        $quantities = [];
        foreach ($bands as $index => $band) {
            $groupId = 'time-' . ($index + 1);
            $inStockDates = true;
            foreach ($band['parts'] as [$rootId, $quantity, , $inStock]) {
                $quantities[$rootId][$groupId] = ($quantities[$rootId][$groupId] ?? 0) + $quantity;
                $inStockDates = $inStockDates && $inStock;
            }
            // In-stock dates only for bands without backorders, or the processor would date them too early.
            $groups[] = new DeliveryGroup($groupId, (string) ($index + 1), true, $inStockDates);
        }

        return new Allocation($groups, $quantities);
    }

    private function days(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return (int) $from->diff($to)->days;
    }
}
