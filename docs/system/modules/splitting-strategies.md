# Module: Splitting strategies
_Path: src/Strategy (+ wiring in src/Resources/config/services.php lines 74-87)_
_Mapped 2026-11-17. Hotspots from size/fan-in plus git history (WarehouseStrategy.php and DeliveryTimeStrategy.php: 2 commits each). [verified, git log]_

## Responsibility
Given the cart's top-level items ("roots") and the customer's DeliveryPlan, a strategy decides which delivery groups exist and which quantity of each item goes into which group; it returns an immutable `Allocation`. [verified: src/Strategy/DeliverySplitStrategyInterface.php, Allocation.php]
Four built-in strategies exist (manual, availability, deliveryTime, warehouse), plus a registry that picks the active one with fallbacks. Third parties add strategies through a tagged service. [verified: services.php:74-87, README.md:196-243]
The module only computes the allocation. Building the actual cart deliveries, prices and dates happens in the cart module. [verified: src/Cart/SplitDeliveryProcessor.php:148]

## Entry points and interfaces
- `DeliverySplitStrategyInterface` (src/Strategy/DeliverySplitStrategyInterface.php): `getName()`, `isAvailable(ctx)`, `allowsManualMoves()`, `allowsAdjustments()`, `allocate(array<string,RootItem> $roots, DeliveryPlan, ctx): Allocation`. Documented as public API, stable within a major version. [verified]
- Extension point: service tag `mn.multi_delivery.strategy` (priority = display order). Built-ins use priorities manual 300, availability 200, deliveryTime 150, warehouse 100. [verified: services.php:75-84]
- `StrategyRegistry` (src/Strategy/StrategyRegistry.php, marked `@internal`):
  - `get(name, ctx)` tries the requested name, then the merchant default (`SplitSettings::defaultStrategy`, falls back to 'manual'), then the first available, then ManualStrategy. [verified: lines 36-46]
  - `availableNames(ctx)` filters by the "Rules customers can choose" setting (`strategies`). The setting only limits the four BUILT_IN names; third-party strategies are only filtered by their own `isAvailable()`. Empty setting means all offered. [verified: lines 51-63; SplitSettings.php:40-53]
- Value objects (public API per docblocks):
  - `Allocation` (final): groups keyed by id in order; the constructor throws if a quantity references an unknown group; `withGroup()` and `withQuantities()` return copies. [verified]
  - `DeliveryGroup` (final): id must match `/^[A-Za-z0-9-]{1,64}$/`; fields label, locked, inStockDates, warehouseId, warehouseName. [verified]
  - `RootItem` (final): lineItem plus DeliveryPositions; `isSplittable()` is true only for exactly one position whose identifier equals the line item id. Bundles are never split. [verified]
- Built-ins:
  - `ManualStrategy` (NAME 'manual'): groups are `main` plus `plan->getManualGroups()` labelled 2..n. Unassigned quantity stays in main; assignments are capped at the cart quantity; assignments to removed groups are ignored. Non-splittable items go as a whole to the group holding most of them. allowsManualMoves = true, allowsAdjustments = false. [verified: ManualStrategy.php:39-73]
  - `AvailabilityStrategy` ('availability'): groups `now` (inStockDates true) and `later`. Partial stock on a splittable item gives stock to now and the rest to later. Otherwise an item goes to later if it is a backorder (stock < quantity) or its earliest date is more than 1 day after the earliest date in the cart. Adjustments allowed, no manual moves. [verified: AvailabilityStrategy.php:41-79]
  - `DeliveryTimeStrategy` ('deliveryTime'): builds parts (partial stock is split into an in-stock part and a rest part), sorts them by earliest date, and bands them by `deliveryTimeGroupDays`. If there are more bands than `deliveryTimeMaxDeliveries` (default 3), the latest bands are merged. Groups are `time-1..n`; inStockDates is true only for bands without backorders. [verified: DeliveryTimeStrategy.php:47-108; SplitSettings.php:76-86]
  - `WarehouseStrategy` ('warehouse'): one group `wh-<warehouseId>` per source warehouse, plus `main` for non-product or non-splittable items and any remainder. `allocateProduct()` mirrors SwagCommercial `MultiWarehouseStockUpdater::calculateOrderStocks()`. `isAvailable()` needs `Shopware\Commercial\Licensing\License::get('MULTI_INVENTORY-3749997')` plus at least one row in `warehouse`. No manual moves, no adjustments. [verified: WarehouseStrategy.php:26-153]
- Settings consumed: `strategies`, `defaultStrategy`, `deliveryTimeGroupDays`, `deliveryTimeMaxDeliveries` (src/Service/SplitSettings.php; src/Resources/config/config.xml lines ~35-107). [verified]
- Snippet keys by strategy name: `mnMultiDelivery.strategies.<name>` and `mnMultiDelivery.strategyHints.<name>` (README.md:243; src/Resources/snippet/*.json). [verified in README, snippets partially checked]

## Data model
- No tables, entities or migrations in this module. [verified: src/Strategy contains only the 8 classes]
- WarehouseStrategy reads Commercial tables with raw SQL: `product`, `product_warehouse_group`, `warehouse_group`, `warehouse_group_warehouse`, `product_warehouse`, `warehouse` (WarehouseStrategy.php:164-193, 243). [verified]
- Strategy output reaches persistence elsewhere: group info goes to the order delivery custom field `mn_multi_delivery` (group, label, warehouseId, warehouseName) via src/Subscriber/OrderConversionSubscriber.php:82 and src/Service/CustomFieldInstaller.php. [verified]
- The strategy name and the manual groups, assignments and adjustments are stored in the cart extension `mnDeliveryPlan` (`DeliveryPlan`, cart module). [verified: README.md:381]

## Dependencies
- Uses:
  - `Mn\MultiDelivery\Cart\DeliveryPlan` (MAIN_GROUP, getManualGroups, getAssignments, getStrategy). [verified]
  - `Service\SplitSettings`. [verified]
  - Doctrine DBAL `Connection` (warehouse only). [verified]
  - Shopware core `LineItem`, `DeliveryPosition`, `DeliveryDate`, `SalesChannelContext`. [verified]
  - Optional SwagCommercial License class, called by name through `class_exists`/`call_user_func`. [verified: WarehouseStrategy.php:43]
- Used by:
  - `Cart/SplitDeliveryProcessor` (constructor arg at line 69; `get()` and `allocate()` at 147-148; adjustments at 149 and 163-195; `buildDeliveries` reads group fields). [verified]
  - `Service/DeliveryPlanService` (lines 35, 124-188, 310-314: uses the capability flags to decide whether the customer may move items and whether the plan is "adjustable"). [verified]
  - Wiring: services.php:102-124 (two consumers receive the registry). [verified]
  - `Subscriber/OrderConversionSubscriber` and `Cart/DeliveryGroupInfo` carry warehouse data. [verified]
  - Extension event `DeliveryAllocationEvent` lets plugins replace the Allocation after the strategy and the adjustments. It belongs to the extension-events module. [verified: SplitDeliveryProcessor.php:151-153]
  - The Store API route `POST /store-api/checkout/multi-delivery/strategy` selects a strategy by name (README.md:122). [verified in README only]

## Patterns and conventions
- A strategy is a stateless service. Constructor dependencies are only SplitSettings or Connection. It has `NAME` and `GROUP_*` constants, and `allocate()` is pure apart from reads. [verified]
- Built-in classes are not `final`, and their docblocks say "replace or decorate through interface or service id; the class itself may change". Value objects are `final` and readonly. [verified]
- Adding a rule: implement the interface, tag it `mn.multi_delivery.strategy` with a priority, add snippets `strategies.<name>` and `strategyHints.<name>` (README.md:196-243). A new built-in additionally needs `StrategyRegistry::BUILT_IN`, the two `config.xml` option lists, and a services.php entry. [inferred from registry and config.xml]
- Group ids are stable strings: 'main', 'now', 'later', 'time-N', 'wh-<uuid>', or a manual uuid. They are reused as keys for the customer's per-delivery address and shipping choice. [verified: DeliveryGroup.php docblock]
- Capability flags drive the UI and API behaviour. Manual = manual moves only. Availability and deliveryTime = adjustments only. Warehouse = neither. [verified]
- `inStockDates` marks groups whose partial quantities are dated by the normal delivery time rather than the restock date. The processor clears it when an adjustment adds items to such a group. [verified: SplitDeliveryProcessor.php:189-191]

## Tests
- Location: tests/Unit/Strategy (AllocationTest, AvailabilityStrategyTest, DeliveryTimeStrategyTest, ManualStrategyTest, StrategyRegistryTest, WarehouseStrategyParityTest). [verified]
- Run: no local PHP or Composer here, so not run. Presumably PHPUnit through the plugin's CI (see the tests/CI module). [inferred]
- Coverage impression: good for the pure logic.
  - Allocation validation, group id validation and the immutable `with*` methods.
  - Availability: partial stock and later items.
  - DeliveryTime: banding, max-merge, wide window, partial stock with inStockDates.
  - Manual: remainder stays in main, cap, removed groups.
  - Registry: filter, third-party strategies, fallbacks. [verified]
- Gaps (checked by reading the test file names and methods):
  - No test of `WarehouseStrategy::allocate()`, `loadWarehouseStocks()` (SQL) or `isAvailable()`.
  - The parity test only covers `allocateProduct()`, and it is skipped when SwagCommercial is not installed.
  - No test of non-splittable bundles in Availability or DeliveryTime.
  - No test of the manual strategy with a bundle. [verified/inferred]

## Hotspots
Ranked by size and fan-in [inferred]; git: WarehouseStrategy.php 2 commits, DeliveryTimeStrategy.php 2 [verified, git log].
1. src/Strategy/WarehouseStrategy.php (250 lines): raw SQL and Commercial coupling, the most likely to break on Commercial updates (the repo memory already pins core/commercial pairs).
2. src/Strategy/DeliveryTimeStrategy.php (114): date and banding logic with several settings.
3. src/Strategy/StrategyRegistry.php: fallback and filter rules; every consumer goes through it.
4. src/Resources/config/services.php:74-87 (wiring) and `config.xml` option lists, which must stay in sync with the strategy names.
5. The consumer side, src/Cart/SplitDeliveryProcessor.php (strategy contract: group fields, `inStockDates`).

## Risks
- WarehouseStrategy copies Commercial internals: the SQL query, group selection and `calculateOrderStocks` logic. The comment claims it predicts the allocation "in the same request". If Commercial changes, the prediction silently diverges; the only guard is the parity test, which skips without Commercial. [verified: WarehouseStrategy.php:15-21, parity test]
- The license toggle `MULTI_INVENTORY-3749997` is hardcoded (line 28). If Shopware renames it, the strategy silently becomes unavailable. [verified]
- `isAvailable()` runs a DB query (`SELECT 1 FROM warehouse`) on every call, and `allocate()` calls it again. The registry calls it for every `availableNames()`, so this is one query per call with no cache. Failures are swallowed with `catch (\Throwable)`. [verified: lines 40-52, 81; inferred on frequency]
- `warehouse` group handling: if `$warehouses[$productId]` is missing (product not assigned to a warehouse group), `$perWarehouse` is empty and the whole quantity goes to `main`. Intended or not is unknown. [verified behaviour, intent open]
- `loadWarehouseNames` uses `LOWER(HEX(id))` keys and `fetchAllKeyValue`; the group label falls back to the raw warehouse id when no name exists, which could leak a UUID to customers. [verified: line 110]
- Availability uses "+1 day" tolerance relative to the earliest date in the cart (AvailabilityStrategy.php:65), a magic number, not configurable and not mentioned in the docblock. [verified]
- DeliveryTimeStrategy `parts` are untyped positional arrays (`$a[2]`). `usort` is stable in PHP 8, but order among equal dates depends on cart order. [verified; the stability point is inferred]
- DeliveryTimeStrategy: with `deliveryTimeGroupDays = 0`, only identical dates group. A `deliveryTimeMaxDeliveries` below 1 is coerced to 3. [verified: SplitSettings.php:76-86]
- `StrategyRegistry::get()` ends with `$this->strategies[ManualStrategy::NAME]`. If someone replaces or removes the manual service, or a third party registers under the name 'manual', this could be missing or replaced. Duplicate names from different plugins silently overwrite each other (the later tag wins). [verified: lines 31-33, 45]
- `BUILT_IN` filtering means a third-party strategy cannot be disabled through the merchant setting, although the admin option list only offers built-ins. [verified/inferred]
- The interface docblock promises "stable within a major version", but the interface already carries two capability flags, and any addition breaks implementers. [inferred]
- The `locked` flag is only informational and has inconsistent values: warehouse `main` group defaults to locked=true, while Manual uses false. [verified]
- Tag priority order = customer display order; third-party priorities (README example 120) interleave between the built-ins. [verified: README.md:236]

## Open questions
- Why does the availability rule use a 1-day tolerance, and why a default maximum of 3 deliveries in deliveryTime? Ask the plugin author. [unverified WHY]
- Is it intended that products not assigned to any warehouse group all go to the `main` group in warehouse mode? Ask the plugin author or product owner.
- Is the hardcoded license toggle `MULTI_INVENTORY-3749997` confirmed stable across Commercial versions? Ask the plugin author.
- Should third-party strategies also be limited by the merchant's "Rules customers can choose" setting? Ask the product owner.
- Is the Store API strategy route and its error handling for unavailable names (fallback versus error) specified? Not checked here; belongs to the Store API module.
