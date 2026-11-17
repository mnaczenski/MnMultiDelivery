# Module: Administration
_Path: src/Resources/app/administration/src, src/Resources/public, src/Resources/config/config.xml, src/Subscriber/DeliveryNoteSubscriber.php, tests/ci/check-admin-overrides.php, tests/ci/admin-overrides.json_

All paths are relative to the plugin root (MnMultiDelivery). Tags: [verified] = seen in code, [inferred] = interpretation.

## Responsibility
Everything an admin user sees or configures for the plugin. It adds a "Deliveries" tab to the order detail page (state, tracking codes, delivery note per delivery), a Rule Builder condition "Number of deliveries", and the plugin settings UI (config.xml with one custom info component). It also holds the one backend piece that serves the admin's "Create delivery note" button (DeliveryNoteSubscriber) and a CI check that the core admin internals it overrides still exist. [verified]

## Entry points and interfaces
- Admin entry: `src/Resources/app/administration/src/main.js` imports the 2 components, the sw-order-detail extension, the module and the decorator. [verified]
- Order tab, tab bar: `extension/sw-order-detail/index.js` overrides `sw-order-detail` (computed `mnHasSplitDeliveries` = more than 1 `order.deliveries`; computed `orderDetailTabs` appends a tab via `$super`, for the v6.8 tab list). [verified]
- Order tab, classic bar: `extension/sw-order-detail/sw-order-detail.html.twig` extends block `sw_order_detail_content_tabs_extension` with a `sw-tabs-item` showing a count. [verified]
- Route: `module/mn-multi-delivery/index.js` registers an empty module (`routes: {}`) whose `routeMiddleware` pushes child route `mn.multi.delivery.order.detail.deliveries` (path `/sw/order/detail/:id/deliveries`, privilege `order.viewer`) onto `sw.order.detail`. The comment says it copies SwagCommercial's return-management tab approach. [verified]
- Tab content: `component/mn-multi-delivery-card/` (`index.js`, `.html.twig`, `.scss`). Per delivery it shows a card with address, shipping method and price, dates, items, and actions. [verified]
  - Load: repository `order_delivery`, filter `orderId`, associations `shippingOrderAddress.country`, `shippingMethod`, `stateMachineState`, `positions.orderLineItem`, sorted by `shippingDateEarliest`, page size 25 (`index.js:57-81`). [verified]
  - State change: `stateMachineService.getState` for transitions, then `orderStateMachineService.transitionOrderDeliveryState(id, action, {sendMail: false})` (`index.js:83-125`). [verified]
  - Tracking: comma-separated input saved to `trackingCodes` via the repository (`index.js:127-142`). [verified]
  - Delivery note: `documentService.createDocument(orderId, 'delivery_note', {documentDate, mnOrderDeliveryId})` (`index.js:144-157`). [verified]
  - Extension points: Twig blocks `mn_multi_delivery_card`, `_details`, `_address`, `_shipping`, `_items`, `_actions` (README.md "Administration", line 369ff; blocks verified in the twig file). [verified]
  - Reads custom field `delivery.customFields.mn_multi_delivery.warehouseName` for the card title (`index.js:91-100`). The field is written by `src/Subscriber/OrderConversionSubscriber.php:79-83`. [verified]
  - Edit controls are disabled without ACL `order.editor`. [verified]
- Rule condition: `decorator/rule-condition.js` decorates `ruleConditionDataProviderService`, adds `mnMultiDeliveryCount` using `sw-condition-generic`, scope `cart`, group `cart`, label `mn-multi-delivery.ruleDeliveryCount`. The PHP side is `src/Rule/DeliveryCountRule.php` (name `mnMultiDeliveryCount`, constraints `count`, `operator`), tagged `shopware.rule.definition` in `src/Resources/config/services.php:174`. [verified]
- Settings info component: `component/mn-multi-delivery-country-mode-info/` is plain text only (no config value). It is used in config.xml as `<component name="mn-multi-delivery-country-mode-info">` (line 120). [verified]
- Settings: `src/Resources/config/config.xml`, 5 cards. All have en and de labels. [verified]
  - Multi Delivery: `enabled`, `blockedPaymentHandlers`, `cartHint`.
  - Splitting rules: `strategies`, `defaultStrategy`, `autoSplit`, `deliveryTimeGroupDays`, `deliveryTimeMaxDeliveries`.
  - Deliveries to other countries: info component, `countryMode` (`sameCountry`, `separateOrders`, `allSeparate`), `separateOrderPaymentHandlers`.
  - Shipping costs: `extraDeliveryCosts` (`each`, `free`, `flat`), `extraDeliveryFlatPrice`.
  - The settings are per sales channel because the plugin config is sales-channel aware [inferred]. The task brief says "per-sales-channel settings"; I did not check how the PHP side reads them (cart/strategy modules).
- Delivery note backend: `src/Subscriber/DeliveryNoteSubscriber.php` (tagged `kernel.event_subscriber`, `services.php:168`). [verified]
  - `DocumentOrderCriteriaEvent` (only for `delivery_note.document.criteria`, and only if an operation has config `mnOrderDeliveryId`): adds the associations `deliveries.positions`, `.shippingOrderAddress.country`, `.countryState`, `.shippingMethod`.
  - `DeliveryNoteOrdersEvent`: for those orders, it narrows line items to the delivery's positions (quantities summed, parents kept, items cloned), sets `deliveries` to just that delivery, and calls `setPrimaryOrderDelivery` if the method exists.
- Snippets: `snippet/en-GB.json`, `snippet/de-DE.json`, namespace `mn-multi-delivery.*`, 33 lines each, same keys. [verified]
- Prebuilt assets: `src/Resources/public/administration/assets/mn-multi-delivery-gLkdwNJs.js` (about 8.5 KB), `...-gLReHJxI.css` (about 0.8 KB), `.vite/manifest.json` and `.vite/entrypoints.json` (entry `main.js`, base `/bundles/mnmultidelivery/administration/`, vite metadata version 7.1.0). Not inspected in depth. Last rebuilt in commit 126ac9e (1.2.0). [verified]

## Data model
- This module owns no tables, entities or migrations. [verified: no migration in the listed paths; I did not scan the rest of src for migrations]
- It reads `order_delivery` (with `positions`, `shippingOrderAddress`, `shippingMethod`, `stateMachineState`, `trackingCodes`, `shippingCosts`) and `order_line_item`. [verified]
- It reads the custom field set `mn_multi_delivery` on `order_delivery`. The set is installed by `src/Service/CustomFieldInstaller.php` (relations `order_delivery` and `order`, lines 41-61). [verified]
- It writes `trackingCodes` on `order_delivery` and the order delivery state. [verified]
- The only config contract is the document config key `mnOrderDeliveryId` (`DeliveryNoteSubscriber::CONFIG_KEY`). [verified]

## Dependencies
- Uses:
  - Core admin: component `sw-order-detail`, `sw-tabs-item`, `sw-condition-generic`, `mt-card`, `mt-select`, `mt-text-field`, `mt-button`. Services `repositoryFactory`, `stateMachineService`, `orderStateMachineService`, `documentService`, `acl`, `ruleConditionDataProviderService`. Store `swOrderDetail` (currency). Mixin `notification`. [verified]
  - Core PHP: `DocumentOrderCriteriaEvent`, `DeliveryNoteOrdersEvent`, `DeliveryNoteRenderer`, `DocumentGenerateOperation`. [verified]
  - Other plugin modules: `src/Rule/DeliveryCountRule.php`, `src/Subscriber/OrderConversionSubscriber.php` (writes the custom field the card reads), `src/Service/CustomFieldInstaller.php`. [verified]
- Used by:
  - Settings keys defined in config.xml are read by the cart, strategy and separate-order modules (not mapped here). [inferred]
  - CI: `.github/workflows/tests.yml:59-60` runs `tests/ci/check-admin-overrides.php` against `src/Administration/Resources/app/administration/src`. [verified]

## Patterns and conventions
- A typical admin change: edit the source under `src/Resources/app/administration/src`, then rebuild with `bin/build-administration.sh` and commit the built files in `src/Resources/public` (README.md:54, 425). [verified] The script `bin/` does not exist in this checkout (`ls bin` failed), nor any `package.json` or build config outside the public output. [verified] See Risks.
- Components use `Shopware.Component.register/override`, Twig templates with `{% block %}` names, a BEM scss class `mn-multi-delivery-*`, and snippets under `mn-multi-delivery.*`. [verified]
- Admin code uses only the global `Shopware` object and no custom API service. It calls core services and repositories directly. [verified]
- Deliveries tab visibility: only with more than 1 delivery on the loaded order. [verified]
- Both locales are updated together (en-GB and de-DE have the same keys). [verified]

## Tests
- Admin JavaScript: no tests found in the listed paths (no `*.spec.js`). [verified]
- `tests/ci/check-admin-overrides.php`: a static check, run as `php tests/ci/check-admin-overrides.php <administration src dir>`. For each component in `tests/ci/admin-overrides.json` it checks that the directory exists, that the `$super` members exist (regex on method/computed names), and that the Twig blocks exist. The only entry is `sw-order-detail` with member `orderDetailTabs` and block `sw_order_detail_content_tabs_extension`. Exit codes 0 (ok), 1 (failures), 2 (no source dir). [verified]
- In CI it runs against the Shopware trunk checkout in the integration matrix (`v6.7.14.0`, `v6.7.16.0`, `trunk`, trunk is `continue-on-error`) (`.github/workflows/tests.yml:44-60`). [verified]
- `DeliveryCountRule`: unit test at `tests/Unit/Rule/DeliveryCountRuleTest.php`. [verified]
- `DeliveryNoteSubscriber`: no test file references it or `mnOrderDeliveryId` in `tests/` (grep). [verified] Coverage impression: weak for the admin UI and the delivery-note subscriber, only the compatibility check and the rule are covered. [inferred]
- No local run was possible (no PHP/Node), so I did not run anything.

## Hotspots
Git history is short (10 commits, 2026-11-05 to 2026-11-13). Files in this module that changed most: `src/Resources/config/config.xml` (4 commits), both snippet files (3 each), `tests/ci/seed.php` (2), `DeliveryNoteSubscriber.php` (2), `main.js` (2), `mn-multi-delivery-country-mode-info.html.twig` (2). [verified via git log]
- Churn follows features: 1.1.0 and 1.2.0 (separate orders) touched config.xml and the country-mode info text. [inferred]
- `component/mn-multi-delivery-card/index.js` (159 lines) holds nearly all admin logic. [verified]

## Risks
- Core overrides, fragile against Shopware updates [verified unless noted]:
  - `sw-order-detail`: override of computed `orderDetailTabs` (calls `$super`) and Twig block `sw_order_detail_content_tabs_extension`. This is the only override. `CHANGELOG.md:24` says it needs 6.7.14 or newer. The check covers exactly these two names.
  - Both the classic `sw-tabs-item` and the `orderDetailTabs` entry are added. If a core version renders both, the tab could appear twice. I cannot tell from the code whether core ever renders both. [inferred]
  - The route is added by a `routeMiddleware` on `sw.order.detail` (same pattern as SwagCommercial). It depends on that route name and on the `sw-order-detail` child-route structure. This is not in the CI check. [inferred]
  - `ruleConditionDataProviderService` decorator and `sw-condition-generic`: not in the CI check.
  - The card uses `mt-card`, `mt-select`, `mt-text-field`, `mt-button` (Meteor components) and the store `Shopware.Store.get('swOrderDetail')`. These are core-internal names that are not in the CI check. [inferred: risk]
- Document generation: `DeliveryNoteSubscriber` relies on two events that the file's own comment says are marked for replacement in v6.9 (`DOCUMENT_GENERATION_REWORK`). It also uses `method_exists($order, 'setPrimaryOrderDelivery')` for 6.7 version drift (line 103). No tests. [verified]
- Hidden contract: the admin sends `mnOrderDeliveryId` in the document config. Whether core passes unknown config keys through to `DocumentGenerateOperation` is not checked here. This is only implied by the subscriber, and I did not verify the core path. [inferred]
- If the delivery id is not found or has no positions, the subscriber returns silently and the note covers the whole order. [verified: `DeliveryNoteSubscriber.php:67-71`]
- Card behavior [verified]:
  - Page size is 25 (`index.js:63`), so orders with more than 25 deliveries would be cut off.
  - Transition errors show the first API error detail, other errors show `String(error)`.
  - `formatPrice` falls back to `EUR` when the store has no order.
  - Several card strings are hard-coded: the address is built from name, street, zip, city, country (no company, no address line 2, no country state). The card ignores the address's `company` and `additionalAddressLine1`.
  - A document is created without checking for an existing note for that delivery, so repeated clicks create duplicates. [inferred: no guard seen in code]
  - Tracking codes are split on `,` only.
- Build: the README mentions `bin/build-administration.sh`, but `bin/`, `package.json` and any vite config are absent from this checkout, so the admin cannot be rebuilt from the repo. The prebuilt files in `src/Resources/public` are the only runnable form, and there is no check that they match the sources. [verified absence; mismatch risk inferred]
- Minor: `module/mn-multi-delivery/index.js` registers a module with empty `name` and `title`. [verified] The `.vite/entrypoints.json` records vite version 7.1.0 in its metadata. [verified]
- `blockedPaymentHandlers` and `separateOrderPaymentHandlers` are free-text lists in the admin with no validation. [inferred from config.xml types: textarea]

## Open questions
- Plugin author: why a `routeMiddleware`-registered route plus both a `sw-tabs-item` and an `orderDetailTabs` entry? Is the double registration intended to cover 6.7 and 6.8 only? (see CHANGELOG 1.2.1 and `docs/compatibility.md`, which I did not read)
- Plugin author: where does the build script (`bin/build-administration.sh`) live, and how are the built assets verified against sources?
- Plugin author: why was `DeliveryNoteSubscriber` not covered by tests, and is the plan to port it to `AbstractDocumentDataProvider` before 6.9?
- Product owner: should the delivery tab appear only for split orders (current rule: more than 1 delivery)? Should a delivery note be creatable once per delivery?
- Product owner: should config values like payment handlers be validated or chosen from a list rather than typed?
- Plugin author: the card hard-codes `order.viewer` and `order.editor` privileges. Are there plans for a dedicated ACL?
