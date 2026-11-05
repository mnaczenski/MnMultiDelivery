<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

/**
 * One delivery a strategy wants to create.
 *
 * Public API: stable within a major version.
 */
final class DeliveryGroup
{
    /**
     * @param string  $id            stable id, used for the customer's address and shipping method choice per delivery
     * @param string  $label         short label stored on the order delivery (customFields.mn_multi_delivery.label)
     * @param bool    $locked        informational: whether the group comes from the rule rather than the customer (DeliveryGroupInfo::isLocked())
     * @param bool    $inStockDates  date partial quantities by the normal delivery time instead of the restock date
     * @param ?string $warehouseId   source warehouse, shown to customers and stored on the order delivery
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label = '',
        public readonly bool $locked = true,
        public readonly bool $inStockDates = false,
        public readonly ?string $warehouseId = null,
        public readonly ?string $warehouseName = null,
    ) {
        if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('Invalid delivery group id "%s": use 1-64 letters, digits or dashes.', $id));
        }
    }

    public function withInStockDates(bool $inStockDates): self
    {
        return new self($this->id, $this->label, $this->locked, $inStockDates, $this->warehouseId, $this->warehouseName);
    }
}
