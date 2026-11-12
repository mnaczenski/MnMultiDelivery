<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Controller;

use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractUpsertAddressRoute;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Validation\Exception\ConstraintViolationException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Form endpoints of the delivery planner on the confirm page.
 *
 * Plain form posts redirect back to the confirm page. Background requests
 * from the storefront plugin (X-Requested-With) get a small HTML response
 * with only the parts of the page that depend on the plan.
 *
 * @internal
 */
#[Route(defaults: ['_routeScope' => ['storefront'], 'XmlHttpRequest' => true])]
class DeliveryPlanController extends StorefrontController
{
    /** Error snippet error.MN_MULTI_DELIVERY__COUNTRY_NOT_ALLOWED */
    public const COUNTRY_NOT_ALLOWED = 'MN_MULTI_DELIVERY__COUNTRY_NOT_ALLOWED';

    public function __construct(
        private readonly DeliveryPlanService $planService,
        private readonly CheckoutConfirmPageLoader $confirmPageLoader,
        private readonly ?AbstractUpsertAddressRoute $upsertAddressRoute = null,
        private readonly ?DeliveryAddressPolicies $addressPolicies = null,
    ) {
    }

    #[Route(path: '/checkout/multi-delivery/toggle', name: 'frontend.mn.multi-delivery.toggle', methods: ['POST'])]
    public function toggle(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->toggle($context, $request->request->getBoolean('enabled')));
    }

    #[Route(path: '/checkout/multi-delivery/strategy', name: 'frontend.mn.multi-delivery.strategy', methods: ['POST'])]
    public function strategy(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->setStrategy($context, (string) $request->request->get('strategy')));
    }

    #[Route(path: '/checkout/multi-delivery/group', name: 'frontend.mn.multi-delivery.group', methods: ['POST'])]
    public function group(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->setGroup(
            $context,
            (string) $request->request->get('groupId'),
            $request->request->get('addressId') !== null ? (string) $request->request->get('addressId') : null,
            $request->request->get('shippingMethodId') !== null ? (string) $request->request->get('shippingMethodId') : null,
        ));
    }

    /**
     * Creates an address in the customer's address book (Shopware's own
     * validation, guests included) and sends the delivery there. Invalid
     * input shows the form again with the errors.
     */
    #[Route(path: '/checkout/multi-delivery/address', name: 'frontend.mn.multi-delivery.address', methods: ['POST'])]
    public function newAddress(Request $request, RequestDataBag $data, SalesChannelContext $context): Response
    {
        $customer = $context->getCustomer();
        $groupId = (string) $request->request->get('groupId');
        $address = $data->get('address');
        if ($customer === null || $this->upsertAddressRoute === null || !$address instanceof RequestDataBag) {
            return $this->respond($request, $context, null);
        }
        $address->remove('id');

        // Only countries the form offers: an address the delivery can't use isn't added to the address book.
        $countryId = (string) $address->get('countryId');
        if ($countryId !== '' && $this->addressPolicies !== null && !$this->addressPolicies->isCountryAllowed($countryId, $context)) {
            $violation = new ConstraintViolation('', '', [], '', '/countryId', $countryId, null, self::COUNTRY_NOT_ALLOWED);

            return $this->showAddressErrors($request, $context, $groupId, $address, new ConstraintViolationException(new ConstraintViolationList([$violation]), $address->all()));
        }

        try {
            $created = $this->upsertAddressRoute->upsert(null, $address, $context, $customer)->getAddress();
        } catch (ConstraintViolationException $violations) {
            return $this->showAddressErrors($request, $context, $groupId, $address, $violations);
        }

        return $this->respond($request, $context, $this->planService->setGroupAddress($context, $groupId, $created->getId()));
    }

    #[Route(path: '/checkout/multi-delivery/add-group', name: 'frontend.mn.multi-delivery.add-group', methods: ['POST'])]
    public function addGroup(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->addGroup($context));
    }

    #[Route(path: '/checkout/multi-delivery/remove-group', name: 'frontend.mn.multi-delivery.remove-group', methods: ['POST'])]
    public function removeGroup(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->removeGroup($context, (string) $request->request->get('groupId')));
    }

    #[Route(path: '/checkout/multi-delivery/assign', name: 'frontend.mn.multi-delivery.assign', methods: ['POST'])]
    public function assign(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->move(
            $context,
            (string) $request->request->get('lineItemId'),
            (string) $request->request->get('fromGroupId'),
            (string) $request->request->get('groupId'),
            $request->request->getInt('quantity'),
        ));
    }

    #[Route(path: '/checkout/multi-delivery/reset', name: 'frontend.mn.multi-delivery.reset', methods: ['POST'])]
    public function reset(Request $request, SalesChannelContext $context): Response
    {
        return $this->respond($request, $context, $this->planService->reset($context));
    }

    private function showAddressErrors(Request $request, SalesChannelContext $context, string $groupId, RequestDataBag $address, ConstraintViolationException $violations): Response
    {
        $form = ['groupId' => $groupId, 'data' => $address];
        if (!$request->isXmlHttpRequest()) {
            return $this->forwardToRoute('frontend.checkout.confirm.page', ['formViolations' => $violations, 'mnNewAddress' => $form]);
        }
        $request->attributes->set('formViolations', $violations);
        $request->attributes->set('mnNewAddress', $form);

        return $this->respond($request, $context, null);
    }

    private function respond(Request $request, SalesChannelContext $context, ?Cart $cart): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page') . '#mn-multi-delivery');
        }

        // Situations the confirm page itself redirects away from: let the browser follow.
        if ($context->getCustomer() === null || $cart?->getLineItems()->count() === 0) {
            return $this->renderStorefront('@MnMultiDelivery/storefront/component/mn-multi-delivery/planner-partial.html.twig', [
                'redirect' => $this->generateUrl('frontend.checkout.confirm.page'),
            ]);
        }

        return $this->renderStorefront('@MnMultiDelivery/storefront/component/mn-multi-delivery/planner-partial.html.twig', [
            'page' => $this->confirmPageLoader->load($request, $context),
        ]);
    }
}
