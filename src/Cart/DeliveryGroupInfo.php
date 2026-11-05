<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Cart;

use Shopware\Core\Framework\Struct\Struct;

/**
 * Attached to each split cart delivery: which group it is, where it ships
 * from and whether the customer may move items into or out of it.
 *
 * Public API: stable within a major version.
 */
class DeliveryGroupInfo extends Struct
{
    public const EXTENSION = 'mnDeliveryGroup';

    public function __construct(
        protected string $groupId,
        protected string $label,
        protected bool $locked = false,
        protected ?string $warehouseId = null,
        protected ?string $warehouseName = null,
    ) {
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isLocked(): bool
    {
        return $this->locked;
    }

    public function getWarehouseId(): ?string
    {
        return $this->warehouseId;
    }

    public function getWarehouseName(): ?string
    {
        return $this->warehouseName;
    }

    public function getApiAlias(): string
    {
        return 'mn_delivery_group';
    }
}
