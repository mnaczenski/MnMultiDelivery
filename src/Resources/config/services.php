<?php declare(strict_types=1);

use Mn\MultiDelivery\Cart\SplitDeliveryProcessor;
use Mn\MultiDelivery\Controller\DeliveryPlanController;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Policy\ExtraDeliveryCostPolicy;
use Mn\MultiDelivery\Policy\SameCountryAddressPolicy;
use Mn\MultiDelivery\Policy\ShippingCostPolicyInterface;
use Mn\MultiDelivery\Route\AbstractDeliveryPlanRoute;
use Mn\MultiDelivery\Route\DeliveryPlanRoute;
use Mn\MultiDelivery\Rule\DeliveryCountRule;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderCalculator;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderCartOrderRoute;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderContextFactory;
use Mn\MultiDelivery\SeparateOrder\SeparateOrderSplitter;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Mn\MultiDelivery\Service\MailBlock;
use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Service\SplitSettings;
use Mn\MultiDelivery\Strategy\AvailabilityStrategy;
use Mn\MultiDelivery\Strategy\DeliveryTimeStrategy;
use Mn\MultiDelivery\Strategy\ManualStrategy;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Mn\MultiDelivery\Strategy\WarehouseStrategy;
use Mn\MultiDelivery\Subscriber\CartHashSubscriber;
use Mn\MultiDelivery\Subscriber\CartPageSubscriber;
use Mn\MultiDelivery\Subscriber\ConfirmPageSubscriber;
use Mn\MultiDelivery\Subscriber\DeliveryNoteSubscriber;
use Mn\MultiDelivery\Subscriber\OrderConversionSubscriber;
use Mn\MultiDelivery\Subscriber\OrderMailSubscriber;
use Mn\MultiDelivery\Subscriber\OrderPlacedCriteriaSubscriber;
use Mn\MultiDelivery\Subscriber\SeparateOrdersFinishPageSubscriber;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\CartContextHasher;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Price\QuantityPriceCalculator;
use Shopware\Core\Checkout\Cart\SalesChannel\CartOrderRoute;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Cart\Tax\TaxCalculator;
use Shopware\Core\Checkout\Customer\SalesChannel\ListAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\UpsertAddressRoute;
use Shopware\Core\Framework\Adapter\Translation\Translator;
use Shopware\Core\System\Country\SalesChannel\CountryRoute;
use Shopware\Core\System\Salutation\SalesChannel\SalutationRoute;
use Shopware\Core\Framework\Adapter\Twig\TemplateFinder;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoader;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

/*
 * Extension points (see README, "Extending"):
 *   mn.multi_delivery.strategy        DeliverySplitStrategyInterface
 *   mn.multi_delivery.blocker         SplitBlockerInterface
 *   mn.multi_delivery.address_policy  DeliveryAddressPolicyInterface
 *   ShippingCostPolicyInterface             decorate
 *   AbstractDeliveryPlanRoute               decorate
 */
return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(SplitSettings::class)
        ->args([service(SystemConfigService::class)]);

    $services->set(SplitGuard::class)
        ->args([service(SystemConfigService::class), tagged_iterator('mn.multi_delivery.blocker')]);

    // Strategies, in the order customers see them.
    $services->set(ManualStrategy::class)
        ->tag('mn.multi_delivery.strategy', ['priority' => 300]);
    $services->set(AvailabilityStrategy::class)
        ->tag('mn.multi_delivery.strategy', ['priority' => 200]);
    $services->set(DeliveryTimeStrategy::class)
        ->args([service(SplitSettings::class)])
        ->tag('mn.multi_delivery.strategy', ['priority' => 150]);
    $services->set(WarehouseStrategy::class)
        ->args([service('Doctrine\DBAL\Connection')])
        ->tag('mn.multi_delivery.strategy', ['priority' => 100]);

    $services->set(StrategyRegistry::class)
        ->args([tagged_iterator('mn.multi_delivery.strategy'), service(SplitSettings::class)]);

    // Policies
    $services->set(SameCountryAddressPolicy::class)
        ->args([service(SplitSettings::class)])
        ->tag('mn.multi_delivery.address_policy');
    $services->set(DeliveryAddressPolicies::class)
        ->args([tagged_iterator('mn.multi_delivery.address_policy')]);

    $services->set(ExtraDeliveryCostPolicy::class)
        ->args([service(SplitSettings::class), service(QuantityPriceCalculator::class), service(TaxCalculator::class)]);
    $services->alias(ShippingCostPolicyInterface::class, ExtraDeliveryCostPolicy::class);

    // Between DeliveryProcessor (-5000) and PromotionDeliveryProcessor (-5100).
    $services->set(SplitDeliveryProcessor::class)
        ->args([
            service(SplitGuard::class),
            service(StrategyRegistry::class),
            service(DeliveryCalculator::class),
            service('customer_address.repository'),
            service('shipping_method.repository'),
            service(SplitSettings::class),
            service(ShippingCostPolicyInterface::class),
            service(DeliveryAddressPolicies::class),
            service('event_dispatcher'),
        ])
        ->tag('shopware.cart.processor', ['priority' => -5050])
        ->tag('shopware.cart.collector', ['priority' => -5050]);

    $services->set(DeliveryPlanService::class)
        ->args([
            service(CartService::class),
            service(SplitGuard::class),
            service(StrategyRegistry::class),
            service(SplitSettings::class),
            service('event_dispatcher'),
            service(SeparateOrderCalculator::class),
        ]);

    // Deliveries to other countries as separate orders (setting countryMode)
    $services->set(SeparateOrderSplitter::class)
        ->args([service(SplitSettings::class)]);
    $services->set(SeparateOrderContextFactory::class)
        ->args([service(SalesChannelContextFactory::class), service(SalesChannelContextPersister::class)]);
    $services->set(SeparateOrderCalculator::class)
        ->args([service(SeparateOrderSplitter::class), service(SeparateOrderContextFactory::class), service(CartCalculator::class), service(CartPersister::class)]);
    $services->set(SeparateOrderCartOrderRoute::class)
        ->decorate(CartOrderRoute::class)
        ->args([
            service('.inner'),
            service(SeparateOrderCalculator::class),
            service(SplitSettings::class),
            service(CartContextHasher::class),
            service(CartPersister::class),
            service('order.repository'),
            service(StateMachineRegistry::class),
            service('event_dispatcher'),
            service('logger'),
        ]);

    // Subscribers
    $services->set(ConfirmPageSubscriber::class)
        ->args([
            service(SplitGuard::class),
            service(DeliveryPlanService::class),
            service(ListAddressRoute::class),
            service(DeliveryAddressPolicies::class),
            service(CountryRoute::class),
            service(SalutationRoute::class),
        ])
        ->tag('kernel.event_subscriber');
    $services->set(CartPageSubscriber::class)
        ->args([service(SplitGuard::class), service(SplitSettings::class)])
        ->tag('kernel.event_subscriber');
    $services->set(OrderConversionSubscriber::class)
        ->args([service('event_dispatcher')])
        ->tag('kernel.event_subscriber');
    $services->set(CartHashSubscriber::class)
        ->tag('kernel.event_subscriber');
    $services->set(OrderPlacedCriteriaSubscriber::class)
        ->tag('kernel.event_subscriber');
    $services->set(DeliveryNoteSubscriber::class)
        ->tag('kernel.event_subscriber');
    $services->set(SeparateOrdersFinishPageSubscriber::class)
        ->args([service('order.repository')])
        ->tag('kernel.event_subscriber');

    $services->set(DeliveryCountRule::class)
        ->tag('shopware.rule.definition');

    $services->set(MailBlock::class)
        ->args([service('twig'), service(TemplateFinder::class)]);
    $services->set(OrderMailSubscriber::class)
        ->args([service('Doctrine\DBAL\Connection'), service(MailBlock::class), service(Translator::class)])
        ->tag('kernel.event_subscriber');

    // Storefront and Store API
    $services->set(DeliveryPlanController::class)
        ->public()
        ->args([service(DeliveryPlanService::class), service(CheckoutConfirmPageLoader::class), service(UpsertAddressRoute::class), service(DeliveryAddressPolicies::class)])
        ->call('setContainer', [service('service_container')]);

    $services->set(DeliveryPlanRoute::class)
        ->public()
        ->args([service(DeliveryPlanService::class), service(CartService::class), service(ListAddressRoute::class), service(DeliveryAddressPolicies::class)]);
    $services->alias(AbstractDeliveryPlanRoute::class, DeliveryPlanRoute::class)->public();
};
