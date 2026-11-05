<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Cart;

use Shopware\Core\Framework\Struct\Struct;

/**
 * The customer's delivery plan, stored as a cart extension so it survives
 * every recalculation and cart persistence.
 *
 * Public API: stable within a major version.
 */
class DeliveryPlan extends Struct
{
    public const EXTENSION = 'mnDeliveryPlan';

    public const MAIN_GROUP = 'main';

    /**
     * @param array<string, array{addressId?: ?string, shippingMethodId?: ?string}> $groups      settings per group id
     * @param array<string, array<string, int>>                                    $assignments root line item id => [group id => quantity]
     * @param list<string>                                                         $manualGroups group ids the customer created
     * @param array<string, array<string, array<string, int>>>                     $adjustments  strategy => root line item id => [group id => quantity]
     */
    public function __construct(
        protected bool $enabled = false,
        protected string $strategy = 'manual',
        protected array $groups = [],
        protected array $assignments = [],
        protected array $manualGroups = [],
        protected array $adjustments = [],
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }

    public function getStrategy(): string
    {
        return $this->strategy;
    }

    public function setStrategy(string $strategy): void
    {
        $this->strategy = $strategy;
    }

    /**
     * @return array{addressId?: ?string, shippingMethodId?: ?string}
     */
    public function getGroupSettings(string $groupId): array
    {
        return $this->groups[$groupId] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getConfiguredGroupIds(): array
    {
        return array_keys($this->groups);
    }

    public function setGroupSettings(string $groupId, ?string $addressId, ?string $shippingMethodId): void
    {
        $this->groups[$groupId] = ['addressId' => $addressId ?: null, 'shippingMethodId' => $shippingMethodId ?: null];
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getAssignments(): array
    {
        return $this->assignments;
    }

    /**
     * @param array<string, int> $quantities manual group id => quantity; the rest stays in the first delivery
     */
    public function setAssignments(string $rootLineItemId, array $quantities): void
    {
        $this->assignments[$rootLineItemId] = array_filter($quantities, static fn (int $quantity) => $quantity > 0);
    }

    /**
     * @return list<string>
     */
    public function getManualGroups(): array
    {
        return $this->manualGroups;
    }

    public function addManualGroup(): string
    {
        $next = \count($this->manualGroups) + 2;
        $id = 'delivery-' . $next;
        while (\in_array($id, $this->manualGroups, true)) {
            $id = 'delivery-' . ++$next;
        }
        $this->manualGroups[] = $id;

        return $id;
    }

    public function removeManualGroup(string $groupId): void
    {
        $this->manualGroups = array_values(array_filter($this->manualGroups, static fn (string $id) => $id !== $groupId));
        unset($this->groups[$groupId]);
        foreach ($this->assignments as $lineItemId => $groups) {
            unset($groups[$groupId]);
            $this->assignments[$lineItemId] = $groups;
        }
    }

    /**
     * The customer's corrections of an automatic split.
     *
     * @return array<string, array<string, int>> root line item id => [group id => quantity]
     */
    public function getAdjustments(string $strategy): array
    {
        return $this->adjustments[$strategy] ?? [];
    }

    /**
     * @param array<string, int> $quantities group id => quantity
     */
    public function setAdjustment(string $strategy, string $rootLineItemId, array $quantities): void
    {
        $this->adjustments[$strategy][$rootLineItemId] = array_filter($quantities, static fn (int $quantity) => $quantity > 0);
    }

    public function resetAdjustments(string $strategy): void
    {
        unset($this->adjustments[$strategy]);
    }

    public function getApiAlias(): string
    {
        return 'mn_delivery_plan';
    }
}
