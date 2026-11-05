<?php declare(strict_types=1);

namespace Mn\MultiDelivery\SeparateOrder;

use Shopware\Core\Checkout\Cart\AbstractCartPersister;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Splits a cart into separate orders and calculates each one the way it will
 * be placed: the main cart for the shipping country, every separate order
 * for its own address.
 *
 * Shopware stores a cart under its token while calculating it when product
 * data was reloaded (CartRuleLoader). The carts here therefore never use the
 * customer's token: the main cart has a throwaway token, each separate order
 * the token of its own context. Those stored copies are deleted again right
 * away, so the customer's cart stays as it is.
 *
 * @internal
 */
class SeparateOrderCalculator
{
    public function __construct(
        private readonly SeparateOrderSplitter $splitter,
        private readonly SeparateOrderContextFactory $contexts,
        private readonly CartCalculator $calculator,
        private readonly AbstractCartPersister $persister,
    ) {
    }

    /**
     * @return array{split: SeparateOrderSplit, contexts: array<string, SalesChannelContext>}|null contexts by group id
     */
    public function calculate(Cart $cart, SalesChannelContext $context): ?array
    {
        $split = $this->splitter->split($cart, $context);
        if ($split === null) {
            return null;
        }

        $parts = [];
        $contexts = [];
        foreach ($split->parts as $part) {
            $partContext = $this->contexts->create($context, $part);
            $contexts[$part->groupId] = $partContext;
            // Shopware's order route deletes the cart of its context's token after placing the order.
            $part->cart->setToken($partContext->getToken());
            $parts[] = $part->withCart($this->calculateWithoutStoring($part->cart, $partContext, $context->getToken()));
        }

        // The main cart is calculated on a copy of the context: calculating changes the context's rules.
        $main = $split->hasMainOrder()
            ? $this->calculateWithoutStoring($split->mainCart, $this->copy($context), $context->getToken())
            : $split->mainCart;

        return ['split' => $split->with($main, $parts), 'contexts' => $contexts];
    }

    /**
     * Removes what calculating or placing a cart of this class stored under
     * its token. Never touches the customer's own cart.
     */
    public function discard(Cart $cart, SalesChannelContext $context, string $customerToken): void
    {
        if ($cart->getToken() === $customerToken) {
            return;
        }
        $this->persister->delete($cart->getToken(), $context);
        $cart->setPersisted(false);
    }

    private function calculateWithoutStoring(Cart $cart, SalesChannelContext $context, string $customerToken): Cart
    {
        $calculated = $this->calculator->calculate($cart, $context);
        $this->discard($calculated, $context, $customerToken);

        return $calculated;
    }

    private function copy(SalesChannelContext $context): SalesChannelContext
    {
        $copy = clone $context;
        $copy->assign(['context' => clone $context->getContext()]);

        return $copy;
    }
}
