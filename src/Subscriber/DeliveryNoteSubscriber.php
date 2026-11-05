<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Shopware\Core\Checkout\Document\Event\DeliveryNoteOrdersEvent;
use Shopware\Core\Checkout\Document\Event\DocumentOrderCriteriaEvent;
use Shopware\Core\Checkout\Document\Renderer\DeliveryNoteRenderer;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\Aggregate\OrderDelivery\OrderDeliveryCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * One delivery note per delivery: pass "mnOrderDeliveryId" in the document
 * config and the core delivery note renderer sees an order that only holds
 * that delivery (its address) and that delivery's items and quantities.
 *
 * Both events are marked for replacement in v6.9 (DOCUMENT_GENERATION_REWORK);
 * port to AbstractDocumentDataProvider when that flag becomes the default.
 *
 * @internal
 */
class DeliveryNoteSubscriber implements EventSubscriberInterface
{
    public const CONFIG_KEY = 'mnOrderDeliveryId';

    public static function getSubscribedEvents(): array
    {
        return [
            DocumentOrderCriteriaEvent::class => 'onCriteria',
            DeliveryNoteOrdersEvent::class => 'onOrders',
        ];
    }

    public function onCriteria(DocumentOrderCriteriaEvent $event): void
    {
        if ($event->getName() !== DeliveryNoteRenderer::TYPE . '.document.criteria' || !$this->hasDeliveryConfig($event->getOperations())) {
            return;
        }

        $event->getCriteria()->addAssociations([
            'deliveries.positions',
            'deliveries.shippingOrderAddress.country',
            'deliveries.shippingOrderAddress.countryState',
            'deliveries.shippingMethod',
        ]);
    }

    public function onOrders(DeliveryNoteOrdersEvent $event): void
    {
        $operations = $event->getOperations();

        foreach ($event->getOrders() as $order) {
            $operation = $operations[$order->getId()] ?? null;
            $deliveryId = $operation instanceof DocumentGenerateOperation ? ($operation->getConfig()[self::CONFIG_KEY] ?? null) : null;
            if (!\is_string($deliveryId) || $deliveryId === '') {
                continue;
            }

            $this->narrowToDelivery($order, $deliveryId);
        }
    }

    private function narrowToDelivery(OrderEntity $order, string $deliveryId): void
    {
        $delivery = $order->getDeliveries()?->get($deliveryId);
        $lineItems = $order->getLineItems();
        if ($delivery === null || $lineItems === null || $delivery->getPositions() === null) {
            return;
        }

        $quantities = [];
        foreach ($delivery->getPositions() as $position) {
            $quantities[$position->getOrderLineItemId()] = ($quantities[$position->getOrderLineItemId()] ?? 0) + $position->getQuantity();
        }

        // Keep the delivered items plus their parents (e.g. a bundle around delivered children).
        $keep = $quantities;
        foreach (array_keys($quantities) as $lineItemId) {
            $parentId = $lineItems->get($lineItemId)?->getParentId();
            while ($parentId !== null && !isset($keep[$parentId])) {
                $keep[$parentId] = null;
                $parentId = $lineItems->get($parentId)?->getParentId();
            }
        }

        $narrowed = new OrderLineItemCollection();
        foreach ($lineItems as $lineItem) {
            if (!\array_key_exists($lineItem->getId(), $keep)) {
                continue;
            }
            $copy = clone $lineItem;
            if ($keep[$lineItem->getId()] !== null) {
                $copy->setQuantity($keep[$lineItem->getId()]);
            }
            $narrowed->add($copy);
        }

        $order->setLineItems($narrowed);
        $order->setDeliveries(new OrderDeliveryCollection([$delivery]));
        if (method_exists($order, 'setPrimaryOrderDelivery')) {
            $order->setPrimaryOrderDelivery($delivery);
        }
    }

    /**
     * @param array<string, DocumentGenerateOperation> $operations
     */
    private function hasDeliveryConfig(array $operations): bool
    {
        foreach ($operations as $operation) {
            if (!empty($operation->getConfig()[self::CONFIG_KEY])) {
                return true;
            }
        }

        return false;
    }
}
