# Module: tests-ci (Tests & CI)
_Path: tests/, .github/workflows/tests.yml, phpunit.xml.dist, phpstan.neon.dist, composer.json (scripts)_

Note: there is no local PHP or Composer in the mapping environment. No command below was run. Commands are verified only against CI config, composer.json and README, not by execution.

## Responsibility

Holds the PHPUnit unit suite (no DB, no kernel), the integration suite (real Shopware kernel, read-only), PHPStan level 8, and CI helper scripts: a test-data seeder and a check that admin internals the plugin overrides still exist. [verified: phpunit.xml.dist, phpstan.neon.dist, tests/ci/*]
One GitHub Actions workflow runs two jobs: `static` (PHPStan + unit) and `integration` (installed shop). [verified: .github/workflows/tests.yml]

## Entry points and interfaces

Commands. Composer scripts [verified: composer.json `scripts`]:
- `composer test` = `phpunit --testsuite unit`
- `composer test:integration` = `phpunit --testsuite integration`
- `composer phpstan` = `phpstan analyse --memory-limit=2G`

Local, without a shop [verified in README.md "Development", lines ~399-411]:
```
composer install
composer test
composer phpstan
```
Local, inside a shop's plugin dir [verified: README.md]:
```
php phpunit.phar --testsuite unit      # or SHOPWARE_DIR=/path/to/shop php phpunit.phar --testsuite unit
MN_MULTI_DELIVERY_SALES_CHANNEL_ID=<id> MN_MULTI_DELIVERY_CUSTOMER_ID=<id> php phpunit.phar --testsuite integration
```
- `phpunit.phar` is not in the repo; the README does not say where it comes from. [verified: absent from file listing]

CI, job `static` (matrix: PHP 8.2 + lowest, 8.3 + highest, 8.4 + highest) [verified: tests.yml]:
1. `shivammathur/setup-php@v2` (ext intl, pdo_mysql, zip, gd; coverage none)
2. `composer update --no-interaction --no-progress [--prefer-lowest]`
3. `composer show shopware/core | grep -E '^versions'` (prints the version under test)
4. `composer phpstan -- --no-progress`
5. `composer test`

CI, job `integration` (matrix Shopware `v6.7.14.0`, `v6.7.16.0`, `trunk`; trunk is `continue-on-error`) [verified: tests.yml]:
1. `shopware/github-actions/setup-extension@main` with `extensionName: MnMultiDelivery`, `install: true`, `allow-insecure-versions: true`
2. `php custom/plugins/MnMultiDelivery/tests/ci/check-admin-overrides.php src/Administration/Resources/app/administration/src`
3. `php custom/plugins/MnMultiDelivery/tests/ci/seed.php "$PWD" >> "$GITHUB_ENV"`
4. in `custom/plugins/MnMultiDelivery`: `../../../vendor/bin/phpunit --testsuite integration`

Triggers: push to `main`, tags `v*`, every pull_request. [verified: tests.yml]

Helper scripts:
- `tests/bootstrap.php`: finds Shopware autoload from, in order, `SHOPWARE_DIR`, `dirname(__DIR__, 4)` (shop root when plugin is in custom/plugins/X), plugin's own `vendor/`. A root counts only if `vendor/shopware/core` or `src/Core` exists. Registers PSR-4 for `Mn\MultiDelivery\`, `Mn\MultiDelivery\Tests\`, `Mn\MultiDeliveryExample\`. Exits 1 if none found. [verified]
- `tests/ci/seed.php <shop root>`: boots the kernel and creates test data (see Data model). Prints `KEY=value` lines to stdout; errors go to stderr. [verified]
- `tests/ci/check-admin-overrides.php <admin src dir>`: for each component in `tests/ci/admin-overrides.json`, checks the component dir exists, listed methods/computed props appear, and listed Twig blocks appear. Exit 0 ok, 1 on failures, 2 if dir missing. Regex based, not a JS parse. [verified]
- `tests/ci/admin-overrides.json`: only `sw-order-detail` (member `orderDetailTabs`, block `sw_order_detail_content_tabs_extension`). [verified]
- `tests/Fixtures.php`: static builders for RootItem, DeliveryPosition and similar cart structs, used by unit tests. [verified]

Build scripts: README (lines 54 and 425) tells devs to run `bin/build-storefront.sh` / `bin/build-administration.sh`. There is no `bin/` directory in the repo. [verified: `ls bin` fails] Prebuilt assets are committed per README. [verified: README line 54]

## Data model

No tables or migrations belong to this module. [verified]
What the integration tests need:
- Environment variables [verified: IntegrationTestCase.php docblock, seed.php output]:
  - `MN_MULTI_DELIVERY_SALES_CHANNEL_ID` (required; without it every integration test is skipped in setUp)
  - `MN_MULTI_DELIVERY_CUSTOMER_ID` (optional; without it only OrderConversionTest skips)
  - `APP_ENV` (default `prod`)
  - `SHOPWARE_DIR` (default `dirname(__DIR__, 5)` from IntegrationTestCase, i.e. shop root)
  - DB connection: not set by this repo. The kernel reads the usual Shopware env (`DATABASE_URL` etc.); CI relies on setup-extension to provide them. [inferred]
- `.env` handling: both `seed.php` and `IntegrationTestCase::container()` load `.env` through Symfony Dotenv only if `.env` or `.env.dist` exists in the shop root; otherwise values come from the process environment. [verified: seed.php, IntegrationTestCase.php; commits edaf04e, 715ef81 "CI: seed without .env", "CI: integration tests without .env"]
- Seeded rows (seed.php) [verified]:
  - products `MD-CI-PLENTY` (stock 50) and `MD-CI-FEW` (stock 2), restockTime 5, price 19.99 gross, visible (30) in the first storefront sales channel
  - customer `MD-CI-1`, `md-ci@example.com`, password `shopware-ci`, two addresses (Berlin, Hamburg) in the sales channel's country
  - `defaultPaymentMethodId` added only if the field exists in `CustomerDefinition` (older 6.7)
  - It reads tax, delivery_time, salutation, country and customer group from existing rows via SQL; throws "Missing test data" if any is missing.
- The tests pick products by SQL stock condition (`p.stock > 20`, `p.stock BETWEEN 1 AND 3`), not by the seeded product numbers. [verified: SplitCartTest.php, IntegrationTestCase::product()]
- The seed writes to the shop DB. It is not idempotent: fixed `customerNumber`, `email` and product numbers would collide on a second run. [inferred]
- Integration tests are read-only: carts stay in memory, orders are converted but not persisted, settings are overridden through `MemoizedSystemConfigStore` in-process and reset in tearDown. [verified: IntegrationTestCase.php]

## Dependencies
- Uses: `src/` classes under test; Shopware core test helpers `Shopware\Core\Test\Generator` and `Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService`; `examples/MnMultiDeliveryExample/src` (tested by ExamplePluginTest, analysed by PHPStan); `shopware/github-actions/setup-extension@main` (unpinned); `phpunit/phpunit ^11.5`, `phpstan/phpstan ^2.1`. [verified: composer.json, tests.yml, bootstrap.php, Fixtures.php, ExamplePluginTest.php]
- Used by: nothing in src depends on this module. Release/compat policy refers to the CI (CHANGELOG line 25, docs/compatibility.md). [verified: CHANGELOG.md]

## Patterns and conventions

- Namespaces: `Mn\MultiDelivery\Tests\Unit\<Area>\` mirrors `src/<Area>`; file name `<Class>Test.php`. [verified: tests tree]
- Attribute `#[CoversClass(X::class)]` on most unit tests; `#[CoversNothing]` on ExamplePluginTest. `failOnRisky` and `failOnWarning` are on. [verified: phpunit.xml.dist]
- Test method names are sentences, `testSomethingHappens`. [verified: SplitCartTest.php, OrderConversionTest.php]
- Unit tests use `createMock`, `Generator::generateSalesChannelContext`, `StaticSystemConfigService` and `Fixtures::*`; no DB. [verified: SeparateOrderCalculatorTest.php, Fixtures.php]
- Integration tests extend `Tests\Integration\IntegrationTestCase`, use reflection (`property()`) to reach private Shopware internals (CartService::calculator, config loader store, OrderPersister converter). They skip, not fail, if data is missing. [verified]
- PHPStan: level 8, paths `src`, `tests`, `examples/MnMultiDeliveryExample/src`, excludes `src/Resources`, bootstrap `tests/bootstrap.php`, `treatPhpDocTypesAsCertain: false`, `reportUnmatchedIgnoredErrors: false` because Shopware Commercial classes are unknown in CI. [verified: phpstan.neon.dist]
- Typical change: add `tests/Unit/<Area>/<Class>Test.php`, build inputs with `Fixtures`, run `composer test` and `composer phpstan`. [inferred]
- composer.lock is gitignored, so CI "highest" and "lowest" resolve fresh each run. [verified: .gitignore, tests.yml]

## Tests

Locations: `tests/Unit/{Cart,Example,Policy,Rule,SeparateOrder,Service,Strategy,Subscriber}`, `tests/Integration`. 22 unit test files, 3 integration files (about 3,000 lines in tests/ including scripts). [verified]

Integration tests (7 methods) [verified: grep]:
- SplitCartTest: manual plan splits a real cart; partial stock ships available part now; Rule Builder sees the split; allocation listeners change real carts; blocked payment handlers keep one delivery; shipping methods of other sales channels ignored.
- OrderConversionTest: each delivery becomes an order delivery with its group; `SplitOrderConvertedEvent` dispatched once.

Coverage per src area (by presence of a matching test file; no coverage run was possible) [verified for presence, inferred for depth]:

| src area | Tested | Not tested (no test file) |
|---|---|---|
| Cart | DeliveryPlan, SplitDeliveryProcessor | DeliveryGroupInfo (struct) |
| Strategy | Manual, Availability, DeliveryTime, Warehouse (parity test only), Allocation, StrategyRegistry | DeliveryGroup, RootItem, the interface |
| Policy | SameCountryAddressPolicy, ExtraDeliveryCostPolicy | DeliveryAddressPolicies |
| Rule | DeliveryCountRule | none |
| Service | DeliveryPlanService, SplitGuard, MailBlock, SplitSettings (country mode only) | CustomFieldInstaller |
| SeparateOrder | Calculator, Splitter, CartOrderRoute | ContextFactory (partly via Calculator mocks), Part, Split (structs) |
| Subscriber | CartHash, CartPage, ConfirmPage | DeliveryNoteSubscriber, OrderConversionSubscriber (only in the integration test, indirectly), OrderMailSubscriber, OrderPlacedCriteriaSubscriber, SeparateOrdersFinishPageSubscriber |
| Controller / Route | none | DeliveryPlanController, DeliveryPlanRoute, DeliveryPlanRouteResponse (security-reviewed in 1.2.2 per git log, but no test file) |
| Event, Exception | indirect only | all events, MultiDeliveryException |
| Plugin class | none | MnMultiDelivery.php (install/uninstall/lifecycle), Resources/config/*.php |
| Admin/Storefront JS, Twig, snippets | none (only the admin-overrides regex check) | everything else under src/Resources/app |

WarehouseStrategy has no direct unit test; WarehouseStrategyParityTest compares with SwagCommercial and skips if it is missing (`class_exists` check), so in CI it is likely always skipped. [verified: WarehouseStrategyParityTest.php lines 27-28; CI skip is inferred]
Rough impression: business logic (strategies, guard, processor, separate orders) is well covered; HTTP layer, lifecycle, mail/document subscribers and all front-end code are not. [inferred]

## Hotspots

Commits touching tests/CI (git log on tests, .github, phpunit/phpstan config) [verified]: 715ef81 and edaf04e (2026-11-13, .env-less CI), 0f5de34 (1.3.0), 73d957e (1.2.2), f1a5070 (1.2.1, compat checks in CI), 00bf3d9 (1.2.0), ca3432a (1.1.0), b7f3b4c (1.0.0).
Most-edited files: `tests/Unit/Cart/SplitDeliveryProcessorTest.php` (4), `tests/Unit/Service/DeliveryPlanServiceTest.php`, `tests/Integration/IntegrationTestCase.php`, `SplitCartTest.php`, `OrderConversionTest.php` (3 each), `tests/ci/seed.php` (2). [verified: git log --name-only]

## Risks

- Duplicated `.env` bootstrap logic in `tests/ci/seed.php` and `IntegrationTestCase::container()`; it has already broken CI twice (see commits above). [verified]
- Integration tests use reflection into Shopware private properties (`calculator`, `loader`, `memoizedSystemConfigStore`, `decorated`, `orderPersister`, `converter`); likely to break on trunk. Trunk is allowed to fail, so such breakage can go unnoticed. [verified code; inferred risk]
- `shopware/github-actions/setup-extension@main` is unpinned. [verified]
- Integration tests skip silently when env vars are missing. If seed output fails to reach `$GITHUB_ENV`, CI could be green with all integration tests skipped. [inferred from setUp skip + workflow]
- `bin/build-storefront.sh` and `bin/build-administration.sh` are referenced in README but do not exist. [verified]
- `composer.lock` ignored and `phpunit.phar` referenced without source in README. [verified]
- The `check-admin-overrides.php` regex check covers only one component and one member and block; other overrides are unchecked. [verified for the JSON; whether other overrides exist is not checked here]
- No coverage reporting in CI (`coverage: none`) and no coverage threshold. [verified]
- `composer.json` says `~6.7.14`, so CI "lowest" on the `static` job resolves to the 6.7.14 line. [inferred]
- The integration job does not run PHPStan against a shop with Commercial installed; Commercial-dependent code paths are not analysed. [inferred from phpstan.neon.dist comment]

## Open questions

- Why `phpunit.phar` in the README rather than `vendor/bin/phpunit`? Where do contributors get it?
- Were `bin/build-*.sh` ever committed, or are they planned? Where are the storefront and admin sources built?
- Is the SwagCommercial parity test ever run (locally or in CI)? Nothing in tests.yml installs Commercial.
- Why is `trunk` allowed to fail while 6.7.14 and 6.7.16 are required? Presumably early warning, which the workflow comment states ("next minor (early warning)"), but the policy for reacting to trunk failures is not documented.
- Which DB and env values does setup-extension provide to the integration step? Not visible in this repo.
- Should `admin-overrides.json` list more components? Does the plugin override other admin components?
- Is there a minimum coverage target for the tests?

## Verified by /onboard (2026-11-17)
Run in a dockware Shopware 6.7.16 shop (PHP 8.2), plugin copied into the container [verified]:
- Unit: `SHOPWARE_DIR=/var/www/html /var/www/html/vendor/bin/phpunit --testsuite unit` → 112 tests, 224 assertions, 8 skipped (all `WarehouseStrategyParityTest`, Commercial missing).
- PHPStan: phpstan 2.1 installed outside the plugin, `SHOPWARE_DIR=/var/www/html phpstan analyse --memory-limit=2G` → no errors.
- Integration: plugin installed and activated, `tests/ci/seed.php` → both `MN_MULTI_DELIVERY_*` variables, `../../../vendor/bin/phpunit --testsuite integration` → 7 tests, 15 assertions, all pass.
- Admin override check against 6.7.16 admin sources → 1 component checked, 0 problems.
- Confirmed: there is no `bin/` directory.
