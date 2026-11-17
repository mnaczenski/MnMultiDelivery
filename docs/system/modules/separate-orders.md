# Module: separate-orders ("Separate orders")
_Path: src/SeparateOrder (plus src/Subscriber/SeparateOrdersFinishPageSubscriber.php, src/Subscriber/OrderConversionSubscriber.php, wiring in src/Resources/config/services.php)_

Legend: [verified] = seen in code or tests, [inferred] = my interpretation. All paths are relative to the plugin root (`MnMultiDelivery/`). Line numbers refer to the state read on 2026-11-17 (no git repo in this checkout, so no git-log hotspots; see Hotspots).

## Responsibility

When the merchant setting `countryMode` is `separateOrders` (hybrid) or `allSeparate` (every delivery), this module divides the calculated cart along its deliveries into a main cart and one cart per separate order. It calculates every cart with a sales channel context for its own shipping address, so Shopware computes VAT, prices and shipping rules for that country. At checkout it places the separate orders and then the main order through Shopware's own order route, links them via a custom field, and shows all of them on the finish page. [verified: src/SeparateOrder/SeparateOrderSplitter.php:15-31, src/SeparateOrder/SeparateOrderCartOrderRoute.php:25-39, src/Service/SplitSettings.php:20-27]

Only the main order goes through the storefront payment step; the module therefore only works with payment handlers on a merchant-maintained allow-list. [verified: src/Service/SplitSettings.php:131-143, src/Resources/config/config.xml:146-157]

## Entry points and interfaces

| Entry point | File | Notes |
|---|---|---|
| `SeparateOrderCartOrderRoute::order()` | src/SeparateOrder/SeparateOrderCartOrderRoute.php:66 | Decorator of Shopware `CartOrderRoute` (services.php:133-145, `->decorate(CartOrderRoute::class)`). Used by storefront checkout and `POST /store-api/checkout/order`. [verified] |
| `SeparateOrderCalculator::calculate()` | src/SeparateOrder/SeparateOrderCalculator.php:36 | Returns `null` or `['split' => SeparateOrderSplit, 'contexts' => [groupId => SalesChannelContext]]`. Called by the route and by `DeliveryPlanService::describeSeparateOrders()` (src/Service/DeliveryPlanService.php:215-230) for the confirm page / planner / Store API. [verified] |
| `SeparateOrderCalculator::discard()` | same file:65 | Deletes the cart stored under a throwaway token; never the customer's token. [verified] |
| `SeparateOrderSplitter::split()` | src/SeparateOrder/SeparateOrderSplitter.php:41 | Pure splitting of a calculated cart; returns `null` when nothing goes abroad or mode is `sameCountry`. [verified] |
| `SeparateOrderContextFactory::create()` | src/SeparateOrder/SeparateOrderContextFactory.php:30 | Builds a never-stored context with the part's shipping address, country and (optionally) shipping method. [verified] |
| `SeparateOrderSplit`, `SeparateOrderPart` | src/SeparateOrder/SeparateOrderSplit.php, SeparateOrderPart.php | Value objects, marked "Public API: stable within a major version" (docs/compatibility.md:13). Splitter, calculator, context factory and route are `@internal`. [verified] |
| `SeparateOrdersFinishPageSubscriber` | src/Subscriber/SeparateOrdersFinishPageSubscriber.php:41 | Listens to `CheckoutFinishPageLoadedEvent`; adds page extension `mnSeparateOrders` (`orders`, `others`, `totalPrice`). [verified] |
| `OrderConversionSubscriber` | src/Subscriber/OrderConversionSubscriber.php | Not specific to this module (see Dependencies). [verified] |

Events and extension points
- `Mn\MultiDelivery\Event\SeparateOrdersPlacedEvent(groupId, orders, context)` is dispatched after all orders exist and are linked; main order is last in `orders` (src/Event/SeparateOrdersPlacedEvent.php; dispatched at route:129). Public API. [verified]
- Each order also fires Shopware's own `CheckoutOrderPlacedEvent` because it is placed via the decorated route (route docblock). [verified: comment; behaviour is Shopware's] 
- Error codes `MN_MULTI_DELIVERY__SEPARATE_ORDER_PAYMENT` (payment handler not allowed) and `MN_MULTI_DELIVERY__SEPARATE_ORDER_INVALID` (a part cart has blocking errors), both HTTP 400 (src/Exception/MultiDeliveryException.php:16-40). [verified]
- Order custom field `mn_multi_delivery_orders` = `{group, orders[{id, orderNumber, countryIso, country, amountTotal, currencyIso}]}`, public contract (docs/compatibility.md:19). [verified]
- Twig: page extension `mnSeparateOrders` is used by src/Resources/views/storefront/page/checkout/finish/index.html.twig and the `separate-orders.html.twig` component (compatibility.md:24-25). [verified for existence of references; template contents not read]

Wiring (src/Resources/config/services.php:126-145, 170-172) [verified]
- Splitter(SplitSettings), ContextFactory(SalesChannelContextFactory, SalesChannelContextPersister), Calculator(Splitter, ContextFactory, CartCalculator, CartPersister), Route(inner, Calculator, SplitSettings, CartContextHasher, CartPersister, order.repository, StateMachineRegistry, event_dispatcher, logger). Calculator is also injected (nullable) into `DeliveryPlanService` (services.php:123, DeliveryPlanService.php:38).

### Flow of `order()` (route:66-132) [verified]
1. `calculator->calculate()`; if `null`, delegate unchanged to Shopware.
2. Cart hash (`hash` request param) is checked once against the whole, undivided customer cart; the `hash` is then removed from the data passed on to part orders.
3. `settings->separateOrderPaymentAllowed()` else `separateOrderPaymentNotAllowed` is thrown (nothing placed).
4. Every part cart is scanned for `blockOrder()` errors; if any, `separateOrderInvalid` is thrown (nothing placed). Note: the **main** cart's errors are NOT checked here; Shopware's own route checks it when placing last.
5. Separate orders are placed in order, then the main order (last) with the customer's context. If no main cart items remain, the customer's cart is deleted by the module and the last separate order is returned as the response.
6. On any `\Throwable`, part carts are discarded, already-placed orders are cancelled (state machine transition `cancel`), the exception is rethrown.
7. Orders are linked (`orderRepository->update` of custom fields) and `SeparateOrdersPlacedEvent` is dispatched.

### Splitting rules (SeparateOrderSplitter) [verified]
- Hybrid: every delivery whose address country differs from the shipping location country becomes a part (splitter:55-62). Every delivery (`allSeparate`) with an allowed payment: only the "kept" delivery stays (main group's delivery in the home country, else the first home delivery, else none) (`mainDelivery`, :136-152). Without an allowed payment `allSeparate` behaves like hybrid (:50-52; test testEveryDeliveryModeSplitsLikeHybridWithoutAnAllowedPayment).
- Items move as whole root line items; containers move with all children, quantity of a child-position delivery = root quantity (:64-75).
- Part carts get `DeliveryPlan(false)` (no further splitting) and fresh random token; line items are cloned and "refreshed" (`dataTimestamp`/`dataContextHash` cleared) so Shopware reloads prices/tax for the new country (:100-115, 214-223).
- Promotions/vouchers and other non-delivery items stay in the main cart (docblock :26-28; no code path moves them).
- `forget()` removes manual groups / clears the group's address settings from the main cart's `DeliveryPlan` so remaining items are not sent abroad (:199-208).
- `SeparateOrderPart::groupId` falls back to `DeliveryPlan::MAIN_GROUP` when a delivery has no `DeliveryGroupInfo` extension (:81).

### Cart-token hygiene (SeparateOrderCalculator) [verified]
Shopware's `CartRuleLoader` can persist a cart under its token during calculation. The module gives main cart a throwaway token and each part the token of its own context, and deletes the persisted copies after calculating (`calculateWithoutStoring`, calculator:74-80). The main cart is calculated on a cloned context because calculation mutates rules (:53, 82-88). Part carts are tokenised with the part context's token because Shopware's order route deletes the cart under its context's token after placing (:48-49).

### Context factory [verified]
Starts from the persisted context of the customer's token (so e.g. Commercial B2B employee data carries over), removes `expired`, `token`, `sw-shipping-address`, rule ids and area rule ids, then sets customer, billing address, shipping address, country, state, payment method, shipping method (part's or current), language, currency, domain (factory:33-55). Created through `SalesChannelContextFactory::create` with a random token, not persisted.

## Data model

- No own tables and no migrations: `src/Migration` does not exist. [verified]
- Order custom field set for entity `order` with JSON field `mn_multi_delivery_orders` is created by `src/Service/CustomFieldInstaller.php:55-72` (removed on uninstall, :78-81). [verified]
- `order_delivery.customFields.mn_multi_delivery` (`group`, `label`, `warehouseId`, `warehouseName`) is written by `OrderConversionSubscriber` (constant `CUSTOM_FIELD = 'mn_multi_delivery'`), belongs to the split-delivery feature, not to separate orders. [verified: OrderConversionSubscriber.php:25,78-85]
- Config (system config `MnMultiDelivery.config.*`, src/Resources/config/config.xml:~130-157): `countryMode` (`sameCountry` default, `separateOrders`, `allSeparate`) and `separateOrderPaymentHandlers` (textarea; default Shopware Invoice/Pre/Cash/Debit/DefaultPayment class names). Read by `SplitSettings::countryMode()` (:107) and `separateOrderPaymentAllowed()` (:131). [verified]
- Link data lives only in order custom fields (JSON per order, duplicated on every order of the group). [verified: route:140-164]

## Dependencies

- Uses (inside the plugin):
  - `Service/SplitSettings` (mode, allowed payment) [verified]
  - `Cart/DeliveryPlan`, `Cart/DeliveryGroupInfo` (group id, label, `MAIN_GROUP`, manual groups, group settings) [verified: splitter imports]
  - `Exception/MultiDeliveryException`, `Event/SeparateOrdersPlacedEvent` [verified]
  - `Service/CustomFieldInstaller` refers to `SeparateOrderCartOrderRoute::CUSTOM_FIELD` [verified]
- Uses (Shopware): `CartOrderRoute` (decorated), `CartCalculator`, `CartPersister`/`AbstractCartPersister`, `CartContextHasher`, `SalesChannelContextFactory`, `SalesChannelContextPersister`, `StateMachineRegistry`, `order.repository`, `CheckoutFinishPageLoadedEvent`. Uses `sw-shipping-address` as a string literal because the constant exists only since Shopware 6.7.16 (factory:37). [verified]
- Used by:
  - `Service/DeliveryPlanService` (preview of every order: confirm page, planner, Store API `DeliveryPlanRoute`) [verified: DeliveryPlanService.php:9,38,215-230; src/Route/DeliveryPlanRoute.php:46 post-processes `separateOrders.orders`]
  - `Policy/SameCountryAddressPolicy` uses `SplitSettings::separateOrdersAllowed()` to also accept foreign addresses [verified: SameCountryAddressPolicy.php:29]
  - Twig templates: checkout summary, finish page, planner (grep hits listed above) [verified]
  - Other plugins via `SeparateOrdersPlacedEvent` and the custom field [verified: docs/compatibility.md]
- `OrderConversionSubscriber`: listens to `CartConvertedEvent`, only acts when the cart has 2+ deliveries, matches converted deliveries to cart deliveries by position signature, writes the custom field and dispatches `SplitOrderConvertedEvent`. It runs once per placed order, so each separate order with one delivery is skipped (`count() < 2`, :39), while a main order with several deliveries is processed normally. [verified for the code; the interaction is my [inferred] reading]

## Patterns and conventions

- Decoration, not replacement: the route is a service decorator with `getDecorated()` (route:61); all real order creation stays in Shopware. [verified]
- Value objects are `final` with `readonly` public properties and `withX()` style copy methods (`SeparateOrderPart::withCart`, `SeparateOrderSplit::with`). [verified]
- Carts are deep-copied with `clone` (Shopware `CloneTrait`), not `serialize()`, because calculated carts can hold closures (splitter:225-239, test testCartsHoldingClosuresCanBeSplit). [verified]
- Calculation never stores: always use `calculateWithoutStoring()` and `discard()`; never calculate with the customer's token. [verified]
- Services are wired explicitly with `->args(...)` in `services.php` (no autowiring). [verified]
- Failures are signalled with `MultiDeliveryException` static factories with HTTP 400 and `{{ placeholders }}`. [verified]
- A typical change: adjust splitter logic or settings, extend `SeparateOrderSplitterTest` / `SeparateOrderCartOrderRouteTest`, update README "Deliveries to other countries" and, if public data changes, docs/compatibility.md and the Store API schema src/Resources/Schema/StoreApi/components/schemas/MnMultiDelivery.json. [inferred from structure]

## Tests

- Unit tests: `tests/Unit/SeparateOrder/` (SeparateOrderSplitterTest, SeparateOrderCalculatorTest, SeparateOrderCartOrderRouteTest) and `tests/Unit/Service/SplitSettingsCountryModeTest.php`. [verified]
- Splitter tests cover: same-country mode no-op, no foreign deliveries, one cart per foreign delivery, everything abroad (empty main cart), container with children, closures, `allSeparate` (all deliveries, fallback main delivery, fallback to hybrid without allowed payment, single delivery no-op). [verified]
- Calculator tests: customer's cart never stored/deleted, `discard` guard. [verified]
- Route tests: unchanged pass-through, order of placing (parts then main) and hash checked once, linking and event, all-abroad returns last separate order, cancellation of placed orders when the main order fails, payment-not-allowed placing nothing. [verified]
- Not covered by any test I found: `SeparateOrderContextFactory` (no test file), `SeparateOrdersFinishPageSubscriber` (no test file), blocking-error path (`separateOrderInvalid`), failure in `link()`, hash mismatch, partial failure when the second of several separate orders fails. `tests/Integration/` has only OrderConversionTest and SplitCartTest; the only mention of separate orders there is a comment (OrderConversionTest.php:38), so no integration test places real separate orders. Note docs/compatibility.md:58 says the context-persister option keys are "covered by the integration tests", which I could not confirm. [verified by grep]
- Run: no local PHP/Composer here, tests not run. Presumably PHPUnit via phpunit.xml.dist with tests/bootstrap.php; CI config in tests/ci not read. [inferred]

## Hotspots

Judged by size and centrality [inferred]; git commit counts: SeparateOrderSplitter.php 2, SeparateOrderContextFactory.php 2 [verified, git log]:
- src/SeparateOrder/SeparateOrderCartOrderRoute.php (185 lines): placing, failure handling, linking; every behavioural change to checkout lands here.
- src/SeparateOrder/SeparateOrderSplitter.php (240 lines): the split algorithm, quantity moving, container handling.
- src/SeparateOrder/SeparateOrderContextFactory.php: depends on Shopware internals (context option keys); most likely to break on Shopware upgrades.
- src/Service/SplitSettings.php (`countryMode`, `separateOrderPaymentAllowed`) and src/Service/DeliveryPlanService.php (`describeSeparateOrders`) as consumers.
- Documentation to keep in sync: README.md "Deliveries to other countries", docs/compatibility.md.

## Risks

Payment, transactions and failure handling
1. **No atomicity across orders.** Each `decorated->order()` commits its own order; there is no surrounding DB transaction (route:100-125). If the main order or a later separate order fails, earlier orders are only compensated by a `cancel` transition. [verified]
2. **Compensation is partial.** `cancel()` (route:169-184) only transitions the order state. It does not refund/void payment transactions, release stock explicitly (relies on Shopware's cancel flows) or suppress mails/flows already fired; the README admits confirmation mails may already be out (README "Placing"). [verified for code; effect on stock/transactions [inferred]]
3. **Cancel failures are only logged.** If a cancel transition throws, it is logged at error level and the original exception is rethrown; the order stays active, the customer sees a failed checkout, and the cart still exists, so a retry can create duplicates. [verified: route:177-182; the retry duplicate scenario is [inferred]]
4. **Failure after all orders are placed is not compensated.** `link()` (route:128) and the `SeparateOrdersPlacedEvent` dispatch (:129) are outside the try/catch; an exception there (DAL error, a subscriber throwing) leaves every order placed and active while the customer sees an error, and the orders are unlinked. [verified]
5. **Failure inside the loop leaves the customer's cart state depending on which order failed.** Shopware deletes the customer's cart only after the main order succeeds, so a failure keeps the cart; in the all-abroad branch the module deletes the customer's cart itself (route:115) after the last separate order was placed. [verified: code; consequences [inferred]]
6. **Only the main order goes through payment.** Separate orders get Shopware's default open transaction from the decorated route and no payment handler is invoked for them (docblock route:25-31, config helpText). Safety relies entirely on the allow-list; no code checks that the handler is truly "offline". Matching is `str_contains` on the handler identifier with the allowed fragment (SplitSettings.php:136-139), so a short fragment such as `DefaultPayment` could match unrelated plugin handlers whose class name contains it. [verified code; the false-positive scenario is [inferred]]
7. **The allow-list check happens after calculation in `order()`** (route:68-80) and hybrid splitting itself ignores payment (`split()` only checks payment for `allSeparate`); the guard is the exception in the route and the planner's `paymentAllowed` flag. Store API clients that choose a non-allowed method get HTTP 400 at order time. [verified]
8. **Blocking-error check covers only part carts**, not the main cart (route:83-93), so a blocking error in the main cart appears only after the separate orders have been placed, and triggers the cancel path. [verified; inference on consequence]
9. **Main cart with only promotions.** `hasMainOrder()` counts all line items (SeparateOrderSplit.php:26), and promotions stay in the main cart; if every product goes abroad but a promotion line item is left, an empty "main" order could be placed. I did not confirm whether Shopware removes or errors on such a cart. [inferred, unconfirmed]
10. **Cart-hash scope.** The hash is validated against the customer's cart only; part orders are placed with the hash removed, and totals of part carts are recalculated at placing time, so prices can differ from the preview the customer confirmed (e.g. price change between confirm and order). [inferred]

Other fragility
- Reliance on Shopware internals: `CartRuleLoader` persistence behaviour, context persister option keys, `SalesChannelContext` clone semantics, string literal `sw-shipping-address` (factory:37). Compatibility table (docs/compatibility.md:58) lists these. [verified]
- Plugin-specific `SalesChannelContext` options other plugins store are copied into part contexts; some may be wrong for another country (e.g. customer-group, currency-dependent data). [inferred]
- Performance: `DeliveryPlanService::describeSeparateOrders` calculates N+1 carts on every planner/confirm request (DeliveryPlanService.php:228). [inferred]
- The splitter docblock line 21-22 is long and inconsistent in formatting only; no functional issue. Region of doc/behaviour match: README and code agree on modes and fallback. [verified]
- `groupId` fallback `MAIN_GROUP` for deliveries without `DeliveryGroupInfo` means two foreign deliveries without info would share a group id, and `contexts[groupId]` in the calculator (calculator:47) would then be overwritten by the later part, so an earlier part would be placed with the wrong context in the route (`$calculated['contexts'][$part->groupId]`). Whether such deliveries exist is unknown. [inferred]
- Dead code: none found in this module. `SeparateOrderPart::label` is only carried through; consumers not checked. [inferred]

## Open questions

- Why is cancellation chosen (instead of a delete or a single transaction) and are payment transactions meant to be left open for separate orders, to be paid offline? Plugin author.
- Is the missing compensation for failures in `link()` / event listeners intended? Plugin author.
- Should the main cart's blocking errors also be checked before placing anything? Plugin author.
- Why does the splitter keep promotions/vouchers in the main order only, and what is expected when the main order has no physical items? Product owner / plugin author.
- Which payment handlers are used in production on rotfuchs (the allow-list default is Shopware's offline handlers, and `str_contains` matching can be broader than intended)? Merchant / product owner.
- Is there an integration test or CI job (tests/ci) that places separate orders on a real Shopware (compatibility.md says "covered by the integration tests")? Plugin author.
- Are mixed-country deliveries expected to share the customer's single `payment method` and `shipping method` for all parts (the context factory takes the part's shipping method, otherwise the current one)? Product owner.

## Changes after mapping
- 1.4.0 (ADR-0004): main-cart blocking errors are checked before anything is placed; `link()` is retried once and its failure logged; `SeparateOrdersPlacedEvent` listener exceptions are logged; cancelled orders also get their open payment transactions cancelled. The risks above about these points are resolved [verified, unit tests]. Still open: no integration test places real separate orders.
