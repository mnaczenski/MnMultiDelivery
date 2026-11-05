<?php declare(strict_types=1);

namespace Mn\MultiDeliveryExample\Strategy;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Strategy\Allocation;
use Mn\MultiDelivery\Strategy\DeliveryGroup;
use Mn\MultiDelivery\Strategy\DeliverySplitStrategyInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Splitting rule "By size": items above a weight ship by freight, the rest by
 * parcel. Customers can still move items between the two deliveries.
 */
class BulkyGoodsStrategy implements DeliverySplitStrategyInterface
{
    public const NAME = 'bulky';
    public const FREIGHT = 'freight';
    public const PARCEL = 'parcel';

    public function __construct(private readonly float $freightFromKg = 30.0)
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
        $quantities = [];
        foreach ($roots as $id => $root) {
            $weight = $root->lineItem->getDeliveryInformation()?->getWeight() ?? 0.0;
            $quantities[$id] = [$weight >= $this->freightFromKg ? self::FREIGHT : self::PARCEL => $root->getQuantity()];
        }

        return new Allocation([new DeliveryGroup(self::PARCEL, 'parcel'), new DeliveryGroup(self::FREIGHT, 'freight')], $quantities);
    }
}
