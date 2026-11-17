# Module: storefront-store-api ("Storefront & Store API")
_Path: src/Controller, src/Route, src/Subscriber, src/Resources/views, src/Resources/app/storefront/src, src/Resources/Schema/StoreApi, src/Resources/snippet, src/Resources/config/routes.php, src/Service/MailBlock.php_

All paths are relative to `/Users/M.Naczenski/Documents/shopware/rotfuchs-deploy/MnMultiDelivery`. Markers: [verified] = seen in code/tests, [inferred] = interpretation. Vendor (Shopware core) is not available locally, so statements about core behaviour are [inferred].

## Responsibility
The module is the customer-facing and integration surface of the plugin. It renders the "delivery planner" on the confirm page (form endpoints plus a small JS enhancement), shows hints and delivery lists on cart, finish, account, B2B approval pages and in the order confirmation mail, and exposes the same planner operations as a Store API for headless clients. It holds no planning logic itself: every change is delegated to `Mn\MultiDelivery\Service\DeliveryPlanService` (cart module) [verified: `src/Controller/DeliveryPlanController.php`, `src/Route/DeliveryPlanRoute.php` only call `$this->planService->...`].

## Entry points and interfaces

Storefront controller `src/Controller/DeliveryPlanController.php` (class-level defaults `_routeScope: storefront`, `XmlHttpRequest: true`, line 30) [verified]. All routes are POST only:
| Route name | Path | Plan operation |
|---|---|---|
| `frontend.mn.multi-delivery.toggle` | `/checkout/multi-delivery/toggle` | `toggle(enabled)` (l.44) |
| `...strategy` | `/strategy` | `setStrategy(strategy)` (l.50) |
| `...group` | `/group` | `setGroup(groupId, addressId?, shippingMethodId?)` (l.56) |
| `...address` | `/address` | creates an address via core `UpsertAddressRoute`, then `setGroupAddress` (l.72) |
| `...add-group` | `/add-group` | `addGroup` (l.100) |
| `...remove-group` | `/remove-group` | `removeGroup(groupId)` (l.106) |
| `...assign` | `/assign` | `move(lineItemId, fromGroupId, groupId, quantity)` (l.112) |
| `...reset` | `/reset` | `reset` (l.124) |

Response logic (`respond()`, l.142-158) [verified]: non-XHR requests get a redirect to `frontend.checkout.confirm.page#mn-multi-delivery` (works without JS); XHR requests get `planner-partial.html.twig` with the freshly loaded confirm page (`CheckoutConfirmPageLoader::load`); if the customer is gone or the cart is empty, a `data-redirect` meta is returned and the JS follows it. Address validation errors on non-XHR forward to `frontend.checkout.confirm.page` with `formViolations` and `mnNewAddress` (l.130-140).

Store API `src/Route/DeliveryPlanRoute.php` (abstract: `AbstractDeliveryPlanRoute.php`, response: `DeliveryPlanRouteResponse.php`; scope `store-api`, `_loginRequired` with `_loginRequiredAllowGuest`, l.24) [verified]:
- `GET /store-api/checkout/multi-delivery` (`store-api.mn.multi-delivery`) returns the plan, `shippingCountryId`, `addressIds` (customer addresses allowed by the address policies); `cart` and `country` of separate orders are stripped (l.46-58).
- `POST /store-api/checkout/multi-delivery/{action}` (`store-api.mn.multi-delivery.change`), action in `toggle|strategy|group|add-group|remove-group|move|reset`; returns `CartResponse`. Move target parameter is `toGroupId` here but `groupId` in the storefront `/assign` [verified l.75-81 vs controller l.112-122].
- Extension point: `getDecorated()` throws `DecorationPatternException`, i.e. the class is the innermost implementation; decorate via the abstract class/alias `AbstractDeliveryPlanRoute` (`src/Resources/config/services.php` l.189-192) [verified].
- OpenAPI: `src/Resources/Schema/StoreApi/paths/multi-delivery.json` (306 lines) and `components/schemas/MnMultiDelivery.json` [verified]. Path list matches the route actions (including `/move`) [verified via grep].

Route loading: `src/Resources/config/routes.php` imports `../../Controller` and `../../Route` as attribute routes [verified].

Subscribers (all registered in `src/Resources/config/services.php` l.147-182) [verified]:
- `CartPageSubscriber` (`CheckoutCartPageLoadedEvent`): adds page extension `mnMultiDeliveryCartHint` (delivery count) if `SplitSettings::cartHint` is on, the cart is splittable (`SplitGuard::blockReason`), and there are 2+ deliveries or 2+ goods quantity.
- `ConfirmPageSubscriber` (`CheckoutConfirmPageLoadedEvent`): adds page extension `mnMultiDelivery` = `DeliveryPlanService::describe()` + policy-filtered `addresses` + `addressForm` (countries allowed by policies, salutations) only when the plan is enabled and not blocked. Skips guests (`$customer === null` returns, l.56) [verified]. So the planner is for registered customers; yet the Store API allows guests (see Risks).
- `CartHashSubscriber` (`CartContextHashEvent`): adds the delivery layout (group, address id, shipping method, positions) to the cart hash when 2+ deliveries, so that changes in another tab invalidate the submitted `hash`.
- `OrderConversionSubscriber` (`CartConvertedEvent`): writes custom field `mn_multi_delivery` (group, label, warehouseId, warehouseName) into each converted order delivery by matching position signatures; then dispatches `Mn\MultiDelivery\Event\SplitOrderConvertedEvent` (extension point for projects).
- `OrderPlacedCriteriaSubscriber` (`CheckoutOrderPlacedCriteriaEvent`): adds `deliveries.positions.orderLineItem` for the mail.
- `OrderMailSubscriber` (`MailBeforeValidateEvent`): for `order_confirmation_mail` templates (looked up by SQL on `mail_template`/`mail_template_type`) of orders with 2+ deliveries, injects the block into `contentHtml`/`contentPlain` and sets `mnDeliveryTexts` from snippets `mnMultiDelivery.mail.*` with hard-coded English fallbacks (l.26-32).
- `DeliveryNoteSubscriber` (`DocumentOrderCriteriaEvent`, `DeliveryNoteOrdersEvent`): when the document config contains `mnOrderDeliveryId`, narrows the order to one delivery (its address, items and quantities incl. parent items). The only sender in this repo is the admin card (`src/Resources/app/administration/src/component/mn-multi-delivery-card/index.js` l.149) [verified].
- `SeparateOrdersFinishPageSubscriber` (`CheckoutFinishPageLoadedEvent`): page extension `mnSeparateOrders`; loads sibling orders restricted by `orderCustomer.customerId` (l.60). Covered mainly by the separate-orders module.

Mail block: `src/Service/MailBlock.php` injects Twig source of `views/mn-multi-delivery/mail/order-deliveries.{html,txt}.twig` at marker `{# mn-multi-delivery #}`, else before `{% set billingAddress = ... %}`, else before `</body>`/end; `{# mn-multi-delivery:off #}` disables, `:start` marker prevents double injection (l.25-61) [verified]. Nothing is stored in the mail templates [verified]. Template is found via `TemplateFinderInterface`, so themes/plugins can override it [verified l.66].

Templates (`src/Resources/views/storefront`, all `sw_extends` core templates) [verified]:
- `page/checkout/confirm/index.html.twig`: wraps planner in `<div data-mn-multi-delivery ...>` in block `page_checkout_confirm_product_table`.
- `page/checkout/cart/index.html.twig`: info alert hint.
- `page/checkout/finish/index.html.twig` + `finish-details.html.twig`: list all separate orders / deliveries.
- `page/account/order-history/order-detail.html.twig`: separate-orders + delivery overview.
- `pending-order/page/...` (3 files): B2B approval pages (SwagCommercial) show deliveries from `page.pendingOrderCart.deliveries`.
- `page/checkout/summary.html.twig`, `component/mn-multi-delivery/{planner,planner-partial,delivery-overview,separate-orders,finish-order}.html.twig`. `planner.html.twig` (285 lines) has many `component_mn_multi_delivery_*` blocks as theme extension points (README l.342) [verified].

Storefront JS: `src/Resources/app/storefront/src/main.js` registers plugin `MnMultiDelivery` on `[data-mn-multi-delivery]`; `mn-multi-delivery/mn-multi-delivery.plugin.js` (125 lines) intercepts form submit and `change` of `[data-mn-md-auto-submit]` fields, POSTs `FormData` with `X-Requested-With`, serializes requests in a promise queue, swaps the planner `<template>` and summary container, updates the `hash` input, disables submit when `blockResubmit`, reloads page on `data-reload`, falls back to native `form.submit()` on any error. No drag and drop: "planner" is selects and quantity forms [verified]. SCSS: `src/Resources/app/storefront/src/scss/base.scss`. Built output committed at `src/Resources/app/storefront/dist/storefront/js/mn-multi-delivery/mn-multi-delivery.js` [verified exists].

Snippets: `src/Resources/snippet/mn-multi-delivery.{de-DE,en-GB}.json`, 91 lines each [verified]. Error snippet `error.MN_MULTI_DELIVERY__COUNTRY_NOT_ALLOWED` is referenced by the controller (l.33) [verified reference; snippet presence not checked].

## Data model
This module has no own tables, entities or migrations [inferred from absence in these paths]. It reads/writes:
- Cart extension `DeliveryPlan::EXTENSION` (stored in the cart, written through `DeliveryPlanService`, cart module) [verified: `DeliveryPlanService::getPlan`].
- Order delivery custom field `mn_multi_delivery` (`OrderConversionSubscriber::CUSTOM_FIELD`) [verified].
- Order custom field of separate orders (`SeparateOrderCartOrderRoute::CUSTOM_FIELD`) read in the finish subscriber [verified].
- Page extensions: `mnMultiDelivery`, `mnMultiDeliveryCartHint`, `mnSeparateOrders`; mail template variable `mnDeliveryTexts`; document config key `mnOrderDeliveryId` [verified].

## Dependencies
- Uses: cart module (`DeliveryPlanService`, `SplitGuard`, `SplitSettings`, `DeliveryGroupInfo`, `DeliveryPlan`, `SplitDeliveryProcessor` for the actual address/shipping validation), `Policy\DeliveryAddressPolicies`, `Event\DeliveryPlanChangedEvent` constants and `SplitOrderConvertedEvent` (extension events module), `SeparateOrder\SeparateOrderCartOrderRoute`. Core: `CheckoutConfirmPageLoader`, `UpsertAddressRoute`, `ListAddressRoute`, `CountryRoute`, `SalutationRoute`, `CartService`, `Translator`, DBAL `Connection`, document and mail events [verified: `services.php`, imports]. Optional: SwagCommercial pending-order templates (B2B approval) [verified README l.32; templates extend `@Storefront/.../pending-order/...`].
- Used by: administration module (sends `mnOrderDeliveryId`, see above); `MailBlock` is shared with the cart module per the task brief [inferred; no other caller found in the module's own paths]; headless clients consume the Store API.

## Patterns and conventions
- Thin controllers/routes: parse request, call `DeliveryPlanService` method returning `Cart`, render/redirect. A new planner action means: constant in `DeliveryPlanChangedEvent`, method in `DeliveryPlanService`, storefront route in the controller, `match` arm plus `action` regex in `DeliveryPlanRoute` (l.63), path in `multi-delivery.json`, form in `planner.html.twig`, snippets in both languages [inferred from structure].
- Progressive enhancement: every form works as plain POST + redirect; JS only swaps fragments [verified].
- Subscribers are `@internal`, tiny, constructor-injected, one event each; services wired in `services.php` with explicit `->args()` [verified].
- Theme extension via Twig blocks named `component_mn_multi_delivery_*` and `{# mn-multi-delivery #}` mail marker [verified].
- Optional core services nullable in controller/subscriber constructors (`?AbstractUpsertAddressRoute`, `?AbstractCountryRoute`) [verified].

## Tests
- Unit: `tests/Unit/Subscriber/{CartHashSubscriberTest,CartPageSubscriberTest,ConfirmPageSubscriberTest}.php`, `tests/Unit/Service/MailBlockTest.php` [verified]. Integration: `tests/Integration/{OrderConversionTest,SplitCartTest}.php` (installed shop; conversion subscriber covered here) [verified].
- No test files reference `DeliveryPlanController` or `DeliveryPlanRoute` [verified via grep]. No tests for `OrderMailSubscriber`, `DeliveryNoteSubscriber`, `OrderPlacedCriteriaSubscriber`, `SeparateOrdersFinishPageSubscriber` in the unit dir listing (could exist elsewhere under `tests/Unit/SeparateOrder`, not checked) [verified listing]. No JS tests found [verified: no JS test dir].
- Run: `composer test` after `composer install` in the plugin per `CHANGELOG.md` 1.2.1; not executed here (no PHP) [verified from changelog].
- Coverage impression: PHP subscribers/mail block partly covered, HTTP layer and JS and Twig untested [inferred].

## Hotspots
Git history is short (10 commits, `git log` in plugin dir). Most touched in these paths [verified, git log --name-only]: `src/Resources/views/storefront/component/mn-multi-delivery/planner.html.twig` (4), both snippet files (4 each), `Schema/StoreApi/components/schemas/MnMultiDelivery.json` (4), `scss/base.scss` (3), then `DeliveryNoteSubscriber`, `ConfirmPageSubscriber`, `CartHashSubscriber`, `DeliveryPlanRoute` (2 each). Recent: 1.3.0 (new addresses in planner, saving, cart hint, commit `0f5de34`) and 1.2.2 (security review, `73d957e`) [verified]. Newest area: new-address form in `DeliveryPlanController::newAddress` and `planner.html.twig` l.140-175.

## Risks
1. Security, ownership: the controller/route do not check ids themselves; they rely on `DeliveryPlanService`/`SplitDeliveryProcessor`. Address ownership (customerId filter, `SplitDeliveryProcessor.php` l.99-103, l.375-377) and sales-channel shipping method filter (l.117) are enforced at cart calculation, not at write time; the stored plan can hold foreign ids until calculated [verified code; conclusion that they are ignored then is [inferred]; the cart module agent should confirm]. `setGroup` limits group ids to known groups, max 30 configured (`DeliveryPlanService.php` l.71-84), manual groups max 5, which matches the 1.2.2 changelog [verified].
2. CSRF: no token fields in any planner form (`planner.html.twig`) or the JS [verified]. Shopware 6.5+ storefront relies on SameSite cookies instead of CSRF tokens [inferred, unverifiable locally]. Impact is low (state-changing but only cart plan), yet worth confirming.
3. `newAddress` creates a persistent customer address from a POST; it removes `id` (l.81) so existing addresses cannot be overwritten, uses core validation, and checks the country policy, but only `if ($countryId !== '')` (l.85) so an empty country falls through to core validation [verified]. `$address->remove('id')` only guards the top-level id [inferred]. Rate limiting of address creation is not present in the controller [verified absence].
4. Guests: supported in both. `ConfirmPageSubscriber` returns only when there is no customer at all (`$customer === null`, l.56); a guest checkout has a customer record marked as guest, so guests get the planner, matching README and CHANGELOG 1.3.0 [verified]. The Store API route allows guests explicitly (`_loginRequiredAllowGuest`, `DeliveryPlanRoute.php` l.24) [verified]. _Corrected by /onboard 2026-11-17; the first mapping said the storefront skips guests._
5. Store API `change` has no explicit validation of `groupId`/quantity types; casts `(string)` of arrays would emit warnings/errors from `$request->request->get()` if arrays are posted (Symfony throws `BadRequestException` for non-scalar in 6.x/7.x `InputBag::get`) [inferred].
6. Cart hash comment warns that changing the layout shape invalidates open checkouts (`CartHashSubscriber` l.46) and uses `@phpstan-ignore` [verified]; fragile across upgrades.
7. `DeliveryNoteSubscriber` depends on events marked for replacement in 6.9 (comment l.19-20) and on `setPrimaryOrderDelivery` existing only in some 6.7 releases (guarded by `method_exists`) [verified]. Admin card sends the config key; no storefront use.
8. `OrderConversionSubscriber` matches deliveries by signature; deliveries with identical signatures are consumed FIFO; unmatched ones silently get no custom field (`continue 2` also skips when a price is missing) [verified l.60-76].
9. `OrderMailSubscriber`: does 2 raw SQL queries per mail; caches template type per instance; on missing marker/anchor the block is appended/injected at end; custom mail templates changing the anchor line lose placement [verified]. HTML mail template echoes address names and labels unescaped by the template itself; escaping depends on the mail renderer autoescape [inferred, unverified].
10. Mail text in `OrderMailSubscriber::TEXTS` has English fallbacks only; the plain/HTML templates hard-code date format `d.m.Y` (`order-deliveries.html.twig`) regardless of locale [verified].
11. Built JS in `dist/` is committed; source and dist can drift; no build or JS tests locally [verified exist; drift [inferred]].
12. The storefront `/assign` and Store API `/move` naming and parameter (`groupId` vs `toGroupId`) differ [verified]. The `XmlHttpRequest: true` default on the controller allows XHR; non-XHR still works because the controller handles both [inferred; vendor not available].
13. Admin bundle `src/Resources/public/administration/assets/mn-multi-delivery-gLkdwNJs.js` is a prebuilt bundle (belongs to the administration module) [verified].

## Open questions
- ~~Why were guests allowed in the Store API while the storefront planner is customer-only?~~ Not the case: guests are supported in both, and that is intended (user, 2026-11-17).
- Is the absence of CSRF tokens in planner forms intentional (relying on SameSite) and was it part of the 1.2.2 review? (plugin author)
- Is the planner endpoints' lack of rate limiting on address creation acceptable for the target shops? (plugin author / product owner)
- Why does the delivery note use a custom config key instead of a dedicated API, and is a storefront or API way to download per-delivery notes planned? (plugin author)
- Are the committed `dist` files built by CI or by hand? (plugin author; the tests/CI agent may know)
- Should the mail date format follow the mail's locale? (product owner)
