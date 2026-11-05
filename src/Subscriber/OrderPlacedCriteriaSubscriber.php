<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedCriteriaEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The order confirmation mail lists every delivery with its items, so the
 * order loaded for the mail needs the delivery positions.
 *
 * @internal
 */
class OrderPlacedCriteriaSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [CheckoutOrderPlacedCriteriaEvent::class => 'onCriteria'];
    }

    public function onCriteria(CheckoutOrderPlacedCriteriaEvent $event): void
    {
        $event->getCriteria()->addAssociation('deliveries.positions.orderLineItem');
    }
}
