<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Mn\MultiDelivery\Service\SplitGuard;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives the confirm page what the delivery planner needs (see
 * DeliveryPlanService::describe()) plus the customer's addresses that the
 * address policies allow (by default: those in the shipping country).
 *
 * @internal
 */
class ConfirmPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION = 'mnMultiDelivery';

    public function __construct(
        private readonly SplitGuard $guard,
        private readonly DeliveryPlanService $planService,
        private readonly AbstractListAddressRoute $listAddressRoute,
        private readonly DeliveryAddressPolicies $addressPolicies,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutConfirmPageLoadedEvent::class => 'onConfirmPageLoaded'];
    }

    public function onConfirmPageLoaded(CheckoutConfirmPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $customer = $context->getCustomer();
        if ($customer === null || !$this->guard->isActive($context)) {
            return;
        }

        $page = $event->getPage();

        $criteria = (new Criteria())->addAssociations(['country', 'countryState', 'salutation']);
        $addresses = $this->listAddressRoute->load($criteria, $context, $customer)->getAddressCollection()
            ->filter(fn ($address) => $this->addressPolicies->isAllowed($address, $context));

        $page->addExtension(self::EXTENSION, new ArrayStruct([
            ...$this->planService->describe($page->getCart(), $context),
            'addresses' => $addresses,
        ]));
    }
}
