<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Route;

use Mn\MultiDelivery\Event\DeliveryPlanChangedEvent;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Shopware\Core\Checkout\Cart\SalesChannel\CartResponse;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Routing\RoutingException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Store API for headless clients. Same operations as the storefront planner;
 * every change returns the recalculated cart (deliveries included).
 *
 * Built-in implementation: replace or decorate it through its interface or service id; the class itself may change.
 */
#[Route(defaults: ['_routeScope' => ['store-api'], '_loginRequired' => true, '_loginRequiredAllowGuest' => true])]
class DeliveryPlanRoute extends AbstractDeliveryPlanRoute
{
    public function __construct(
        private readonly DeliveryPlanService $planService,
        private readonly CartService $cartService,
        private readonly AbstractListAddressRoute $listAddressRoute,
        private readonly DeliveryAddressPolicies $addressPolicies,
    ) {
    }

    public function getDecorated(): AbstractDeliveryPlanRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(path: '/store-api/checkout/multi-delivery', name: 'store-api.mn.multi-delivery', methods: ['GET'])]
    public function load(Request $request, SalesChannelContext $context): DeliveryPlanRouteResponse
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $data = $this->planService->describe($cart, $context);

        $customer = $context->getCustomer();
        $data['addressIds'] = $customer === null ? [] : array_values($this->listAddressRoute
            ->load(new Criteria(), $context, $customer)
            ->getAddressCollection()
            ->filter(fn ($address) => $this->addressPolicies->isAllowed($address, $context))
            ->getIds());

        return new DeliveryPlanRouteResponse($data);
    }

    #[Route(path: '/store-api/checkout/multi-delivery/{action}', name: 'store-api.mn.multi-delivery.change', requirements: ['action' => 'toggle|strategy|group|add-group|remove-group|move|reset'], methods: ['POST'])]
    public function change(string $action, Request $request, SalesChannelContext $context): CartResponse
    {
        $data = $request->request;
        $optional = static fn (string $key): ?string => $data->get($key) !== null ? (string) $data->get($key) : null;

        $cart = match ($action) {
            DeliveryPlanChangedEvent::ACTION_TOGGLE => $this->planService->toggle($context, $data->getBoolean('enabled')),
            DeliveryPlanChangedEvent::ACTION_STRATEGY => $this->planService->setStrategy($context, (string) $data->get('strategy')),
            DeliveryPlanChangedEvent::ACTION_GROUP => $this->planService->setGroup($context, (string) $data->get('groupId'), $optional('addressId'), $optional('shippingMethodId')),
            DeliveryPlanChangedEvent::ACTION_ADD_GROUP => $this->planService->addGroup($context),
            DeliveryPlanChangedEvent::ACTION_REMOVE_GROUP => $this->planService->removeGroup($context, (string) $data->get('groupId')),
            DeliveryPlanChangedEvent::ACTION_MOVE => $this->planService->move(
                $context,
                (string) $data->get('lineItemId'),
                (string) $data->get('fromGroupId'),
                (string) $data->get('toGroupId'),
                $data->getInt('quantity'),
            ),
            DeliveryPlanChangedEvent::ACTION_RESET => $this->planService->reset($context),
            default => throw RoutingException::invalidRequestParameter('action'),
        };

        return new CartResponse($cart);
    }
}
