<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * The address chosen for a delivery can't be used: it was deleted, doesn't belong to the customer,
 * has no country, or the address policies reject it (e.g. another country without separate orders).
 * Blocks the order until the customer picks another address, like Shopware's ShippingAddressBlockedError
 * does for the order's shipping address. The storefront shows it as the snippet "checkout.<message key>".
 *
 * Public API: stable within a major version.
 */
class DeliveryAddressBlockedError extends Error
{
    public const KEY = 'mn-multi-delivery-address-blocked';

    public function __construct(
        private readonly string $groupId,
        private readonly int $deliveryNumber,
        private readonly ?string $addressId,
    ) {
        parent::__construct(\sprintf('The address chosen for delivery %d can not be used.', $deliveryNumber));
    }

    public function getId(): string
    {
        return self::KEY . '-' . $this->groupId;
    }

    public function getMessageKey(): string
    {
        return self::KEY;
    }

    public function getLevel(): int
    {
        return self::LEVEL_ERROR;
    }

    public function blockOrder(): bool
    {
        return true;
    }

    /**
     * @return array{delivery: int, groupId: string, addressId: ?string}
     */
    public function getParameters(): array
    {
        return ['delivery' => $this->deliveryNumber, 'groupId' => $this->groupId, 'addressId' => $this->addressId];
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }
}
