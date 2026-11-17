<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Cart\Error;

use Shopware\Core\Checkout\Cart\Error\Error;

/**
 * The shipping method chosen for a delivery is no longer available (inactive, not assigned to the sales
 * channel, or deleted), so the delivery uses the main delivery's shipping method instead. A warning that
 * doesn't block the order, like Shopware's ShippingMethodChangedError when it switches the shipping method.
 * The storefront shows it as the snippet "checkout.<message key>".
 *
 * Public API: stable within a major version.
 */
class DeliveryShippingMethodChangedError extends Error
{
    public const KEY = 'mn-multi-delivery-shipping-method-changed';

    public function __construct(
        private readonly string $groupId,
        private readonly int $deliveryNumber,
        private readonly string $newShippingMethodName,
    ) {
        parent::__construct(\sprintf('The shipping method chosen for delivery %d is not available; it ships with %s instead.', $deliveryNumber, $newShippingMethodName));
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
        return self::LEVEL_WARNING;
    }

    public function blockOrder(): bool
    {
        return false;
    }

    /**
     * @return array{delivery: int, groupId: string, newShippingMethodName: string}
     */
    public function getParameters(): array
    {
        return ['delivery' => $this->deliveryNumber, 'groupId' => $this->groupId, 'newShippingMethodName' => $this->newShippingMethodName];
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }
}
