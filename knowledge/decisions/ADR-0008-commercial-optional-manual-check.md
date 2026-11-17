---
id: ADR-0008
status: accepted
scope: global
created: 2026-11-17
source: user decision during /onboard question review
---
# SwagCommercial stays optional; its compatibility is checked by hand before releases

## Context
The plugin relies on SwagCommercial without a Composer dependency [verified]:
- `src/Service/SplitGuard.php` copies the cart/context extension names `originalQuoteId` (quotes) and `subscription` (subscriptions) to keep those carts unsplit.
- `src/Strategy/WarehouseStrategy.php` ("by warehouse" rule) rebuilds Commercial's Multi-Warehouse allocation with raw SQL on Commercial's tables and checks the license toggle `MULTI_INVENTORY-3749997`.
- The only safeguard, `tests/Unit/Strategy/WarehouseStrategyParityTest.php`, compares the rule with `MultiWarehouseStockUpdater` and is skipped without Commercial, so always in CI.
The rotfuchs shop (Commercial 7.16) had 0 warehouses, 0 quotes and 0 subscriptions on 2026-11-17, default rule "manual" [verified: live database].

## Decision
Keep the warehouse rule and the quote/subscription detection. No CI job with Commercial. Before each release, run the parity test (and a quick quote/subscription cart check) by hand in a shop with SwagCommercial installed.

## Alternatives considered
- CI job with Commercial (needs Shopware package credentials as a GitHub secret), about 1 day.
- Drop the warehouse rule (major version), keep only the quote/subscription detection.

## Consequences
- A Commercial release that changes the allocation, the license key or the extension names is only noticed if the manual check is done.
- Release checklist item: "Run WarehouseStrategyParityTest with SwagCommercial". The README limitations should state that the warehouse rule follows Commercial's allocation as of the tested Commercial version.
