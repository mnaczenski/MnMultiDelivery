<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Mn\MultiDelivery\Cart\DeliveryGroupInfo;
use Shopware\Core\Checkout\Cart\Event\CartContextHashEvent;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Adds the delivery layout (items, address and shipping method per delivery)
 * to the cart hash the confirm page submits with the order. Shopware's hash
 * only covers totals, items and the selected methods, so a delivery address
 * changed in another tab would otherwise go unnoticed.
 *
 * @internal
 */
class CartHashSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [CartContextHashEvent::class => 'onHash'];
    }

    public function onHash(CartContextHashEvent $event): void
    {
        $deliveries = $event->getCart()->getDeliveries();
        if ($deliveries->count() < 2) {
            return;
        }

        $layout = [];
        foreach ($deliveries as $delivery) {
            $positions = [];
            foreach ($delivery->getPositions() as $position) {
                $positions[$position->getIdentifier()] = $position->getQuantity();
            }
            $layout[] = [
                'group' => $delivery->getExtension(DeliveryGroupInfo::EXTENSION)?->getGroupId(),
                'address' => $delivery->getLocation()->getAddress()?->getId(),
                'shippingMethod' => $delivery->getShippingMethod()->getId(),
                'positions' => $positions,
            ];
        }

        $event->getHashStruct()->addExtension('mnMultiDelivery', new ArrayStruct($layout));
    }
}
