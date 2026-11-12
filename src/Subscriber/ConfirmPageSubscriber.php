<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Mn\MultiDelivery\Service\SplitGuard;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalesChannel\AbstractSalutationRoute;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gives the confirm page what the delivery planner needs (see
 * DeliveryPlanService::describe()) plus the customer's addresses that the
 * address policies allow (by default: those in the shipping country).
 *
 * While the cart is split, also what the planner's "new address" form needs:
 * the salutations and the countries an address may be in (a country is offered
 * when the address policies allow an address there).
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
        private readonly ?AbstractCountryRoute $countryRoute = null,
        private readonly ?AbstractSalutationRoute $salutationRoute = null,
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

        $described = $this->planService->describe($page->getCart(), $context);
        $formNeeded = $described['plan']->isEnabled() && $described['blockReason'] === null;

        $page->addExtension(self::EXTENSION, new ArrayStruct([
            ...$described,
            'addresses' => $addresses,
            'addressForm' => $formNeeded ? $this->addressForm($event->getRequest(), $context) : null,
        ]));
    }

    /**
     * @return array{countries: CountryCollection, salutations: SalutationCollection, data: ArrayStruct<array{}>}|null
     */
    private function addressForm(Request $request, SalesChannelContext $context): ?array
    {
        if ($this->countryRoute === null || $this->salutationRoute === null) {
            return null;
        }

        $criteria = (new Criteria())->addFilter(new EqualsFilter('active', true))
            ->addSorting(new FieldSorting('position'), new FieldSorting('name'));
        $countries = $this->countryRoute->load($request, $criteria, $context)->getCountries()
            ->filter(fn ($country): bool => $this->addressPolicies->isCountryAllowed($country->getId(), $context, $country));
        if ($countries->count() === 0) {
            return null;
        }

        return [
            'countries' => $countries,
            'salutations' => $this->salutationRoute->load($request, $context, new Criteria())->getSalutations(),
            'data' => new ArrayStruct([]),
        ];
    }
}
