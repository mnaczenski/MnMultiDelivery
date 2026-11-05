<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Controller;

use Mn\MultiDelivery\Service\DeliveryPlanService;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function __construct(
        private readonly DeliveryPlanService $planService,
        private readonly CheckoutConfirmPageLoader $confirmPageLoader,
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

    private function respond(Request $request, SalesChannelContext $context, Cart $cart): Response
    {
        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse($this->generateUrl('frontend.checkout.confirm.page') . '#mn-multi-delivery');
        }

        // Situations the confirm page itself redirects away from: let the browser follow.
        if ($context->getCustomer() === null || $cart->getLineItems()->count() === 0) {
            return $this->renderStorefront('@MnMultiDelivery/storefront/component/mn-multi-delivery/planner-partial.html.twig', [
                'redirect' => $this->generateUrl('frontend.checkout.confirm.page'),
            ]);
        }

        return $this->renderStorefront('@MnMultiDelivery/storefront/component/mn-multi-delivery/planner-partial.html.twig', [
            'page' => $this->confirmPageLoader->load($request, $context),
        ]);
    }
}
