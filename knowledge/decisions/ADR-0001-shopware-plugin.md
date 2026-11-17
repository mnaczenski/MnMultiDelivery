---
id: ADR-0001
status: accepted
scope: global
created: 2026-11-17
source: /onboard
---
# Built as a Shopware plugin, not an app

## Context
Splitting needs a cart processor between core processors, a decorated order route and a custom-field installer [verified: src/Cart/SplitDeliveryProcessor.php, src/SeparateOrder/SeparateOrderCartOrderRoute.php, src/Service/CustomFieldInstaller.php]. Apps can't run PHP in the cart or replace core services.

## Decision
A `shopware-platform-plugin` (composer.json) requiring `shopware/core` and `shopware/storefront` `~6.7.14` [verified].

## Alternatives considered
App system [inferred: not feasible for cart processors]. The actual reasoning isn't documented.

## Consequences
Self-hosted (and PaaS) shops only, not Shopware SaaS [inferred]. Tight coupling to core internals (processor priorities, OrderConverter behaviour), covered by CI on 6.7.14, 6.7.16 and trunk [verified].

## Confirmed (2026-11-17)
Shopware Cloud (SaaS) is out of scope: the plugin targets self-hosted and PaaS shops only.
