# Knowledge index
One line per entry. Agents read this file first, then open only the entries relevant to their module or task.
Maintained by /learn, /retro and /tidy. Keep it short: retired entries are removed from this index.

## Learnings
| ID | Type | Scope | Summary | Status |
|---|---|---|---|---|

## Decisions
| ID | Title | Scope | Status |
|---|---|---|---|
| [ADR-0001](decisions/ADR-0001-shopware-plugin.md) | Built as a Shopware plugin, not an app | global | accepted |
| [ADR-0002](decisions/ADR-0002-cart-processor-split.md) | Split deliveries in a cart processor at priority −5050 | cart-delivery-plan | accepted |
| [ADR-0003](decisions/ADR-0003-no-own-tables.md) | No own tables: cart extensions, config, custom fields | global | accepted |
| [ADR-0004](decisions/ADR-0004-separate-orders-compensation.md) | Separate orders: decorate CartOrderRoute, compensate by cancelling (hardening decided) | separate-orders | accepted |
| [ADR-0005](decisions/ADR-0005-public-api-policy.md) | Public extension API with a semver compatibility policy | extension-surface | accepted |
| [ADR-0006](decisions/ADR-0006-prebuilt-assets.md) | Ship prebuilt storefront and admin assets | global | superseded by ADR-0007 |
| [ADR-0007](decisions/ADR-0007-release-zip-builds.md) | Build assets at release (shopware-cli zip); no builds in git | global | accepted |
| [ADR-0008](decisions/ADR-0008-commercial-optional-manual-check.md) | SwagCommercial optional; compatibility checked by hand before releases | global | accepted |
| [ADR-0009](decisions/ADR-0009-paypal-blocks-splitting.md) | PayPal blocks splitting by default | cart-delivery-plan | accepted |
| [ADR-0010](decisions/ADR-0010-third-party-rules-always-offered.md) | Third-party splitting rules always offered; they decide via isAvailable() | splitting-strategies | accepted |
| [ADR-0011](decisions/ADR-0011-reference-showcase.md) | Reference / showcase plugin; compatibility policy best effort | global | accepted |
| [ADR-0012](decisions/ADR-0012-extra-delivery-costs.md) | Extra deliveries: most expensive charged in full; flat cap pre-filled 4.90 | cart-delivery-plan | accepted |
| [ADR-0013](decisions/ADR-0013-errors-instead-of-silent-fallback.md) | Invalid delivery address or shipping method: error instead of silent fallback | cart-delivery-plan | accepted |
