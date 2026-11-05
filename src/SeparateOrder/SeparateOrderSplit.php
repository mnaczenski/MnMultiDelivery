<?php declare(strict_types=1);

namespace Mn\MultiDelivery\SeparateOrder;

use Shopware\Core\Checkout\Cart\Cart;

/**
 * A cart divided into the main order (everything that stays in the shipping
 * country) and one separate order per delivery to another country.
 *
 * Public API: stable within a major version.
 */
final class SeparateOrderSplit
{
    /**
     * @param list<SeparateOrderPart> $parts
     */
    public function __construct(
        public readonly Cart $mainCart,
        public readonly array $parts,
    ) {
    }

    public function hasMainOrder(): bool
    {
        return $this->mainCart->getLineItems()->count() > 0;
    }

    /**
     * @param list<SeparateOrderPart> $parts
     */
    public function with(Cart $mainCart, array $parts): self
    {
        return new self($mainCart, $parts);
    }
}
