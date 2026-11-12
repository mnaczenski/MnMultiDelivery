<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Subscriber;

use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Service\SplitSettings;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Checkout\Cart\CheckoutCartPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * A hint on the cart page, so customers know about the deliveries before the
 * last step: how many deliveries the cart is split into, or that it can be
 * split. Only for carts that can be split and have more than one item.
 *
 * @internal
 */
class CartPageSubscriber implements EventSubscriberInterface
{
    public const EXTENSION = 'mnMultiDeliveryCartHint';

    public function __construct(
        private readonly SplitGuard $guard,
        private readonly SplitSettings $settings,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckoutCartPageLoadedEvent::class => 'onCartPageLoaded'];
    }

    public function onCartPageLoaded(CheckoutCartPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $page = $event->getPage();
        $cart = $page->getCart();
        if (!$this->settings->cartHint($context) || $this->guard->blockReason($cart, $context) !== null) {
            return;
        }

        $deliveries = $cart->getDeliveries()->count();
        if ($deliveries < 2) {
            $quantity = 0;
            foreach ($cart->getLineItems()->filterGoods() as $lineItem) {
                /** @var LineItem $lineItem */
                $quantity += $lineItem->getQuantity();
            }
            if ($quantity < 2) {
                return;
            }
        }

        $page->addExtension(self::EXTENSION, new ArrayStruct(['deliveries' => $deliveries]));
    }
}
