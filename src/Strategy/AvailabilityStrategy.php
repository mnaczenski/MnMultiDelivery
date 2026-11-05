<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Ships what is in stock now and the rest (backorders, pre-orders) later.
 * A simple product with partial stock is split by quantity.
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
class AvailabilityStrategy implements DeliverySplitStrategyInterface
{
    public const NAME = 'availability';

    public const GROUP_NOW = 'now';
    public const GROUP_LATER = 'later';

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
        $earliest = null;
        foreach ($roots as $root) {
            foreach ($root->positions as $position) {
                $date = $position->getDeliveryDate()->getEarliest();
                $earliest = $earliest === null || $date < $earliest ? $date : $earliest;
            }
        }

        $quantities = [];
        foreach ($roots as $rootId => $root) {
            $information = $root->lineItem->getDeliveryInformation();
            $stock = $information?->getStock();

            if ($root->isSplittable() && $stock !== null && $stock > 0 && $stock < $root->getQuantity()) {
                $quantities[$rootId] = [self::GROUP_NOW => $stock, self::GROUP_LATER => $root->getQuantity() - $stock];
                continue;
            }

            $later = false;
            foreach ($root->positions as $position) {
                $positionStock = $position->getLineItem()->getDeliveryInformation()?->getStock();
                $isBackorder = $positionStock !== null && $positionStock < $position->getQuantity();
                $startsLater = $earliest !== null && $position->getDeliveryDate()->getEarliest() > $earliest->modify('+1 day');
                if ($isBackorder || $startsLater) {
                    $later = true;
                    break;
                }
            }

            $quantities[$rootId] = [$later ? self::GROUP_LATER : self::GROUP_NOW => $root->getQuantity()];
        }

        return new Allocation([
            new DeliveryGroup(self::GROUP_NOW, 'now', true, true),
            new DeliveryGroup(self::GROUP_LATER, 'later'),
        ], $quantities);
    }
}
