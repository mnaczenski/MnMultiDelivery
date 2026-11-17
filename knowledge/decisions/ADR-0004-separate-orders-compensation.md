---
id: ADR-0004
status: accepted
scope: separate-orders
created: 2026-11-17
source: /onboard
---
# Separate orders: decorate CartOrderRoute and compensate failures by cancelling

## Context
Shopware calculates taxes for one country per order, so deliveries to other countries need orders of their own [verified: README Limitations].

## Decision
`SeparateOrderCartOrderRoute` decorates `CartOrderRoute`, places separate orders first and the main order last through Shopware's own route, links them in a custom field and dispatches `SeparateOrdersPlacedEvent`. If placing fails halfway, already placed orders get a `cancel` state transition; there is no transaction across orders. Only the main order goes through payment, so separate orders are limited to listed offline payment handlers [verified: src/SeparateOrder, src/Service/SplitSettings.php].

## Alternatives considered
Not documented.

## Consequences
Confirmation mails of cancelled orders may already be out; payment transactions of separate orders stay open; `link()` and the event run outside the try/catch [verified: separate-orders.md]. Payment-handler allow-list uses partial class-name matching [verified].

## Follow-up (decided by the user, 2026-11-17)
Cancelling instead of a transaction across orders stays (full atomicity was rejected as too costly). Harden it, about 1 day:
1. Check the main order's cart for blocking errors before any order is placed.
2. A failed `link()` or `SeparateOrdersPlacedEvent` listener must not fail the checkout: the orders stand; log and retry the link instead of throwing (prevents duplicate orders on retry).
3. When orders are cancelled, also cancel their open payment transactions.
Not yet planned as a feature (run /pm when it's picked up).

## Implemented (1.4.0, 2026-11-17)
All three points, in `src/SeparateOrder/SeparateOrderCartOrderRoute.php`: main-cart pre-check (`CartException::invalidCart`), `linkSafely()` with one retry and logging, listener exceptions logged, `cancel()` also transitions open `order_transaction`s to `cancel`. Tests: `tests/Unit/SeparateOrder/SeparateOrderCartOrderRouteTest.php` (4 new). Not yet committed or released.
