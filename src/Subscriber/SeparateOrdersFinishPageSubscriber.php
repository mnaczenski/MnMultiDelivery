<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Mn\MultiDelivery\SeparateOrder\SeparateOrderCartOrderRoute;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Finish\CheckoutFinishPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Shows every order of a checkout with separate orders on the finish page,
 * not only the main order Shopware loads: page extension "mnSeparateOrders"
 * with "orders" (all orders, in placing order, the main order last) and
 * "others" (all but the page's order). Only orders of the same customer are
 * loaded.
 *
 * @internal
 */
class SeparateOrdersFinishPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION = 'mnSeparateOrders';

    /**
     * @param EntityRepository<OrderCollection> $orderRepository
     */
    public function __construct(private readonly EntityRepository $orderRepository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutFinishPageLoadedEvent::class => 'onFinishPageLoaded'];
    }

    public function onFinishPageLoaded(CheckoutFinishPageLoadedEvent $event): void
    {
        $page = $event->getPage();
        $order = $page->getOrder();
        $customerId = $event->getSalesChannelContext()->getCustomer()?->getId();
        $group = $order->getCustomFields()[SeparateOrderCartOrderRoute::CUSTOM_FIELD] ?? null;
        if ($customerId === null || !\is_array($group) || !\is_array($group['orders'] ?? null)) {
            return;
        }

        $ids = array_values(array_filter(
            array_map(static fn ($entry) => \is_array($entry) ? ($entry['id'] ?? null) : null, $group['orders']),
            static fn ($id) => \is_string($id) && $id !== $order->getId(),
        ));
        if ($ids === []) {
            return;
        }

        $criteria = (new Criteria($ids))
            ->addFilter(new EqualsFilter('orderCustomer.customerId', $customerId))
            ->addAssociations([
                'lineItems.cover',
                'deliveries.shippingMethod',
                'deliveries.shippingOrderAddress.salutation',
                'deliveries.shippingOrderAddress.country',
                'deliveries.shippingOrderAddress.countryState',
                'transactions.paymentMethod',
                'currency',
            ]);
        $criteria->getAssociation('transactions')->addSorting(new FieldSorting('createdAt'));
        $others = $this->orderRepository->search($criteria, $event->getContext())->getEntities();
        if ($others->count() === 0) {
            return;
        }

        /** @var list<OrderEntity> $orders */
        $orders = [];
        foreach ($group['orders'] as $entry) {
            $id = \is_array($entry) ? ($entry['id'] ?? null) : null;
            $found = $id === $order->getId() ? $order : (\is_string($id) ? $others->get($id) : null);
            if ($found !== null) {
                $orders[] = $found;
            }
        }

        $page->addExtension(self::EXTENSION, new ArrayStruct([
            'orders' => $orders,
            'others' => array_values($others->getElements()),
            'totalPrice' => round(array_sum(array_map(static fn (OrderEntity $o) => $o->getAmountTotal(), $orders)), 2),
        ]));
    }
}
