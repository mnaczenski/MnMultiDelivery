# Module: Extension surface
_Path: src/Event, src/Strategy/DeliverySplitStrategyInterface.php, src/Policy/*Interface.php, src/Route/AbstractDeliveryPlanRoute.php, src/Resources/config/services.php (tags/decoration), Twig blocks in src/Resources/views, docs/compatibility.md, README.md "Extending" (l.188-395), examples/MnMultiDeliveryExample, tests/Unit/Example/ExamplePluginTest.php_

Paths below are relative to the plugin root (`MnMultiDelivery/`). Version of the plugin: 1.3.0 (`composer.json`) [verified].

## Responsibility
This module is the contract between the plugin and project code. It is made of five events, four PHP interfaces or abstract classes, three service tags, two decoration points, a set of Twig blocks and data keys. It carries a written compatibility policy (`docs/compatibility.md`) and an example project plugin that is unit-tested in the main plugin's suite. [verified: files above]
It has no logic of its own. The events are dispatched from the cart, order and route code that other modules own. [verified: `grep dispatch(` results below]

## Entry points and interfaces

### Public-API marker convention
Stable classes carry the docblock line `Public API: stable within a major version.` (23 classes, e.g. `src/Event/DeliveryAllocationEvent.php:19`). `DeliveryPlanService` says "the public methods are stable" (`src/Service/DeliveryPlanService.php:23`). Non-public classes carry `@internal` (see Risks). There is no `@api`, `@final` or `@deprecated` anywhere in `src/` (grep, PHP and JS excluded) [verified].

### Events (all extend `Symfony\Contracts\EventDispatcher\Event`, implement `ShopwareSalesChannelEvent`) [verified]
| Event | Dispatched at | Mutable via | Notes |
|---|---|---|---|
| `Mn\MultiDelivery\Event\DeliveryAllocationEvent` | `src/Cart/SplitDeliveryProcessor.php:153`, after strategy plus customer adjustments | `setAllocation(Allocation)` | getters: roots, strategy name, plan, cart, context |
| `...\SplitDeliveriesBuiltEvent` | `SplitDeliveryProcessor.php:163`, after pricing and `ShippingCostPolicyInterface::apply()`, before replacing Shopware's delivery | `setDeliveries()` or in-place; fewer than 2 cancels the split (`:164`) | promotions' delivery processor runs afterwards (docblock) |
| `...\DeliveryPlanChangedEvent` | `src/Service/DeliveryPlanService.php:299`, after a customer change and recalculation | read-only | constants `ACTION_TOGGLE/STRATEGY/GROUP/ADD_GROUP/REMOVE_GROUP/MOVE/RESET` (`:20-26`) |
| `...\SplitOrderConvertedEvent` | `src/Subscriber/OrderConversionSubscriber.php:89` | `setConvertedCart(array)` | payload is Shopware's OrderConverter output |
| `...\SeparateOrdersPlacedEvent` | `src/SeparateOrder/SeparateOrderCartOrderRoute.php:129` | read-only | orders list, main order last |

### Interfaces and abstract classes
- `src/Strategy/DeliverySplitStrategyInterface.php:14`: `getName`, `isAvailable`, `allowsManualMoves`, `allowsAdjustments`, `allocate(array $roots, DeliveryPlan, SalesChannelContext): Allocation`. Tag `mn.multi_delivery.strategy`. [verified]
- `src/Policy/SplitBlockerInterface.php:17`: `blockReason(Cart, SalesChannelContext, CartBehavior): ?string`. Tag `mn.multi_delivery.blocker`. Reason becomes snippet `mnMultiDelivery.blocked.<reason>`. [verified]
- `src/Policy/DeliveryAddressPolicyInterface.php:16`: `isAllowed(CustomerAddressEntity, SalesChannelContext): bool`. Tag `mn.multi_delivery.address_policy`; all policies must allow. [verified]
- `src/Policy/ShippingCostPolicyInterface.php:16`: `apply(DeliveryCollection, SalesChannelContext): void`. Not a tag. It is a single service, aliased to `ExtraDeliveryCostPolicy` (`src/Resources/config/services.php:98`), changed by decoration. [verified]
- `src/Route/AbstractDeliveryPlanRoute.php:15`: `getDecorated()`, `load()`, `change(string $action, ...)`. Alias is public (`services.php:192`). Shopware's usual abstract-route decoration pattern. [verified]
- Value types that implementers must build or read: `Allocation` (`withQuantities()` at `src/Strategy/Allocation.php:50`), `DeliveryGroup`, `RootItem` (all `final`, marked public), `DeliveryPlan`, `DeliveryGroupInfo`, `DeliveryPlanRouteResponse`. [verified]

### Tags and service wiring (`src/Resources/config/services.php`) [verified]
- Strategy tag priorities: manual 300, availability 200, deliveryTime 150, warehouse 100 (`:75-84`). Higher priority is listed higher for customers (README l.~246). The example uses 120.
- `StrategyRegistry`, `SplitGuard` and `DeliveryAddressPolicies` consume `tagged_iterator(...)` (`:72, :87, :94`).
- The header comment (`:57-64`) lists the five extension points and matches the README table.
- Cart processor and collector tags at priority -5050, "between DeliveryProcessor (-5000) and PromotionDeliveryProcessor (-5100)" (`:100-114`). This is an ordering contract that project plugins may rely on, but it is not in the policy. [inferred]
- Not extension points, but decorating Shopware: `SeparateOrderCartOrderRoute` decorates `CartOrderRoute` (`:133-134`).
- Policy decoration: `->decorate(SameCountryAddressPolicy::class)` is documented to "take over its tag" (README l.~296). [inferred: relies on Symfony decoration moving tags; no test covers it]

### Twig blocks (extend with `sw_extends`) [verified]
- `component/mn-multi-delivery/planner.html.twig`: 22 blocks `component_mn_multi_delivery*` (heading, blocked, intro, strategies, strategy_option, list, item, item_header, item_title, item_separate_order, item_meta, item_settings, item_address, item_shipping_method, item_new_address, item_positions, item_position, item_position_label, item_position_move, item_remove, empty_item, hint, actions).
- `delivery-overview.html.twig`: `component_mn_delivery_overview` plus `_heading/_item/_item_summary/_item_address/_item_positions`.
- `finish-order.html.twig`: `component_mn_multi_delivery_finish_order` plus `_header/_meta/_items`. `separate-orders.html.twig`: `component_mn_multi_delivery_separate_orders`.
- `page/checkout/summary.html.twig`: `page_checkout_summary_mn_separate_order`, `page_checkout_summary_mn_separate_total`.
- `page/checkout/cart/index.html.twig`: `mn_multi_delivery_cart_hint`.
- Admin: `mn-multi-delivery-card` (`src/Resources/app/administration/src/component/mn-multi-delivery-card/mn-multi-delivery-card.html.twig`) with 6 blocks `mn_multi_delivery_card[_details|_address|_shipping|_items|_actions]`, matching README l.~371-377.
- Mail: `mn-multi-delivery/mail/order-deliveries.{html,txt}.twig`, replaceable by shipping the same path.
- Storefront JS: plugin `MnMultiDelivery`, event `updated` (`src/Resources/app/storefront/src/mn-multi-delivery/mn-multi-delivery.plugin.js:88`).
- Page data: `page.extensions.mnMultiDelivery.separateOrders`, `mnSeparateOrders`, `mnMultiDeliveryCartHint`.

### Documentation of the surface
- `README.md` l.188-395 "Extending": task-to-API table, contract tables for strategies, events table, code samples taken from the example, Storefront/Mail/Admin/Data sections.
- `docs/compatibility.md`: the policy (what is public, what is not, version rules, CI checks, known Shopware-internals table).

## Data model
No tables or migrations in this module. The public data contract it promises is [verified in `docs/compatibility.md` l.20-26 and README l.379-386]:
- Cart extensions `mnDeliveryPlan` (`DeliveryPlan`) and `mnDeliveryGroup` (`DeliveryGroupInfo`), also `mnSingleDelivery` (`SplitDeliveryProcessor::SINGLE_DELIVERY_EXTENSION`, CHANGELOG 1.3.0).
- `order_delivery.customFields.mn_multi_delivery` and `order.customFields.mn_multi_delivery_orders`, registered by `CustomFieldInstaller`, which belongs to another module and is `@internal`.
- Rule Builder condition `mnMultiDeliveryCount` (`src/Rule/DeliveryCountRule.php`, public API), settings keys `MnMultiDelivery.config.*`, error codes of `MultiDeliveryException`.

## Dependencies
- Uses: Shopware core event contract (`ShopwareSalesChannelEvent`), Symfony DI tagged iterators, Shopware `AbstractCartOrderRoute`-style decoration. The types are `Cart`, `DeliveryCollection`, `OrderEntity` and `CustomerAddressEntity`. Event and interface signatures therefore also depend on Shopware 6.7 class shapes (`~6.7.14`). [verified: imports in `src/Event/*`, `src/Policy/*`]
- Used by (internal modules, [verified by dispatch/wiring greps]):
  - Cart and delivery plan: `src/Cart/SplitDeliveryProcessor.php`, `src/Service/DeliveryPlanService.php`, `src/Service/SplitGuard.php`.
  - Strategies: `src/Strategy/*` (the built-ins implement the interface), `StrategyRegistry`.
  - Separate orders: `SeparateOrderCartOrderRoute`.
  - Order conversion: `OrderConversionSubscriber`.
  - Storefront and Store API: `DeliveryPlanRoute`, `DeliveryPlanController`.
  - Tests: `tests/Unit/Cart/SplitDeliveryProcessorTest.php`, `tests/Unit/Service/DeliveryPlanServiceTest.php`, `tests/Unit/SeparateOrder/SeparateOrderCartOrderRouteTest.php`, `tests/Integration/*`.
- External consumers: the example plugin `examples/MnMultiDeliveryExample` (requires `mn/multi-delivery ^1.0`). Rotfuchs project plugins consuming the API were not examined. [inferred: none known]

## Patterns and conventions
A typical change [verified from code, README and CHANGELOG]:
- New extension point: a new event class in `src/Event/` with the `Public API: stable...` docblock. It implements `ShopwareSalesChannelEvent` and exposes `getContext()` from the sales channel context. Dispatch it by class name (`dispatcher->dispatch($event)`). Then add it to the README events table, to `docs/compatibility.md`, to the CHANGELOG "new" list and to the example plugin and `ExamplePluginTest`, if applicable.
- A new tagged service needs a tag constant in `services.php`, a `tagged_iterator` consumer and an entry in the header comment (`:57-64`).
- Mutable events use setter-and-replace (`setAllocation`, `setDeliveries`, `setConvertedCart`). Allocation is an immutable value object with `withQuantities()`.
- Replace rather than subclass: README says built-in classes may change; use interfaces, tags and decoration.
- Twig: new blocks are added to README "Storefront" lists and CHANGELOG (1.3.0 added `_item_new_address`, `mn_multi_delivery_cart_hint`).
- CHANGELOG states API additions in minor versions (`DeliveryPlanService::setGroupAddress()`, `DeliveryAddressPolicies::isCountryAllowed()`, 1.3.0), consistent with the policy.

## Tests
- `tests/Unit/Example/ExamplePluginTest.php` (4 tests, `#[CoversNothing]`). `tests/bootstrap.php:26` autoloads `Mn\MultiDeliveryExample\` from `examples/.../src`, and `phpstan.neon.dist:6` analyses the example (PHPStan level 8 per policy). [verified]
- Event dispatch points are covered: allocation and built events in `tests/Unit/Cart/SplitDeliveryProcessorTest.php:75,90`, plan-changed in `tests/Unit/Service/DeliveryPlanServiceTest.php:34`, separate-orders-placed in `tests/Unit/SeparateOrder/SeparateOrderCartOrderRouteTest.php:177`, allocation in `tests/Integration/SplitCartTest.php:60`, converted in `tests/Integration/OrderConversionTest.php`. Custom address and cost policies are exercised with anonymous classes or mocks in `SplitDeliveryProcessorTest.php:106-153`. [verified]
- Run: `composer test` in the plugin without a shop, or phpunit unit/integration testsuites per README l.397-425 and `.github/workflows/tests.yml:38,67`. Not run here (no PHP). [verified from docs]
- No test found that: decorates `AbstractDeliveryPlanRoute` or `ShippingCostPolicyInterface` through the container, checks the DI tags or priorities, or checks that every class named in `compatibility.md` carries the public docblock or `@internal`. [verified by grep; absence is inferred]

### Example plugin coverage
| Extension point | Example | Tested |
|---|---|---|
| `DeliverySplitStrategyInterface` + tag | `BulkyGoodsStrategy` | yes (`testBulkyItemsShipByFreight`) |
| `DeliveryAllocationEvent` | `KeepGiftCardsWithFirstDelivery` | yes |
| `SplitBlockerInterface` + tag | `PreorderBlocker` | yes |
| `SplitOrderConvertedEvent` | `ErpShipmentReference` | yes |
| Twig block `component_mn_multi_delivery_item_title` | `src/Resources/views/storefront/component/mn-multi-delivery/planner.html.twig` | no (rendering not tested) |
| Snippets (`strategies`, `strategyHints`, `groups`, `blocked`) | `src/Resources/snippet/*.json` | no |
| `SplitDeliveriesBuiltEvent`, `DeliveryPlanChangedEvent`, `SeparateOrdersPlacedEvent` | not shown | no |
| `DeliveryAddressPolicyInterface` + tag | not shown | no |
| `ShippingCostPolicyInterface` decoration | not shown | no |
| `AbstractDeliveryPlanRoute` decoration | not shown | no |
| Mail, admin card override, JS `updated` event, Rule Builder | not shown | no |
Summary: 2 of 5 events, 2 of 3 tags, 0 of 2 decoration points and 1 Twig block are exercised. README says the example "shows most of these extension points" (l.~192). That is only partly true. [verified]

## Hotspots
Git commit counts: docs/compatibility.md 3, services.php 3 [verified, git log]. Further proxies from CHANGELOG [inferred]:
- `src/Cart/SplitDeliveryProcessor.php` (two of five events, the ordering contract).
- `src/Resources/views/storefront/component/mn-multi-delivery/planner.html.twig` (most public blocks, 1.3.0 added two).
- `README.md` "Extending" section and `docs/compatibility.md`, which must change with every API change (changed in 1.2.1 and 1.3.0).
- `src/Service/DeliveryPlanService.php` (public methods grow each minor).

## Risks
Policy vs. code, with mismatches [verified unless marked]:
1. **No `@deprecated` and no `@final`.** The policy promises deprecation for at least one minor before removal (`compatibility.md` l.45). No deprecation exists yet, so it cannot be violated yet, but nothing enforces it. The interfaces and public value classes are not `final` or `@final` (only `Allocation`, `DeliveryGroup`, `RootItem`, `SeparateOrderSplit`, `SeparateOrderPart` are `final`). The events, `DeliveryPlan`, `SplitGuard` and others are extendable, and the policy does not say whether subclassing is supported.
2. **Interfaces are "stable" but expected to grow only in minors.** Adding a method to a public interface would break implementers; the policy says "new extension points" in minors but does not say interfaces will not gain methods. [inferred]
3. **Policy lists vs docblocks.** The classes listed as public in `compatibility.md` l.8-9 all carry the `Public API` docblock, and all `Public API` classes appear in the policy. The policy says controllers, subscribers, cart processor, installers and the separate-order splitter, calculator, context factory and order route decorator are `@internal`; all of these carry `@internal`. `SplitDeliveryProcessor` is `@internal` but public constants such as `SINGLE_DELIVERY_EXTENSION` are announced in the CHANGELOG, and the `mnSingleDelivery` extension is not in the policy data list. `DeliveryAddressPolicies`, `StrategyRegistry` and `MailBlock` are `@internal`, though they appear in the README only as consumers. The CHANGELOG 1.3.0 mentions `DeliveryAddressPolicies::isCountryAllowed()` as new, although the class is `@internal`. Unclear whether this is public. [verified/inferred]
4. **Twig block list in the policy is narrower than the code.** The policy names `page_checkout_summary_mn_separate_*` but not `mn_multi_delivery_cart_hint` (`page/checkout/cart/index.html.twig`). The `pending-order/*` templates and the Shopware blocks overridden by the plugin (`page_checkout_confirm_product_table`, `page_checkout_finish_order`, `page_account_order_item_detail_table_footer`, etc.) are not mentioned; overriding them in a theme may clash. Cart hint is in README but not the policy. [verified]
5. **README Events table is incomplete and the top table is broken.** The "You want to" table has a malformed row: `SeparateOrdersPlacedEvent` row at `README.md:203` has 4 columns (a "when/you can" fragment from the events table) and `README.md:204` holds a bare `SplitOrderConvertedEvent` cell. The "Events" section table (l.255-260) lists only four events and omits `SeparateOrdersPlacedEvent`.
6. **Example README is stale.** `examples/MnMultiDeliveryExample/README.md` names `Resources/views/…/split-delivery.html.twig`, but the file is `planner.html.twig`. Also `composer.json` of the example requires `shopware/core ~6.7.0` while the main plugin needs `~6.7.14`. README says "the snippets below are taken from it, shortened": the README `KeepGiftCards` and the real file differ slightly (constructor-configured product numbers, null check) [verified].
7. **Example runs only in unit tests.** It is never installed or compiled in a container, so its `services.php`, tags, priority and Twig override are untested. A broken tag name would not be caught. [inferred: only `tests/bootstrap.php` autoload and phpstan reference it]
8. **Per-calculation cost.** Allocation and built events run on every cart calculation (README l.~262); a slow listener slows every cart request. Documented, not guarded. [verified]
9. **Mutation hazards.** `SplitDeliveriesBuiltEvent` allows in-place edits of `DeliveryCollection`; listeners can produce inconsistent `mnDeliveryGroup` extensions. No validation after dispatch beyond the count check (`SplitDeliveryProcessor.php:164`). [inferred]
10. **Extension priority contract** (-5050 between Shopware processors) is only commented, not in the policy; `compatibility.md` "Known dependencies" table does not list the processor ordering. [verified]
11. **No `@since` tags** on public classes, so "new in 1.x" has to be taken from CHANGELOG. [verified by grep]

Dead code: none observed in this module's files. [verified within scope]

## Open questions
- Is subclassing of events and `DeliveryPlanService`/`SplitGuard` supported, or only use? Should the interfaces get `@final`/`@api` markers? (plugin author)
- Is `DeliveryAddressPolicies::isCountryAllowed()` meant to be public although the class is `@internal`? The CHANGELOG lists it as new API. (plugin author)
- Is the cart processor priority (-5050) a promised contract? (plugin author)
- Should a project plugin be able to rely on Shopware blocks the plugin overrides (pending-order, cart table)? (plugin author)
- Why is `ShippingCostPolicyInterface` decorate-only rather than tagged (several policies could not combine)? (plugin author)
- Does any live Rotfuchs project plugin (e.g. MnTieredPromotions) consume these events or tags? Needs checking in the other plugins' repositories. (shop owner / plugin author)
- Is the 1.x minimum for the example (`~6.7.0`) intentional? (plugin author)
