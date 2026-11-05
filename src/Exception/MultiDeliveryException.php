<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Exception;

use Shopware\Core\Checkout\Cart\CartException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extends CartException, so the storefront shows it on the confirm page as
 * the snippet "error.<code>", like Shopware's own checkout errors.
 *
 * Public API: stable within a major version.
 */
class MultiDeliveryException extends CartException
{
    public const SEPARATE_ORDER_PAYMENT = 'MN_MULTI_DELIVERY__SEPARATE_ORDER_PAYMENT';
    public const SEPARATE_ORDER_INVALID = 'MN_MULTI_DELIVERY__SEPARATE_ORDER_INVALID';

    public static function separateOrderPaymentNotAllowed(string $paymentMethod): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::SEPARATE_ORDER_PAYMENT,
            'Deliveries to other countries are placed as separate orders, which is not possible with the payment method "{{ paymentMethod }}".',
            ['paymentMethod' => $paymentMethod],
        );
    }

    /**
     * @param list<string> $errors keys of the cart errors that block the order
     */
    public static function separateOrderInvalid(string $country, array $errors): self
    {
        return new self(
            Response::HTTP_BAD_REQUEST,
            self::SEPARATE_ORDER_INVALID,
            'The separate order to {{ country }} can not be placed: {{ errors }}',
            ['country' => $country, 'errors' => implode(', ', $errors)],
        );
    }
}
