<?php declare(strict_types=1);

namespace Mn\MultiDelivery\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Mn\MultiDelivery\Cart\DeliveryPlan;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\MemoizedSystemConfigLoader;
use Shopware\Core\System\SystemConfig\Store\MemoizedSystemConfigStore;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs against a real Shopware installation with the plugin active.
 * Read-only: carts are calculated in memory, orders are converted but never
 * written, and plugin settings are changed in this process only.
 *
 *   MN_MULTI_DELIVERY_SALES_CHANNEL_ID   storefront sales channel to test with (required)
 *   MN_MULTI_DELIVERY_CUSTOMER_ID        a customer of that channel, for the order tests (optional)
 *   APP_ENV                           defaults to prod
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?KernelInterface $kernel = null;

    protected static function salesChannelId(): string
    {
        $id = getenv('MN_MULTI_DELIVERY_SALES_CHANNEL_ID');
        if (!\is_string($id) || $id === '') {
            static::markTestSkipped('Set MN_MULTI_DELIVERY_SALES_CHANNEL_ID to run the integration tests.');
        }

        return $id;
    }

    /**
     * A service from the container, typed.
     *
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = static::container()->get($id);
        \assert($service instanceof $id);

        return $service;
    }

    protected static function dispatcher(): \Symfony\Component\EventDispatcher\EventDispatcherInterface
    {
        $dispatcher = static::container()->get('event_dispatcher');
        \assert($dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface);

        return $dispatcher;
    }

    protected static function container(): ContainerInterface
    {
        if (self::$kernel === null) {
            $shopwareDir = getenv('SHOPWARE_DIR') ?: \dirname(__DIR__, 5);
            // a Shopware checkout in CI has no .env: the variables come from the environment
            if (is_file($shopwareDir . '/.env') || is_file($shopwareDir . '/.env.dist')) {
                (new Dotenv())->usePutenv()->bootEnv($shopwareDir . '/.env');
            }
            $classLoader = require $shopwareDir . '/vendor/autoload.php';
            $kernel = KernelFactory::create(environment: getenv('APP_ENV') ?: 'prod', debug: false, classLoader: $classLoader);
            \assert($kernel instanceof KernelInterface);
            self::$kernel = $kernel;
            self::$kernel->boot();
        }

        return self::$kernel->getContainer();
    }

    protected function setUp(): void
    {
        static::salesChannelId();
        $this->configure([]);
    }

    protected function tearDown(): void
    {
        // Skipped tests never touched the settings.
        if (\is_string(getenv('MN_MULTI_DELIVERY_SALES_CHANNEL_ID')) && getenv('MN_MULTI_DELIVERY_SALES_CHANNEL_ID') !== '') {
            $this->memoizedConfig()->setConfig(static::salesChannelId(), $this->baseConfig());
        }
    }

    /**
     * Plugin settings for this process only (without the "MnMultiDelivery.config." prefix).
     *
     * @param array<string, mixed> $settings
     */
    protected function configure(array $settings): void
    {
        $config = $this->baseConfig();
        foreach ($settings + ['enabled' => true, 'blockedPaymentHandlers' => '', 'strategies' => [], 'autoSplit' => false, 'extraDeliveryCosts' => 'each'] as $key => $value) {
            $config['MnMultiDelivery']['config'][$key] = $value;
        }
        $this->memoizedConfig()->setConfig(static::salesChannelId(), $config);
    }

    protected function context(?string $customerId = null): SalesChannelContext
    {
        $options = $customerId !== null ? ['customerId' => $customerId] : [];

        return static::service(SalesChannelContextFactory::class)->create(Uuid::randomHex(), static::salesChannelId(), $options);
    }

    /**
     * @param array<string, int> $products product id => quantity
     */
    protected function calculate(array $products, ?DeliveryPlan $plan, SalesChannelContext $context): Cart
    {
        $cart = new Cart(Uuid::randomHex());
        foreach ($products as $id => $quantity) {
            $lineItem = new LineItem($id, LineItem::PRODUCT_LINE_ITEM_TYPE, $id, $quantity);
            $lineItem->setStackable(true);
            $lineItem->setRemovable(true);
            $cart->add($lineItem);
        }
        if ($plan !== null) {
            $cart->addExtension(DeliveryPlan::EXTENSION, $plan);
        }

        return $this->calculator()->calculate($cart, $context);
    }

    /**
     * A visible simple product of the sales channel matching the stock condition.
     */
    protected function product(string $stockCondition): string
    {
        $id = static::service(Connection::class)->fetchOne(
            'SELECT LOWER(HEX(p.id)) FROM product p
             INNER JOIN product_visibility pv ON pv.product_id = p.id AND pv.sales_channel_id = UNHEX(:salesChannel)
             WHERE p.parent_id IS NULL AND p.child_count = 0 AND p.active = 1 AND COALESCE(p.is_closeout, 0) = 0 AND ' . $stockCondition . '
             ORDER BY p.created_at LIMIT 1',
            ['salesChannel' => static::salesChannelId()]
        );
        if (!\is_string($id)) {
            static::markTestSkipped('No product with ' . $stockCondition . ' in the sales channel.');
        }

        return $id;
    }

    protected function calculator(): CartCalculator
    {
        return $this->property(static::service(CartService::class), 'calculator');
    }

    protected function property(object $object, string $name): mixed
    {
        $reflection = new \ReflectionObject($object);
        while (!$reflection->hasProperty($name)) {
            $reflection = $reflection->getParentClass() ?: throw new \LogicException("No property {$name}");
        }

        return $reflection->getProperty($name)->getValue($object);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseConfig(): array
    {
        static $base = [];

        return $base[static::salesChannelId()] ??= $this->loader()->load(static::salesChannelId());
    }

    private function memoizedConfig(): MemoizedSystemConfigStore
    {
        $store = $this->property($this->loader(), 'memoizedSystemConfigStore');
        \assert($store instanceof MemoizedSystemConfigStore);

        return $store;
    }

    private function loader(): MemoizedSystemConfigLoader
    {
        $loader = $this->property(static::container()->get(SystemConfigService::class), 'loader');
        while (!$loader instanceof MemoizedSystemConfigLoader) {
            $loader = $this->property($loader, 'decorated');
        }

        return $loader;
    }
}
