# Module: cart-delivery-plan (Cart & delivery plan)
_Path: src/Cart, src/Service, src/Policy, src/Rule, src/Exception (wired by src/MnMultiDelivery.php, src/Resources/config/services.php, src/Resources/config/config.xml)_

Paths below are relative to the plugin root. Hotspots below combine CHANGELOG.md and git history (10 commits) [verified, git log].

## Responsibility
Holds the customer's delivery plan as a cart extension and, inside Shopware's cart calculation, replaces the single delivery with several deliveries (own address and shipping method each) [verified: src/Cart/SplitDeliveryProcessor.php:124-169]. It also decides when a cart must not be split (guard), reads the merchant settings, prices the extra deliveries (cost policy), and offers one service used by both storefront and Store API to change the plan [verified: src/Service/*.php]. It contributes a Rule Builder condition, an exception class and a custom-field installer [verified: src/Rule, src/Exception, src/Service/CustomFieldInstaller.php].

## Entry points and interfaces
- `src/Cart/SplitDeliveryProcessor.php` - `CartProcessorInterface` + `CartDataCollectorInterface`, tagged `shopware.cart.processor` and `shopware.cart.collector`, priority -5050: after Shopware DeliveryProcessor (-5000), before PromotionDeliveryProcessor (-5100) [verified: services.php:~85-98, class docblock lines 40-55]. `collect()` loads the customer's addresses and the active, sales-channel-assigned shipping methods named in the plan (lines 80-122). `process()` does the split (124-169). Class is marked `@internal`.
- Process flow [verified: SplitDeliveryProcessor.php:124-169]: drop old `mnSingleDelivery` extension; get plan (cart extension, else `SplitSettings::defaultPlan`); return unless plan enabled and `SplitGuard::blockReason` is null; only proceed if the cart has exactly one delivery; collect `RootItem`s per top-level line item; `StrategyRegistry::get(...)->allocate()`; optional customer adjustments; dispatch `DeliveryAllocationEvent`; build deliveries; abort if fewer than 2; `DeliveryCalculator::calculate`; `ShippingCostPolicyInterface::apply`; dispatch `SplitDeliveriesBuiltEvent`; set deliveries and store the replaced single delivery's shipping costs in cart extension `mnSingleDelivery`.
- `src/Cart/DeliveryPlan.php` - Struct, cart extension `mnDeliveryPlan`; fields enabled, strategy, groups (addressId/shippingMethodId per group), assignments, manualGroups, adjustments. Constant `MAIN_GROUP = 'main'`. API alias `mn_delivery_plan`. Documented "Public API: stable within a major version" [verified].
- `src/Cart/DeliveryGroupInfo.php` - Struct on each split Delivery, extension `mnDeliveryGroup` (groupId, label, locked, warehouseId, warehouseName). Public API [verified].
- `src/Service/DeliveryPlanService.php` - public API used by storefront controller and Store API route (src/Controller/DeliveryPlanController.php, src/Route/DeliveryPlanRoute.php) [verified: services.php args]. Methods: `getPlan`, `toggle`, `setStrategy`, `setGroup`, `setGroupAddress`, `addGroup`, `removeGroup`, `move`, `reset`, `describe`. Private `change()` (line 288) is the common path: get cart, return unchanged if plugin inactive, mutate plan, `CartService::recalculate`, dispatch `DeliveryPlanChangedEvent`. Limits: `MAX_MANUAL_GROUPS = 5`, `MAX_CONFIGURED_GROUPS = 30`; ids validated as 32 hex chars (`hexOrNull`, line 351).
- `src/Service/SplitGuard.php` - `isActive()` (config `MnMultiDelivery.config.enabled`) and `blockReason()`. Built-in reasons in order: disabled, empty, quote (cart extension `originalQuoteId`), subscription (context extension `subscription`), recalculation (behavior flags or `MANUAL_SHIPPING_COSTS`), payment (config `blockedPaymentHandlers`, substring match on handler identifier), then tagged blockers [verified: SplitGuard.php:52-96].
- `src/Service/SplitSettings.php` - typed reader of `MnMultiDelivery.config.*` per sales channel. Constants COSTS_EACH/FREE/FLAT, COUNTRY_SAME/SEPARATE_ORDERS/ALL_SEPARATE. Also `defaultPlan()`, `separateOrdersAllowed()`, `separateOrderPaymentAllowed()` [verified].
- `src/Service/CustomFieldInstaller.php` - upserts two custom field sets (`mn_multi_delivery` on `order_delivery`, `mn_multi_delivery_order` on `order`); called from `src/MnMultiDelivery.php` install/update/uninstall (uninstall only when `!keepUserData`) [verified].
- `src/Service/MailBlock.php` - injects the "N deliveries" block into the order mail template source (markers START/END/MARKER/OFF); used by OrderMailSubscriber [verified: services.php, MailBlock.php]. Lives in Service but is mail/subscriber functionality.
- `src/Policy/` - extension interfaces (all "Public API"):
  - `DeliveryAddressPolicyInterface` - tag `mn.multi_delivery.address_policy`; every policy must allow. Default `SameCountryAddressPolicy` (same country as shipping location, or other country if `separateOrdersAllowed`). Aggregated by `DeliveryAddressPolicies` (`isAllowed`, `isCountryAllowed`, `@internal`).
  - `SplitBlockerInterface` - tag `mn.multi_delivery.blocker`; returns a reason string; storefront snippet `mnMultiDelivery.blocked.<reason>`.
  - `ShippingCostPolicyInterface` - decorate service id; default `ExtraDeliveryCostPolicy` (alias in services.php): most expensive delivery keeps price, others each/free/flat cap; flat price is gross and converted for net customers [verified: ExtraDeliveryCostPolicy.php].
- `src/Rule/DeliveryCountRule.php` - Rule Builder condition `mnMultiDeliveryCount`, tag `shopware.rule.definition`; matches cart deliveries (CartRuleScope) or order deliveries (FlowRuleScope) [verified]. Admin side: src/Resources/app/administration/src/decorator/rule-condition.js references it [verified by grep].
- `src/Exception/MultiDeliveryException.php` - extends `CartException`; codes `MN_MULTI_DELIVERY__SEPARATE_ORDER_PAYMENT` and `..._SEPARATE_ORDER_INVALID`; thrown only from src/SeparateOrder/SeparateOrderCartOrderRoute.php:79,91 [verified].
- Events dispatched by this module (classes live in src/Event, other agent): `DeliveryAllocationEvent`, `SplitDeliveriesBuiltEvent`, `DeliveryPlanChangedEvent` [verified].
- Plugin config surface: `src/Resources/config/config.xml` - cards for general (enabled default false, blockedPaymentHandlers default `Swag\PayPal`, cartHint default true), splitting rules (strategies, defaultStrategy `manual`, autoSplit false, deliveryTimeGroupDays 2, deliveryTimeMaxDeliveries 3), other countries (countryMode `sameCountry`, separateOrderPaymentHandlers default Invoice/Default payment handlers), shipping costs (extraDeliveryCosts `each`, extraDeliveryFlatPrice 4.9) [verified].

## Data model
- No database tables or migrations: there is no src/Migration directory [verified: `ls src`]. Plugin class has no migration code [verified].
- Plan lives in the cart extension `mnDeliveryPlan`, persisted with the cart by Shopware (the class docblock says it "survives every recalculation and cart persistence") [verified: DeliveryPlan.php:8; persistence mechanism itself [inferred] - Shopware serializes cart extensions].
- Custom fields (the only persistent schema): `customFields.mn_multi_delivery` JSON on `order_delivery`, set name `mn_multi_delivery`; `customFields.mn_multi_delivery_orders` JSON on `order`, set name `mn_multi_delivery_order` (note field name differs from set name) [verified: CustomFieldInstaller.php, SeparateOrderCartOrderRoute.php:43, OrderConversionSubscriber.php:25]. Values are written by other modules.
- System config keys `MnMultiDelivery.config.*` (see config.xml).

## Dependencies
- Uses: Shopware core (CartService, DeliveryCalculator, DeliveryProcessor, QuantityPriceCalculator, TaxCalculator, SystemConfigService, customer_address / shipping_method / custom_field_set repositories) [verified: services.php]. src/Strategy (`StrategyRegistry`, `Allocation`, `RootItem`; strategies tagged `mn.multi_delivery.strategy`) [verified]. src/Event (3 events). src/SeparateOrder (`SeparateOrderCalculator`, optional ctor arg of DeliveryPlanService, used only in `describeSeparateOrders`) [verified]. `OrderConversionSubscriber::CUSTOM_FIELD` and `SeparateOrderCartOrderRoute::CUSTOM_FIELD` constants (CustomFieldInstaller). SwagCommercial is touched only via string constants (`originalQuoteId`, `subscription`) in SplitGuard, no hard dependency [verified: SplitGuard.php:29-33]. Twig/TemplateFinder (MailBlock).
- Used by: Controller/DeliveryPlanController, Route/DeliveryPlanRoute, Subscriber (CartHash, CartPage, ConfirmPage, OrderConversion), SeparateOrder (CartOrderRoute, Splitter), all Strategy classes and `StrategyRegistry`, Event classes, Store API schema (src/Resources/Schema/StoreApi/components/schemas/MnMultiDelivery.json) [verified by grep for the module's classes].

## Patterns and conventions
- Everything is plain constructor injection, wired by hand in `src/Resources/config/services.php` (PHP container configurator, no autowiring); the file header comment lists extension points [verified].
- Extension via tagged iterators (`mn.multi_delivery.strategy|blocker|address_policy`), service decoration (`ShippingCostPolicyInterface`, `AbstractDeliveryPlanRoute`), and events [verified: services.php header].
- Public vs internal is declared in docblocks: "Public API: stable within a major version" vs `@internal` [verified].
- Cart state is kept in Struct extensions (`mnDeliveryPlan`, `mnDeliveryGroup`, `mnSingleDelivery`); the processor never mutates the line items, it rebuilds deliveries with partial `DeliveryPosition`s [verified: SplitDeliveryProcessor.php:300-334].
- Typical change: a new setting means config.xml entry + typed getter in `SplitSettings` + use site; a new split reason means a `SplitBlockerInterface` or a `SplitGuard` constant + snippet `mnMultiDelivery.blocked.<reason>`; a new plan action means a `DeliveryPlanService` method via `change()` + a `DeliveryPlanChangedEvent::ACTION_*` constant + route/controller entry [inferred from structure and CHANGELOG 1.3.0].
- Security validation is spread: ids hex-checked in service, address ownership and policy checked in processor `location()` (line 370), shipping method active + sales channel filter in `collect()` (114-117) [verified; CHANGELOG 1.2.2].

## Tests
- Unit: tests/Unit/Cart (DeliveryPlanTest 4 tests, SplitDeliveryProcessorTest 12), tests/Unit/Service (DeliveryPlanServiceTest 12, SplitGuardTest 8, SplitSettingsCountryModeTest 4, MailBlockTest 8), tests/Unit/Policy (ExtraDeliveryCostPolicyTest 5, SameCountryAddressPolicyTest 1), tests/Unit/Rule (DeliveryCountRuleTest 3) [verified; counts from grep of `function test`].
- Integration (need a Shopware install with the plugin active): tests/Integration/SplitCartTest.php (6), OrderConversionTest.php (1), base class IntegrationTestCase.php [verified].
- Run: `composer test` after `composer install` in the plugin (CHANGELOG 1.2.1); phpunit config phpunit.xml.dist with suites `unit` and `integration`, failOnRisky/failOnWarning [verified]. Not run by me (no PHP available). CI details: tests/ci, docs/compatibility.md (other agent).
- Coverage impression [inferred]: good for the processor happy path, events, address and shipping-method safety, adjustments. No unit tests found for CustomFieldInstaller, `MultiDeliveryException`, `SplitSettings` beyond country mode, or `DeliveryPlanService::describe` separate-order details (not checked in depth).

## Hotspots
Git commit counts: DeliveryPlanService.php 5, SplitSettings.php 4, services.php 3, SplitDeliveryProcessor.php 3, CustomFieldInstaller.php 2 [verified, git log].
- src/Cart/SplitDeliveryProcessor.php (384 lines, newest edit Nov 12; touched by 1.2.2 security fixes and 1.3.0).
- src/Service/DeliveryPlanService.php (355 lines, edited Nov 12; 1.3.0 added `setGroupAddress`, `extraShippingCosts`; 1.1/1.2 added separate-order description).
- src/Service/SplitSettings.php (Nov 12; one getter per config key, grows with every setting).
- src/Policy/DeliveryAddressPolicies.php (Nov 12, `isCountryAllowed` in 1.3.0), SameCountryAddressPolicy (Nov 5).
- src/Resources/config/services.php (193 lines; every new class needs an entry).

## Risks
- Split only happens when the cart has exactly one delivery (SplitDeliveryProcessor.php:138); another plugin that creates several deliveries before -5050 silently disables splitting [verified; consequence inferred].
- Priority coupling: -5050 sits between Shopware core processors with hard-coded priorities (services.php, class docblock); a core change to those priorities breaks promotion handling [inferred].
- `partialPosition()` scales taxes with `quantity/position qty` and prices with `round(unitPrice*quantity, 2)` (lines 316-331); rounding differences between parts and the original are possible and untested at the cent level [inferred]. Child line items (bundles) share the root's quantity (`root->positions` loop, line 264) [inferred].
- ~~Silent fallbacks~~ Changed in 1.4.0 (ADR-0013): an unusable address keeps the main address and adds the blocking `DeliveryAddressBlockedError`; an unavailable shipping method keeps the main method and adds the warning `DeliveryShippingMethodChangedError` (`SplitDeliveryProcessor::addFallbackErrors()`) [verified, tests].
- `SplitGuard` matches blocked payment handlers and quote/subscription by string constants of SwagCommercial internals (SplitGuard.php:29-33); renames upstream break detection without any compile error [verified; risk inferred].
- `setGroup()` stores any address id that passes hex check; ownership is only enforced at calculation time [verified: DeliveryPlanService.php:71-84].
- `SplitDeliveryProcessor::process()` calls `guard->blockReason` which reads system config per call; `collect()` and `process()` both evaluate it (lines 83, 132) [verified; performance impact inferred and probably small due to SystemConfigService caching].
- Housekeeping: orphan duplicate docblock in SplitSettings.php:55-61 (the "plan of a cart..." comment sits above `cartHint`, not `defaultPlan`); double blank line SplitDeliveryProcessor.php:205-206; `plan()` logic duplicated in processor and `DeliveryPlanService::getPlan` [verified].
- `MailBlock` (mail templating) is in Service although unrelated to the plan; `DeliveryCountRule` has a compiled admin bundle in src/Resources/public/administration/assets checked in [verified: grep hit]; mail injection relies on a Shopware template anchor string `{% set billingAddress = ... %}` (MailBlock.php ANCHOR) that may change upstream [verified; risk inferred].
- Error code `MN_MULTI_DELIVERY__COUNTRY_NOT_ALLOWED` (CHANGELOG 1.3.0) is not in `MultiDeliveryException`; it is a constraint violation built in src/Controller/DeliveryPlanController.php:33,86 with snippets in src/Resources/snippet/*.json:89. Error codes are therefore split over two places [verified].
- Cart mutation in `describe()` / `getPlan()` returns a fresh default plan object when no extension exists, so writes to it are lost unless `change()` attaches it [verified: `change()` line 296].

## Open questions
- Why exactly -5050 and why only carts with exactly one delivery are split? Ask the plugin author.
- Why is PayPal blocked by default (config helpText says PayPal sends only the first delivery's shipping cost)? Is this still true for current Swag PayPal versions? Plugin author.
- Intended policy for the price of the "most expensive delivery keeps its price" rule and the flat default 4.9: product owner.
- Is `autoSplit` meant to default to false for all merchants, and is the default plan applied to guests? Product owner.
- Should a rejected address/shipping method fall back silently, or should the customer see an error? Plugin author / product owner.
- Is it intended that error codes live both in `MultiDeliveryException` and the controller? Plugin author.
- Why do custom field names differ from set names (`mn_multi_delivery_orders` vs `mn_multi_delivery_order`)? Plugin author.
