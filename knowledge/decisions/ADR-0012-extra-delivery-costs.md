---
id: ADR-0012
status: accepted
scope: cart-delivery-plan
created: 2026-11-17
source: user decision during /onboard question review
---
# Shipping costs of additional deliveries: the most expensive delivery is charged in full

## Context
The setting "Shipping costs of additional deliveries" (`extraDeliveryCosts`) has three modes: `each` (default: every delivery costs its shipping method's price), `free`, and `flat` (others capped at a gross amount, pre-filled with 4.90) [verified: src/Resources/config/config.xml, src/Policy/ExtraDeliveryCostPolicy.php].

## Decision
In `free` and `flat` mode the most expensive delivery keeps its normal price (it is the delivery Shopware's OrderConverter makes the primary delivery); every other delivery is free or costs at most the flat amount, which never raises a price. The pre-filled flat amount 4.90 (gross; net customers pay it without tax) is a reasonable starting value that merchants change per sales channel.

## Alternatives considered
- Charge the cheapest delivery in full (customer-friendlier, less shipping revenue).
- No pre-filled flat amount, so merchants must enter their own.

## Consequences
Example with deliveries at 5.90, 9.90 and 5.90: `each` 21.70, `free` 9.90, `flat` 19.70. The default `each` is unaffected. Merchants who want a different split of costs can decorate `ShippingCostPolicyInterface`.
