<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Decides how cart items are distributed over deliveries. Register an
 * implementation with the tag "mn.multi_delivery.strategy".
 *
 * Public API: stable within a major version.
 */
interface DeliverySplitStrategyInterface
{
    public function getName(): string;

    /** Whether customers may pick this strategy in the current context. */
    public function isAvailable(SalesChannelContext $context): bool;

    /** Whether customers create the groups themselves and may add or remove them. */
    public function allowsManualMoves(): bool;

    /**
     * Whether customers may move items between the groups this strategy
     * created (stored as adjustments in the DeliveryPlan).
     */
    public function allowsAdjustments(): bool;

    /**
     * @param array<string, RootItem> $roots keyed by root line item id
     */
    public function allocate(array $roots, DeliveryPlan $plan, SalesChannelContext $context): Allocation;
}
