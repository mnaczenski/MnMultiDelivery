<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The customer moves items between deliveries; everything not moved stays
 * in the first delivery.
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
class ManualStrategy implements DeliverySplitStrategyInterface
{
    public const NAME = 'manual';

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
        return true;
    }

    public function allowsAdjustments(): bool
    {
        // No adjustments: moves are the strategy itself.
        return false;
    }

    public function allocate(array $roots, DeliveryPlan $plan, SalesChannelContext $context): Allocation
    {
        $groups = [DeliveryPlan::MAIN_GROUP => new DeliveryGroup(DeliveryPlan::MAIN_GROUP, '1', false)];
        foreach ($plan->getManualGroups() as $index => $groupId) {
            $groups[$groupId] = new DeliveryGroup($groupId, (string) ($index + 2), false);
        }

        $quantities = [];
        foreach ($roots as $rootId => $root) {
            $assigned = array_intersect_key($plan->getAssignments()[$rootId] ?? [], $groups);
            unset($assigned[DeliveryPlan::MAIN_GROUP]);

            if (!$root->isSplittable()) {
                // Containers such as bundles move as a whole, to the group holding most of them.
                arsort($assigned);
                $target = $assigned === [] ? DeliveryPlan::MAIN_GROUP : (string) array_key_first($assigned);
                $quantities[$rootId] = [$target => $root->getQuantity()];
                continue;
            }

            $moved = 0;
            foreach ($assigned as $groupId => $quantity) {
                $quantity = min($quantity, $root->getQuantity() - $moved);
                if ($quantity > 0) {
                    $quantities[$rootId][$groupId] = $quantity;
                    $moved += $quantity;
                }
            }
            if ($root->getQuantity() - $moved > 0) {
                $quantities[$rootId][DeliveryPlan::MAIN_GROUP] = $root->getQuantity() - $moved;
            }
        }

        return new Allocation($groups, $quantities);
    }
}
