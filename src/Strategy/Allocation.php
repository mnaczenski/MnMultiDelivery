<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

/**
 * Result of a split strategy: the delivery groups, in display order, and
 * which quantity of each top-level cart item goes into which group.
 * Groups without items are not created.
 *
 * Public API: stable within a major version.
 */
final class Allocation
{
    /** @var array<string, DeliveryGroup> */
    public readonly array $groups;

    /**
     * @param iterable<DeliveryGroup>           $groups
     * @param array<string, array<string, int>> $quantities root line item id => [group id => quantity]
     */
    public function __construct(iterable $groups, public readonly array $quantities)
    {
        $byId = [];
        foreach ($groups as $group) {
            $byId[$group->id] = $group;
        }
        foreach ($quantities as $rootId => $perGroup) {
            foreach (array_keys($perGroup) as $groupId) {
                if (!isset($byId[$groupId])) {
                    throw new \InvalidArgumentException(\sprintf('Item "%s" is allocated to unknown delivery group "%s".', $rootId, $groupId));
                }
            }
        }
        $this->groups = $byId;
    }

    public function getGroup(string $id): ?DeliveryGroup
    {
        return $this->groups[$id] ?? null;
    }

    public function withGroup(DeliveryGroup $group): self
    {
        return new self([...$this->groups, $group->id => $group], $this->quantities);
    }

    /**
     * @param array<string, int> $quantities group id => quantity
     */
    public function withQuantities(string $rootId, array $quantities): self
    {
        return new self($this->groups, [...$this->quantities, $rootId => $quantities]);
    }
}
