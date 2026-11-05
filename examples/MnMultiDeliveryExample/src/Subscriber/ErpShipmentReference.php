<?php declare(strict_types=1);

namespace Mn\MultiDeliveryExample\Subscriber;

use Mn\MultiDelivery\Event\SplitOrderConvertedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Gives every delivery of a split order a shipment reference for the ERP,
 * stored next to the split data in the order delivery's custom fields.
 */
class ErpShipmentReference implements EventSubscriberInterface
{
    public const CUSTOM_FIELD = 'example_erp_shipment';

    public static function getSubscribedEvents(): array
    {
        return [SplitOrderConvertedEvent::class => 'onConverted'];
    }

    public function onConverted(SplitOrderConvertedEvent $event): void
    {
        $order = $event->getConvertedCart();
        foreach ($order['deliveries'] ?? [] as $index => $delivery) {
            $group = $delivery['customFields']['mn_multi_delivery']['group'] ?? 'main';
            $order['deliveries'][$index]['customFields'][self::CUSTOM_FIELD] = \sprintf('SHIP-%s-%d', strtoupper($group), $index + 1);
        }
        $event->setConvertedCart($order);
    }
}
