<?php declare(strict_types=1);

namespace Mn\MultiDelivery\SeparateOrder;

use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * The customer's context with another shipping address: same customer,
 * billing address, payment method, language and currency, so Shopware
 * calculates taxes, prices and shipping rules for that address's country.
 *
 * It starts from the stored context of the current token, so data other
 * extensions keep there (for example Commercial B2B's employee) carries over.
 * It gets a token of its own and is never stored.
 *
 * @internal
 */
class SeparateOrderContextFactory
{
    public function __construct(
        private readonly AbstractSalesChannelContextFactory $factory,
        private readonly SalesChannelContextPersister $persister,
    ) {
    }

    public function create(SalesChannelContext $context, SeparateOrderPart $part): SalesChannelContext
    {
        $customer = $context->getCustomer();
        $options = $this->persister->load($context->getToken(), $context->getSalesChannelId(), $customer?->getId());
        unset(
            $options['expired'],
            $options['token'],
            $options[SalesChannelContextService::SHIPPING_ADDRESS],
            $options[SalesChannelContextService::RULE_IDS],
            $options[SalesChannelContextService::AREA_RULE_IDS],
        );

        $options = [
            ...$options,
            SalesChannelContextService::CUSTOMER_ID => $customer?->getId(),
            SalesChannelContextService::BILLING_ADDRESS_ID => $customer?->getActiveBillingAddress()?->getId(),
            SalesChannelContextService::SHIPPING_ADDRESS_ID => $part->address->getId(),
            SalesChannelContextService::COUNTRY_ID => $part->address->getCountryId(),
            SalesChannelContextService::COUNTRY_STATE_ID => $part->address->getCountryStateId(),
            SalesChannelContextService::PAYMENT_METHOD_ID => $context->getPaymentMethod()->getId(),
            SalesChannelContextService::SHIPPING_METHOD_ID => $part->shippingMethodId ?? $context->getShippingMethod()->getId(),
            SalesChannelContextService::LANGUAGE_ID => $context->getLanguageId(),
            SalesChannelContextService::CURRENCY_ID => $context->getCurrencyId(),
            SalesChannelContextService::DOMAIN_ID => $context->getDomainId(),
        ];

        return $this->factory->create(Uuid::randomHex(), $context->getSalesChannelId(), array_filter($options, static fn ($value) => $value !== null));
    }
}
