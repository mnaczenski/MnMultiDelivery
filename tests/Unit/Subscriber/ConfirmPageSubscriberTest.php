<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Unit\Subscriber;

use Mn\MultiDelivery\Cart\DeliveryPlan;
use Mn\MultiDelivery\Policy\DeliveryAddressPolicies;
use Mn\MultiDelivery\Policy\SameCountryAddressPolicy;
use Mn\MultiDelivery\Service\DeliveryPlanService;
use Mn\MultiDelivery\Service\SplitGuard;
use Mn\MultiDelivery\Strategy\ManualStrategy;
use Mn\MultiDelivery\Strategy\StrategyRegistry;
use Mn\MultiDelivery\Subscriber\ConfirmPageSubscriber;
use Mn\MultiDelivery\Tests\Fixtures;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractListAddressRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\ListAddressRouteResponse;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Country\SalesChannel\AbstractCountryRoute;
use Shopware\Core\System\Country\SalesChannel\CountryRouteResponse;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\Salutation\SalesChannel\AbstractSalutationRoute;
use Shopware\Core\System\Salutation\SalesChannel\SalutationRouteResponse;
use Shopware\Core\System\Salutation\SalutationCollection;
use Shopware\Core\Test\Generator;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPage;
use Shopware\Storefront\Page\Checkout\Confirm\CheckoutConfirmPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(ConfirmPageSubscriber::class)]
class ConfirmPageSubscriberTest extends TestCase
{
    private const ABROAD = 'ffffffffffffffffffffffffffffffff';

    public function testTheAddressFormOffersTheCountriesTheAddressPoliciesAllow(): void
    {
        $context = $this->context();
        $view = $this->load(new DeliveryPlan(true, 'manual'), $context);

        $form = $view->get('addressForm');
        static::assertIsArray($form);
        static::assertSame([$context->getShippingLocation()->getCountry()->getId()], array_values($form['countries']->getIds()));
    }

    public function testNoAddressFormWhileTheCartIsNotSplit(): void
    {
        static::assertNull($this->load(new DeliveryPlan(false, 'manual'), $this->context())->get('addressForm'));
    }

    /**
     * @return ArrayStruct<array<string, mixed>>
     */
    private function load(DeliveryPlan $plan, SalesChannelContext $context): ArrayStruct
    {
        $cart = new Cart('token');
        $cart->add(new LineItem('chair', LineItem::PRODUCT_LINE_ITEM_TYPE, 'chair', 2));
        $cart->addExtension(DeliveryPlan::EXTENSION, $plan);
        $page = new CheckoutConfirmPage();
        $page->setCart($cart);

        $settings = Fixtures::settings();
        $guard = new SplitGuard(new StaticSystemConfigService(['MnMultiDelivery.config.enabled' => true]));
        $cartService = $this->createMock(CartService::class);
        $cartService->method('getCart')->willReturn($cart);
        $planService = new DeliveryPlanService($cartService, $guard, new StrategyRegistry([new ManualStrategy()], $settings), $settings, new EventDispatcher());

        $addresses = $this->createMock(AbstractListAddressRoute::class);
        $addresses->method('load')->willReturn(new ListAddressRouteResponse($this->searchResult('customer_address', new CustomerAddressCollection())));

        $home = new CountryEntity();
        $home->setId($context->getShippingLocation()->getCountry()->getId());
        $abroad = new CountryEntity();
        $abroad->setId(self::ABROAD);
        $countries = $this->createMock(AbstractCountryRoute::class);
        $countries->method('load')->willReturn(new CountryRouteResponse($this->searchResult('country', new CountryCollection([$home, $abroad]))));
        $salutations = $this->createMock(AbstractSalutationRoute::class);
        $salutations->method('load')->willReturn(new SalutationRouteResponse($this->searchResult('salutation', new SalutationCollection())));

        (new ConfirmPageSubscriber($guard, $planService, $addresses, new DeliveryAddressPolicies([new SameCountryAddressPolicy()]), $countries, $salutations))
            ->onConfirmPageLoaded(new CheckoutConfirmPageLoadedEvent($page, $context, new Request()));

        $view = $page->getExtension(ConfirmPageSubscriber::EXTENSION);
        static::assertInstanceOf(ArrayStruct::class, $view);

        return $view;
    }

    /**
     * @template T of \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection
     *
     * @param T $collection
     *
     * @return EntitySearchResult<T>
     */
    private function searchResult(string $entity, $collection): EntitySearchResult
    {
        return new EntitySearchResult($entity, $collection->count(), $collection, null, new Criteria(), Context::createDefaultContext());
    }

    private function context(): SalesChannelContext
    {
        $paymentMethod = new PaymentMethodEntity();
        $paymentMethod->setId('0190a1b2c3d4e5f60718293a4b5c6d70');
        $paymentMethod->setHandlerIdentifier('Shopware\\Core\\Checkout\\Payment\\Cart\\PaymentHandler\\PrePayment');
        $customer = new CustomerEntity();
        $customer->setId('0190a1b2c3d4e5f60718293a4b5c6d71');

        return Generator::generateSalesChannelContext(paymentMethod: $paymentMethod, customer: $customer);
    }
}
