---
id: ADR-0003
status: accepted
scope: global
created: 2026-11-17
source: /onboard
---
# No own database tables: cart extensions, plugin config and custom fields

## Context
The plan must survive cart recalculation and end up on the order.

## Decision
The plan lives in the cart extension `mnDeliveryPlan`; settings in `MnMultiDelivery.config.*`; order data in custom fields `order_delivery.customFields.mn_multi_delivery` and `order.customFields.mn_multi_delivery_orders` [verified: CustomFieldInstaller.php, docs/compatibility.md]. No `src/Migration` [verified].

## Alternatives considered
Not documented.

## Consequences
Easy install/uninstall, no migrations [inferred]. Custom fields are part of the public API, so their shape is frozen within a major version [verified: docs/compatibility.md]. Querying orders by delivery group needs JSON filtering [inferred].
