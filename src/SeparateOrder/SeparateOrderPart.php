<?php declare(strict_types=1);

namespace Mn\MultiDelivery\SeparateOrder;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;

/**
 * One delivery to another country, turned into a cart of its own that is
 * placed as a separate order.
 *
 * Public API: stable within a major version.
 */
final class SeparateOrderPart
{
    public function __construct(
        public readonly string $groupId,
        public readonly string $label,
        public readonly CustomerAddressEntity $address,
        public readonly ?string $shippingMethodId,
        public readonly Cart $cart,
    ) {
    }

    public function withCart(Cart $cart): self
    {
        return new self($this->groupId, $this->label, $this->address, $this->shippingMethodId, $cart);
    }
}
