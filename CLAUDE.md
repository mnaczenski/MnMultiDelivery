# Multi Delivery (Shopware 6.7 plugin)

Customers split one order into several deliveries (own address and shipping method each), by rule, optionally as separate orders. Start with `docs/system/overview.md`, then the module docs in `docs/system/modules/`. Decisions and learnings: `knowledge/INDEX.md`. Features: `specs/INDEX.md`.

## Commands
All verified on 2026-11-17 in a dockware Shopware 6.7.16 shop (PHP 8.2). The tests need Shopware's classes: `tests/bootstrap.php` uses `SHOPWARE_DIR`, then the shop the plugin sits in (`custom/plugins/MnMultiDelivery`), then the plugin's own `vendor/`.

| What | Command | Result on 2026-11-17 |
|---|---|---|
| Unit tests | `SHOPWARE_DIR=<shop root> <shop root>/vendor/bin/phpunit --testsuite unit` (or `composer test` after `composer install` in the plugin, as CI does) | 112 tests, 8 skipped (Commercial warehouse parity) |
| PHPStan (level 8) | `SHOPWARE_DIR=<shop root> vendor/bin/phpstan analyse --memory-limit=2G` (or `composer phpstan`) | no errors |
| Integration tests | plugin installed and active in a shop; `php tests/ci/seed.php <shop root> > env` (prints `MN_MULTI_DELIVERY_SALES_CHANNEL_ID`, `MN_MULTI_DELIVERY_CUSTOMER_ID`); export those; from `custom/plugins/MnMultiDelivery`: `../../../vendor/bin/phpunit --testsuite integration` | 7 tests |
| Admin override check | `php tests/ci/check-admin-overrides.php <shop>/vendor/shopware/administration/Resources/app/administration/src` | 1 component checked, 0 problems |
| Install in a shop | `bin/console plugin:refresh && bin/console plugin:install --activate MnMultiDelivery && bin/console theme:compile && bin/console cache:clear` | installs and activates |

There's no PHP locally on the author's machine; a dockware container works (copy the plugin to `/tmp/<dir>` and set `SHOPWARE_DIR=/var/www/html`).

**Building assets:** `bin/build-storefront.sh` and `bin/build-administration.sh` are the **shop's** scripts (shop root, plugin active, Node needed), not the plugin's. Today `dist/` and `public/administration` are committed builds; decided (ADR-0007): they move out of git and are built into a release zip with `shopware-cli extension zip`. Until that is done, rebuild and commit them after changing JS sources.

## Conventions (observed in the code)
- `declare(strict_types=1);` in every PHP file; constructor injection with `private readonly` properties; services registered in `src/Resources/config/services.php` (PHP config, not XML).
- **Public API vs internal:** classes promised stable carry the docblock `Public API: stable within a major version`; everything else is `@internal`. Anything listed in `docs/compatibility.md` (classes, events, service tags, custom fields, Twig block names, snippet keys, Store API fields, setting keys) only changes in a major version. Update `docs/compatibility.md` and the README "Extending" section with every public change.
- Extension points are service tags (`mn.multi_delivery.strategy`, `.blocker`, `.address_policy`), decoration (`ShippingCostPolicyInterface`, `AbstractDeliveryPlanRoute`) and events in `src/Event`. New extension points belong in `examples/MnMultiDeliveryExample` and `tests/Unit/Example/ExamplePluginTest.php`.
- No database tables: state in the cart extension `mnDeliveryPlan`, config `MnMultiDelivery.config.*`, order custom fields `mn_multi_delivery` / `mn_multi_delivery_orders` (installed by `CustomFieldInstaller`).
- Error codes `MN_MULTI_DELIVERY__*` (see `MultiDeliveryException`); storefront errors as `error.MN_MULTI_DELIVERY__*` snippets.
- Texts in English and German (storefront snippets, admin snippets, mail); keep both in sync.
- Tests mirror `src/` under `tests/Unit/<Area>`; shared fixtures in `tests/Fixtures.php`; integration tests extend `tests/Integration/IntegrationTestCase.php` and read the seed environment variables.
- Every version gets a `CHANGELOG.md` entry (`## x.y.z`, bold feature names, new public API named explicitly) and the README is updated with it; version in `composer.json`.
- SwagCommercial is optional and not a Composer dependency: code touching it (warehouse rule, quote/subscription detection) must work without it. Before a release, run `WarehouseStrategyParityTest` in a shop with Commercial (ADR-0008); CI skips it.
