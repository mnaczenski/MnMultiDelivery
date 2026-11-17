<?php declare(strict_types=1);

namespace Mn\MultiDelivery\SeparateOrder;

use Mn\MultiDelivery\Event\SeparateOrdersPlacedEvent;
use Mn\MultiDelivery\Exception\MultiDeliveryException;
use Mn\MultiDelivery\Service\SplitSettings;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartContextHasher;
use Shopware\Core\Checkout\Cart\CartException;
use Shopware\Core\Checkout\Cart\SalesChannel\AbstractCartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRouteResponse;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\OrderDefinition;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Places deliveries to other countries as separate orders (setting
 * countryMode = separateOrders). Each separate order goes through Shopware's
 * normal order route with a context for its own address, so taxes, prices,
 * shipping rules, mails and flows are Shopware's own. The main order is
 * placed last and returned, so the storefront's payment step and finish page
 * work with it.
 *
 * Before anything is placed, every order is calculated; if one of them, the
 * main order included, has an error that blocks ordering, nothing is placed.
 * If placing still fails halfway, the orders placed so far are cancelled,
 * together with their open payments. Once every order is placed, the checkout
 * succeeds: a failure while linking the orders or in a listener of
 * SeparateOrdersPlacedEvent is logged, never thrown, because the customer
 * would otherwise retry and place everything again.
 *
 * Carts without a delivery to another country go to Shopware unchanged.
 *
 * @internal
 */
class SeparateOrderCartOrderRoute extends AbstractCartOrderRoute
{
    public const CUSTOM_FIELD = 'mn_multi_delivery_orders';

    /**
     * @param EntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository
     */
    public function __construct(
        private readonly AbstractCartOrderRoute $decorated,
        private readonly SeparateOrderCalculator $calculator,
        private readonly SplitSettings $settings,
        private readonly CartContextHasher $hasher,
        private readonly AbstractCartPersister $cartPersister,
        private readonly EntityRepository $orderRepository,
        private readonly StateMachineRegistry $stateMachineRegistry,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getDecorated(): AbstractCartOrderRoute
    {
        return $this->decorated;
    }

    public function order(Cart $cart, SalesChannelContext $context, RequestDataBag $data): CartOrderRouteResponse
    {
        $calculated = $this->calculator->calculate($cart, $context);
        if ($calculated === null) {
            return $this->decorated->order($cart, $context, $data);
        }

        // The customer confirmed the whole cart; check that hash before it is divided.
        $hash = $data->getAlnum('hash');
        if ($hash && !$this->hasher->isMatching($hash, $cart, $context)) {
            throw CartException::hashMismatch($cart->getToken());
        }
        if (!$this->settings->separateOrderPaymentAllowed($context)) {
            throw MultiDeliveryException::separateOrderPaymentNotAllowed((string) $context->getPaymentMethod()->getTranslation('name'));
        }

        $split = $calculated['split'];
        foreach ($split->parts as $part) {
            $blocking = [];
            foreach ($part->cart->getErrors()->getErrors() as $error) {
                if ($error->blockOrder()) {
                    $blocking[] = $error->getMessageKey();
                }
            }
            if ($blocking !== []) {
                throw MultiDeliveryException::separateOrderInvalid((string) $part->address->getCountry()?->getTranslation('name'), $blocking);
            }
        }

        // Shopware would refuse the main order only after the separate orders are out (with their mails).
        if ($split->hasMainOrder() && $split->mainCart->getErrors()->blockOrder()) {
            throw CartException::invalidCart($split->mainCart->getErrors());
        }

        $partData = new RequestDataBag($data->all());
        $partData->remove('hash');

        /** @var list<OrderEntity> $orders */
        $orders = [];
        try {
            foreach ($split->parts as $part) {
                $orders[] = $this->decorated->order($part->cart, $calculated['contexts'][$part->groupId], $partData)->getOrder();
            }

            if ($split->hasMainOrder()) {
                try {
                    $response = $this->decorated->order($split->mainCart, $context, $partData);
                } finally {
                    // The main cart has a throwaway token; Shopware deleted the customer's cart itself.
                    $this->calculator->discard($split->mainCart, $context, $context->getToken());
                }
                $orders[] = $response->getOrder();
            } else {
                // Everything goes abroad: the last separate order takes the main order's place.
                $this->cartPersister->delete($context->getToken(), $context);
                $response = new CartOrderRouteResponse($orders[\count($orders) - 1]);
            }
        } catch (\Throwable $e) {
            foreach ($split->parts as $part) {
                $this->calculator->discard($part->cart, $calculated['contexts'][$part->groupId], $context->getToken());
            }
            $this->cancel($orders, $context);

            throw $e;
        }

        // Every order is placed: from here on nothing may fail the checkout.
        $groupId = Uuid::randomHex();
        $this->linkSafely($groupId, $orders, $context);
        try {
            $this->dispatcher->dispatch(new SeparateOrdersPlacedEvent($groupId, $orders, $context));
        } catch (\Throwable $e) {
            $this->logger->error('Multi Delivery: a listener of SeparateOrdersPlacedEvent failed for orders {orderNumbers}: {message}', [
                'orderNumbers' => $this->orderNumbers($orders),
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);
        }

        return $response;
    }

    /**
     * Links the orders, with one retry. If that fails too, the orders stand unlinked and the error is logged.
     *
     * @param list<OrderEntity> $orders
     */
    private function linkSafely(string $groupId, array $orders, SalesChannelContext $context): void
    {
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $this->link($groupId, $orders, $context);

                return;
            } catch (\Throwable $e) {
                if ($attempt === 2) {
                    $this->logger->error('Multi Delivery could not link the separate orders {orderNumbers} (group {group}): {message}', [
                        'orderNumbers' => $this->orderNumbers($orders),
                        'group' => $groupId,
                        'message' => $e->getMessage(),
                        'exception' => $e,
                    ]);
                }
            }
        }
    }

    /**
     * @param list<OrderEntity> $orders
     */
    private function orderNumbers(array $orders): string
    {
        return implode(', ', array_map(static fn (OrderEntity $order) => (string) $order->getOrderNumber(), $orders));
    }

    /**
     * Writes the list of all orders of the checkout into each order's custom
     * field mn_multi_delivery_orders.
     *
     * @param list<OrderEntity> $orders
     */
    private function link(string $groupId, array $orders, SalesChannelContext $context): void
    {
        $list = [];
        foreach ($orders as $order) {
            $delivery = $order->getPrimaryOrderDelivery() ?? $order->getDeliveries()?->first();
            $country = $delivery?->getShippingOrderAddress()?->getCountry();
            $list[] = [
                'id' => $order->getId(),
                'orderNumber' => $order->getOrderNumber(),
                'countryIso' => $country?->getIso(),
                'country' => $country?->getTranslation('name'),
                'amountTotal' => $order->getAmountTotal(),
                'currencyIso' => $order->getCurrency()?->getIsoCode(),
            ];
        }

        $payload = [];
        foreach ($orders as $order) {
            $customFields = $order->getCustomFields() ?? [];
            $customFields[self::CUSTOM_FIELD] = ['group' => $groupId, 'orders' => $list];
            $order->setCustomFields($customFields);
            $payload[] = ['id' => $order->getId(), 'customFields' => [self::CUSTOM_FIELD => $customFields[self::CUSTOM_FIELD]]];
        }
        $this->orderRepository->update($payload, $context->getContext());
    }

    /**
     * @param list<OrderEntity> $orders
     */
    private function cancel(array $orders, SalesChannelContext $context): void
    {
        foreach ($orders as $order) {
            try {
                $this->stateMachineRegistry->transition(
                    new Transition(OrderDefinition::ENTITY_NAME, $order->getId(), 'cancel', 'stateId', 'Multi Delivery: another order of the same checkout failed'),
                    $context->getContext(),
                );
            } catch (\Throwable $e) {
                $this->logger->error('Multi Delivery could not cancel separate order {orderNumber}: {message}', [
                    'orderNumber' => $order->getOrderNumber(),
                    'message' => $e->getMessage(),
                ]);
            }
            // Its payments too: only offline payment methods are allowed here, so they are still open.
            foreach ($order->getTransactions() ?? [] as $transaction) {
                try {
                    $this->stateMachineRegistry->transition(
                        new Transition(OrderTransactionDefinition::ENTITY_NAME, $transaction->getId(), 'cancel', 'stateId', 'Multi Delivery: another order of the same checkout failed'),
                        $context->getContext(),
                    );
                } catch (\Throwable $e) {
                    $this->logger->error('Multi Delivery could not cancel the payment of separate order {orderNumber}: {message}', [
                        'orderNumber' => $order->getOrderNumber(),
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
