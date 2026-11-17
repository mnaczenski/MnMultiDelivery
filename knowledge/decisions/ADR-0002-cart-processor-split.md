---
id: ADR-0002
status: accepted
scope: cart-delivery-plan
created: 2026-11-17
source: /onboard
---
# Split deliveries in a cart processor at priority −5050

## Context
Shopware's DeliveryProcessor (−5000) builds one priced delivery; PromotionDeliveryProcessor (−5100) applies shipping discounts [verified: README "How it works"].

## Decision
`SplitDeliveryProcessor` runs at −5050, replaces the single delivery with one per delivery group when the cart has exactly one delivery and `SplitGuard` allows it [verified: src/Cart/SplitDeliveryProcessor.php, services.php].

## Alternatives considered
Not documented.

## Consequences
Every cart calculation pays for allocation and two events [verified]. The priority is not part of the compatibility policy, though documented in the README [verified: extension-surface.md]. Core reordering of processors would break it [inferred].
