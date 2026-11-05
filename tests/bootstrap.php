<?php declare(strict_types=1);

/*
 * Unit tests run against the Shopware installation the plugin lives in
 * (custom/plugins/MnMultiDelivery) or the one in SHOPWARE_DIR.
 * No database or kernel is needed.
 */
$shopwareDir = getenv('SHOPWARE_DIR') ?: dirname(__DIR__, 4);
$autoload = $shopwareDir . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(\STDERR, "Shopware not found at {$shopwareDir}. Set SHOPWARE_DIR to the shop root.\n");
    exit(1);
}

$classLoader = require $autoload;
$classLoader->addPsr4('Mn\\MultiDelivery\\', dirname(__DIR__) . '/src');
$classLoader->addPsr4('Mn\\MultiDelivery\\Tests\\', __DIR__);
$classLoader->addPsr4('Mn\\MultiDeliveryExample\\', dirname(__DIR__) . '/examples/MnMultiDeliveryExample/src');
