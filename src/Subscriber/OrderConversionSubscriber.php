<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Mn\MultiDelivery\Cart\DeliveryGroupInfo;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Order\CartConvertedEvent;
use Mn\MultiDelivery\Event\SplitOrderConvertedEvent;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Writes each split delivery's group and source warehouse into the order
 * delivery's custom fields, so fulfilment, documents and flows can use it.
 *
 * Converted deliveries are re-sorted by shipping costs, so they are matched
 * to cart deliveries by their positions (line item identifier and quantity).
 * Projects add their own order data in SplitOrderConvertedEvent.
 *
 * @internal
 */
class OrderConversionSubscriber implements EventSubscriberInterface
{
    public const CUSTOM_FIELD = 'mn_multi_delivery';

    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CartConvertedEvent::class => 'onCartConverted'];
    }

    public function onCartConverted(CartConvertedEvent $event): void
    {
        $cartDeliveries = $event->getCart()->getDeliveries();
        if ($cartDeliveries->count() < 2) {
            return;
        }

        $converted = $event->getConvertedCart();
        if (empty($converted['deliveries']) || empty($converted['lineItems'])) {
            return;
        }

        $identifierByOrderLineItemId = [];
        $this->mapLineItems($converted['lineItems'], $identifierByOrderLineItemId);

        $infoBySignature = [];
        foreach ($cartDeliveries as $delivery) {
            $info = $delivery->getExtension(DeliveryGroupInfo::EXTENSION);
            if (!$info instanceof DeliveryGroupInfo) {
                continue;
            }
            $infoBySignature[$this->cartSignature($delivery)][] = $info;
        }

        foreach ($converted['deliveries'] as $index => $orderDelivery) {
            $parts = [];
            foreach ($orderDelivery['positions'] ?? [] as $position) {
                $identifier = $identifierByOrderLineItemId[$position['orderLineItemId'] ?? ''] ?? null;
                $price = $position['price'] ?? null;
                if ($identifier === null || !$price instanceof CalculatedPrice) {
                    continue 2;
                }
                $parts[] = $identifier . ':' . $price->getQuantity();
            }
            sort($parts);
            $signature = implode('|', $parts);

            $info = isset($infoBySignature[$signature]) ? array_shift($infoBySignature[$signature]) : null;
            if ($info === null) {
                continue;
            }

            $converted['deliveries'][$index]['customFields'] = array_merge($orderDelivery['customFields'] ?? [], [
                self::CUSTOM_FIELD => [
                    'group' => $info->getGroupId(),
                    'label' => $info->getLabel(),
                    'warehouseId' => $info->getWarehouseId(),
                    'warehouseName' => $info->getWarehouseName(),
                ],
            ]);
        }

        $splitEvent = new SplitOrderConvertedEvent($converted, $event->getCart(), $event->getSalesChannelContext());
        $event->setConvertedCart($this->dispatcher->dispatch($splitEvent)->getConvertedCart());
    }

    private function cartSignature(Delivery $delivery): string
    {
        $parts = [];
        foreach ($delivery->getPositions() as $position) {
            $parts[] = $position->getIdentifier() . ':' . $position->getQuantity();
        }
        sort($parts);

        return implode('|', $parts);
    }

    /**
     * @param array<int|string, array<string, mixed>> $lineItems
     * @param array<string, string>                   $map
     */
    private function mapLineItems(array $lineItems, array &$map): void
    {
        foreach ($lineItems as $lineItem) {
            if (isset($lineItem['id'], $lineItem['identifier'])) {
                $map[(string) $lineItem['id']] = (string) $lineItem['identifier'];
            }
            if (!empty($lineItem['children']) && \is_array($lineItem['children'])) {
                $this->mapLineItems($lineItem['children'], $map);
            }
        }
    }
}
