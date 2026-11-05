<?php declare(strict_types=1);

namespace Mn\MultiDeliveryExample\Subscriber;

use Mn\MultiDelivery\Event\DeliveryAllocationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Project rule on top of every splitting rule: gift cards always ship with
 * the first delivery.
 */
class KeepGiftCardsWithFirstDelivery implements EventSubscriberInterface
{
    /**
     * @param list<string> $productNumbers
     */
    public function __construct(private readonly array $productNumbers = ['GIFT-CARD'])
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [DeliveryAllocationEvent::class => 'onAllocation'];
    }

    public function onAllocation(DeliveryAllocationEvent $event): void
    {
        $allocation = $event->getAllocation();
        $first = array_key_first($allocation->groups);
        if ($first === null) {
            return;
        }

        foreach ($event->getRoots() as $id => $root) {
            if (\in_array($root->lineItem->getPayload()['productNumber'] ?? null, $this->productNumbers, true)) {
                $allocation = $allocation->withQuantities($id, [$first => $root->getQuantity()]);
            }
        }
        $event->setAllocation($allocation);
    }
}
