# System overview
_Last updated: 2026-11-17 by /onboard_

Legend: [verified] = seen in code, tests, git or by running a command; [inferred] = interpretation.

## What the system is
Multi Delivery (`mn/multi-delivery`, plugin class `Mn\MultiDelivery\MnMultiDelivery`, version 1.3.0) is a Shopware 6.7 **plugin** that lets customers split one order into several deliveries, each with its own address and shipping method, on the checkout confirm page [verified: composer.json, README.md]. The split follows a rule (manual, availability, delivery time, warehouse) and can optionally place deliveries as separate orders (per country or every delivery) [verified: src/Strategy, src/SeparateOrder]. It has a storefront planner, a Store API for headless clients, an admin "Deliveries" tab, a Rule Builder condition and a public extension API under a compatibility policy [verified: README.md, docs/compatibility.md].

## Tech stack
- PHP ≥ 8.2, Shopware core and storefront `~6.7.14` [verified: composer.json]
- Symfony DI via `src/Resources/config/services.php`, routes in `routes.php`, settings in `config.xml` [verified]
- Storefront: Twig templates, a JS plugin `MnMultiDelivery` (source in `src/Resources/app/storefront/src`, committed build in `dist/`), SCSS [verified]
- Administration: Vue components via Shopware's admin (`src/Resources/app/administration/src`), committed Vite build in `src/Resources/public/administration` [verified]
- Tests: PHPUnit 11.5 (unit + integration), PHPStan 2.1 level 8 [verified: phpunit.xml.dist, phpstan.neon.dist, ran both]
- CI: GitHub Actions, `shopware/github-actions/setup-extension@main` for integration tests on 6.7.14, 6.7.16 and trunk [verified: .github/workflows/tests.yml]
- No database tables or migrations; persistent data are two custom field sets and plugin config [verified: src/Service/CustomFieldInstaller.php, no src/Migration]

## Modules
| Module | Responsibility | Doc |
|---|---|---|
| Cart & delivery plan | Delivery plan as cart extension, `SplitDeliveryProcessor` (priority −5050) replacing the single delivery, `SplitGuard`, settings, address and shipping-cost policies, Rule Builder condition | [cart-delivery-plan.md](modules/cart-delivery-plan.md) |
| Splitting strategies | The four built-in rules and the registry choosing one; produce an `Allocation` of items to delivery groups | [splitting-strategies.md](modules/splitting-strategies.md) |
| Separate orders | Decorates `CartOrderRoute`: splits the calculated cart per delivery, calculates each for its own country, places them (separate first, main last), links them, compensates failures by cancelling | [separate-orders.md](modules/separate-orders.md) |
| Storefront & Store API | Planner controller and Store API route, page/mail subscribers, Twig templates (incl. B2B pending-order pages), JS planner, OpenAPI schema, snippets | [storefront-store-api.md](modules/storefront-store-api.md) |
| Administration | Order "Deliveries" tab (state, tracking, delivery note per delivery), settings, rule condition component, delivery-note subscriber, CI check of core admin overrides | [administration.md](modules/administration.md) |
| Extension surface | Five events, four contracts, decoratable route, ~50 Twig blocks, compatibility policy, example plugin | [extension-surface.md](modules/extension-surface.md) |
| Tests & CI | Unit and integration suites, seed script, PHPStan, GitHub workflow | [tests-ci.md](modules/tests-ci.md) |

## How modules interact
1. On every cart calculation Shopware's `DeliveryProcessor` (−5000) builds one delivery; `SplitDeliveryProcessor` (−5050) asks `SplitGuard` whether to split, lets the active strategy (via `StrategyRegistry`) allocate items to groups, dispatches `DeliveryAllocationEvent`, builds and prices one delivery per group under the address and shipping-cost policies, dispatches `SplitDeliveriesBuiltEvent`; `PromotionDeliveryProcessor` (−5100) then applies shipping discounts [verified: README "How it works", src/Cart/SplitDeliveryProcessor.php].
2. Customer changes (storefront controller or Store API route) go through `DeliveryPlanService`, which stores the plan in the cart extension `mnDeliveryPlan`, recalculates and dispatches `DeliveryPlanChangedEvent` [verified].
3. On order placement, `SeparateOrderCartOrderRoute` (decorator) uses `SeparateOrderSplitter` / `SeparateOrderCalculator` when a separate-order mode is on; otherwise Shopware's `OrderConverter` writes one `order_delivery` per cart delivery and `OrderConversionSubscriber` stores group data in custom fields, dispatching `SplitOrderConvertedEvent` [verified].
4. After the order: finish page, account, confirmation mail (`MailBlock` injects a block at send time), admin tab and delivery notes read the stored custom fields [verified].

## Cross-cutting concerns (auth, config, i18n, caching, events)
- **Config:** per-sales-channel settings `MnMultiDelivery.config.*` read by `SplitSettings` [verified].
- **Auth:** Store API routes need a logged-in customer (guests included); storefront planner endpoints rely on the session; address ownership is enforced at cart calculation, not when the plan is written [verified: storefront-store-api.md].
- **i18n:** English and German snippets for storefront, admin and mail [verified]; mail dates hard-coded `d.m.Y` [verified].
- **Events / extension:** tags `mn.multi_delivery.strategy`, `.blocker`, `.address_policy`; decoration of `ShippingCostPolicyInterface` and `AbstractDeliveryPlanRoute`; five events [verified].
- **Optional dependency:** SwagCommercial (Multi-Warehouse for the warehouse rule; quote and subscription detection in `SplitGuard`) via raw SQL and copied constants, no Composer dependency [verified].
- **Caching:** strategies' `isAvailable()` (warehouse) query the DB on every call without caching [verified: splitting-strategies.md].

## Global hotspots
From git history (10 commits, 2026-11-05 to 2026-11-13) [verified: `git log --name-only`]:

| File | Commits |
|---|---|
| src/Service/DeliveryPlanService.php | 5 |
| src/Service/SplitSettings.php | 4 |
| src/Resources/views/storefront/component/mn-multi-delivery/planner.html.twig | 4 |
| src/Resources/snippet/mn-multi-delivery.{en-GB,de-DE}.json | 4 each |
| src/Resources/Schema/StoreApi/components/schemas/MnMultiDelivery.json | 4 |
| src/Resources/config/config.xml | 4 |
| src/Cart/SplitDeliveryProcessor.php, services.php, base.scss, admin snippets, docs/compatibility.md | 3 each |

Largest and most central code: `SplitDeliveryProcessor.php` (384 lines), `DeliveryPlanService.php` (355), `WarehouseStrategy.php` (250), `SeparateOrderCartOrderRoute.php` [verified].

Cross-module risks (details in the module docs):
- Separate orders have no transaction across orders; failure compensation is a state cancel, payment transactions untouched, `link()` outside the try/catch [verified: separate-orders.md].
- SwagCommercial internals copied (warehouse allocation, license toggle, quote/subscription constants), guarded only by a parity test that skips without Commercial [verified].
- Committed `dist/` and `public/` builds can drift from their sources unchecked. The README's `bin/build-*.sh` are the shop's scripts (shop root), not the plugin's [verified]. Decided: build at release instead, ADR-0007.
- `DeliveryNoteSubscriber` relies on core events Shopware plans to replace in 6.9 [verified: README Limitations].
- Hard-coded processor priority −5050 between two core processors [verified].

## Open questions
See the module docs for module-level questions. Global ones:
1. ~~Why a plugin rather than an app; is Cloud a target?~~ Answered 2026-11-17: plugin because of cart internals; Cloud out of scope, see ADR-0001.
2. ~~Where are the build scripts, and how do committed builds stay in sync?~~ Answered 2026-11-17: they are the shop's scripts; builds move out of git into a release zip, see ADR-0007.
3. ~~Is failure handling for separate orders an accepted trade-off?~~ Answered 2026-11-17: cancelling stays, hardening decided, see ADR-0004.
4. ~~Who uses it, and how strict is the compatibility policy?~~ Answered 2026-11-17: reference / showcase, policy best effort, see ADR-0011.
5. ~~Is SwagCommercial support a requirement, and how is it tested?~~ Answered 2026-11-17: optional, kept, checked by hand before releases, see ADR-0008.
