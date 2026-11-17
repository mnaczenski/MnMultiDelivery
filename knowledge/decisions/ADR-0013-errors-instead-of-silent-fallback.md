---
id: ADR-0013
status: accepted
scope: cart-delivery-plan
created: 2026-11-17
source: user decision during /onboard question review
---
# Show an error instead of silently falling back when a delivery's address or shipping method is invalid

## Context
When a delivery's chosen address is rejected (invalid, foreign without separate orders, refused by an address policy) or its shipping method is missing or unavailable, `SplitDeliveryProcessor` silently uses the main delivery's address or method (src/Cart/SplitDeliveryProcessor.php, lines 354-383) [verified: cart-delivery-plan.md]. The Store API also documents that such input "has no effect" [verified: README "Store API", Behavior].

## Decision
The customer gets an error instead: a cart error on the affected delivery, shown in the planner and returned by the Store API, so they can pick another address or shipping method.

## Alternatives considered
Keep the silent fallback (current behaviour).

## Consequences
- Not yet planned (run /pm). Needs new error codes (`MN_MULTI_DELIVERY__*`) and English/German snippets.
- Changes documented Store API behaviour; under the best-effort policy (ADR-0011) note it in the CHANGELOG and README.
- Decide whether the error blocks ordering, or only warns while the fallback still applies.

## Implemented (1.4.0, 2026-11-17)
Following Shopware's default behaviour (user decision): address → `DeliveryAddressBlockedError` (`mn-multi-delivery-address-blocked`, blocks the order, like `ShippingAddressBlockedError`); shipping method → main delivery's method plus `DeliveryShippingMethodChangedError` (`mn-multi-delivery-shipping-method-changed`, warning, like Shopware's `ShippingMethodChangedError`). Classes in `src/Cart/Error/`, raised by `SplitDeliveryProcessor::addFallbackErrors()`, snippets `checkout.*` in English and German (verified in a 6.7.16 shop). Tests: 5 unit tests in `SplitDeliveryProcessorTest`, 2 integration assertions in `SplitCartTest`. Not yet committed or released.
