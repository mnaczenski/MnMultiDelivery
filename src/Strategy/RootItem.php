<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Strategy;

use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;

/**
 * A top-level cart line item with the delivery positions it produced.
 * Bundles and other containers produce one position per child and are
 * never split; simple products may be split by quantity.
 *
 * Public API: stable within a major version.
 */
final class RootItem
{
    /**
     * @param list<DeliveryPosition> $positions
     */
    public function __construct(
        public readonly LineItem $lineItem,
        public readonly array $positions,
    ) {
    }

    public function getId(): string
    {
        return $this->lineItem->getId();
    }

    public function getQuantity(): int
    {
        return $this->lineItem->getQuantity();
    }

    public function isSplittable(): bool
    {
        return \count($this->positions) === 1 && $this->positions[0]->getIdentifier() === $this->lineItem->getId();
    }
}
