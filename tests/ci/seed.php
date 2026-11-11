<?php declare(strict_types=1);

/*
 * Test data for the integration tests in CI, in a freshly installed shop:
 * two products in the storefront sales channel (plenty of stock, and 2 in
 * stock with a restock time) and a customer with two addresses.
 *
 * Prints the variables the integration tests read, as KEY=value lines
 * (append them to $GITHUB_ENV):
 *
 *   php tests/ci/seed.php /path/to/shopware >> "$GITHUB_ENV"
 */

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Kernel\KernelFactory;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Doctrine\DBAL\Connection;
use Symfony\Component\Dotenv\Dotenv;

$root = $argv[1] ?? dirname(__DIR__, 5);
$classLoader = require $root . '/vendor/autoload.php';
(new Dotenv())->usePutenv()->bootEnv($root . '/.env');
$kernel = KernelFactory::create(environment: getenv('APP_ENV') ?: 'prod', debug: false, classLoader: $classLoader);
assert($kernel instanceof Symfony\Component\HttpKernel\KernelInterface);
$kernel->boot();
$container = $kernel->getContainer();
$connection = $container->get(Connection::class);
assert($connection instanceof Connection);
$context = Context::createCLIContext();

$hex = static fn (mixed $value): string => is_string($value) ? strtolower($value) : throw new RuntimeException('Missing test data in the installation.');
$salesChannelId = $hex($connection->fetchOne(
    'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = UNHEX(:type) ORDER BY created_at LIMIT 1',
    ['type' => Defaults::SALES_CHANNEL_TYPE_STOREFRONT],
));
$taxId = $hex($connection->fetchOne('SELECT LOWER(HEX(id)) FROM tax ORDER BY position, tax_rate DESC LIMIT 1'));
$deliveryTimeId = $hex($connection->fetchOne('SELECT LOWER(HEX(id)) FROM delivery_time ORDER BY min LIMIT 1'));
$countryId = $hex($connection->fetchOne(
    'SELECT LOWER(HEX(country_id)) FROM sales_channel WHERE id = UNHEX(:id)',
    ['id' => $salesChannelId],
));
$salutationId = $hex($connection->fetchOne('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1'));
$customerGroupId = $hex($connection->fetchOne(
    'SELECT LOWER(HEX(customer_group_id)) FROM sales_channel WHERE id = UNHEX(:id)',
    ['id' => $salesChannelId],
));

$product = static fn (string $number, int $stock): array => [
    'id' => Uuid::randomHex(),
    'productNumber' => $number,
    'name' => 'CI ' . $number,
    'stock' => $stock,
    'restockTime' => 5,
    'active' => true,
    'taxId' => $taxId,
    'deliveryTimeId' => $deliveryTimeId,
    'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 19.99, 'net' => 16.80, 'linked' => true]],
    'visibilities' => [['salesChannelId' => $salesChannelId, 'visibility' => 30]],
];

/** @var EntityRepository<Shopware\Core\Content\Product\ProductCollection> $products */
$products = $container->get('product.repository');
$products->create([$product('MD-CI-PLENTY', 50), $product('MD-CI-FEW', 2)], $context);

$address = static fn (string $street, string $city): array => [
    'id' => Uuid::randomHex(),
    'salutationId' => $salutationId,
    'firstName' => 'Ci',
    'lastName' => 'Customer',
    'street' => $street,
    'zipcode' => '10115',
    'city' => $city,
    'countryId' => $countryId,
];
$first = $address('Teststraße 1', 'Berlin');
$second = $address('Beispielweg 2', 'Hamburg');
$customerId = Uuid::randomHex();
// Older 6.7 releases still require a default payment method on the customer.
$customerDefinition = $container->get(Shopware\Core\Checkout\Customer\CustomerDefinition::class);
assert($customerDefinition instanceof Shopware\Core\Checkout\Customer\CustomerDefinition);
$payment = $customerDefinition->getField('defaultPaymentMethodId') !== null
    ? ['defaultPaymentMethodId' => $hex($connection->fetchOne('SELECT LOWER(HEX(id)) FROM payment_method WHERE active = 1 ORDER BY position LIMIT 1'))]
    : [];

/** @var EntityRepository<Shopware\Core\Checkout\Customer\CustomerCollection> $customers */
$customers = $container->get('customer.repository');
$customers->create([[
    'id' => $customerId,
    'salesChannelId' => $salesChannelId,
    'groupId' => $customerGroupId,
    'customerNumber' => 'MD-CI-1',
    'salutationId' => $salutationId,
    'firstName' => 'Ci',
    'lastName' => 'Customer',
    'email' => 'md-ci@example.com',
    'password' => 'shopware-ci',
    'defaultBillingAddressId' => $first['id'],
    'defaultShippingAddressId' => $first['id'],
    'addresses' => [$first, $second],
    ...$payment,
]], $context);

echo 'MN_MULTI_DELIVERY_SALES_CHANNEL_ID=' . $salesChannelId . PHP_EOL;
echo 'MN_MULTI_DELIVERY_CUSTOMER_ID=' . $customerId . PHP_EOL;
