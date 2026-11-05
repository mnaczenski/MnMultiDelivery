<?php declare(strict_types=1);

use Mn\MultiDeliveryExample\Blocker\PreorderBlocker;
use Mn\MultiDeliveryExample\Strategy\BulkyGoodsStrategy;
use Mn\MultiDeliveryExample\Subscriber\ErpShipmentReference;
use Mn\MultiDeliveryExample\Subscriber\KeepGiftCardsWithFirstDelivery;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // A splitting rule, listed after "By delivery time" (150) and before "By warehouse" (100).
    $services->set(BulkyGoodsStrategy::class)
        ->args([30.0])
        ->tag('mn.multi_delivery.strategy', ['priority' => 120]);

    $services->set(PreorderBlocker::class)
        ->tag('mn.multi_delivery.blocker');

    $services->set(KeepGiftCardsWithFirstDelivery::class)
        ->args([['GIFT-CARD']])
        ->tag('kernel.event_subscriber');

    $services->set(ErpShipmentReference::class)
        ->tag('kernel.event_subscriber');
};
