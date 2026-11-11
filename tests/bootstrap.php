<?php declare(strict_types=1);

/*
 * The tests need Shopware's classes. They use, in this order:
 *
 * - SHOPWARE_DIR: the root of a Shopware installation;
 * - the installation the plugin lives in (custom/plugins/MnMultiDelivery);
 * - the plugin's own vendor directory (composer install in the plugin, as in CI).
 *
 * The unit tests need no database or kernel; the integration tests need an
 * installation with the plugin active (see tests/Integration/IntegrationTestCase.php).
 */
$candidates = array_filter([
    getenv('SHOPWARE_DIR') ?: null,
    dirname(__DIR__, 4),
    dirname(__DIR__),
]);

foreach ($candidates as $root) {
    $autoload = $root . '/vendor/autoload.php';
    // A shop has Shopware in vendor/, the Shopware repository (CI) in src/Core.
    if (is_file($autoload) && (is_dir($root . '/vendor/shopware/core') || is_dir($root . '/src/Core'))) {
        $classLoader = require $autoload;
        $classLoader->addPsr4('Mn\\MultiDelivery\\', dirname(__DIR__) . '/src');
        $classLoader->addPsr4('Mn\\MultiDelivery\\Tests\\', __DIR__);
        $classLoader->addPsr4('Mn\\MultiDeliveryExample\\', dirname(__DIR__) . '/examples/MnMultiDeliveryExample/src');

        return;
    }
}

fwrite(\STDERR, "Shopware not found. Set SHOPWARE_DIR to a shop root, or run composer install in the plugin.\n");
exit(1);
