# Capabilities (as built)
What the product does today, from a user's perspective. This is the baseline new PRDs build on.
_Written 2026-11-17 by /onboard. Status "built" means present in code [verified]; "tested" means covered by unit or integration tests [verified]. The "Why" column is empty where the intent isn't documented; see Open questions._

| Capability | Users | Modules | Status | Why (if known) |
|---|---|---|---|---|
| Split one order into several deliveries on the confirm page (move items or partial quantities, add/remove deliveries) | Customers (incl. guests, intended) | Cart & delivery plan, Storefront | built, tested (processor, service) | |
| Own address and shipping method per delivery | Customers | Cart & delivery plan, Storefront | built, tested | |
| New address entered directly in a delivery, validated and saved to the address book | Customers (incl. guests) | Storefront, Cart & delivery plan | built (1.3.0), controller untested | |
| Planner works without JavaScript; with JS changes apply without reload | Customers | Storefront | built, JS untested | |
| Splitting rules: I'll choose myself, by availability, by delivery time, by warehouse | Customers, merchants | Splitting strategies | built, tested (warehouse only via skipped parity test) | |
| Adjust an automatic split and reset it | Customers | Splitting strategies, Cart & delivery plan | built, tested | |
| Automatic split with a default rule; merchant chooses offered rules | Merchants | Cart & delivery plan | built | |
| Shipping costs of additional deliveries: charge each, free, or capped flat price | Merchants | Cart & delivery plan (ExtraDeliveryCostPolicy) | built, tested | |
| "Ship everything together" shows the saving; cart page hint about deliveries | Customers | Storefront | built (1.3.0) | |
| Deliveries to other countries as separate orders (hybrid) or every delivery as its own order, each calculated for its country | Customers, merchants | Separate orders | built, unit-tested only | Deliveries going to different countries need different tax, which one Shopware order can't hold (user, 2026-11-17) |
| Splitting blocked for quotes, subscriptions, admin recalculation, manual shipping costs, listed payment handlers (default: PayPal) | Merchants | Cart & delivery plan (SplitGuard) | built, tested | |
| Finish page, account order view and confirmation mail list every delivery (and every separate order) | Customers | Storefront | built, mail block tested, subscribers untested | |
| Admin order "Deliveries" tab: state, tracking codes, delivery note per delivery | Merchants | Administration | built, untested | |
| B2B order approval pages show planned deliveries | B2B customers | Storefront | built, untested | |
| Store API for headless clients (planner state and 7 actions), with OpenAPI schema | Developers | Storefront & Store API | built, untested | |
| Rule Builder condition "Number of deliveries" for shipping prices, payment, promotions, flows | Merchants | Cart & delivery plan, Administration | built, tested | |
| Extension API: strategies, blockers, address policies, shipping-cost policy, five events, decoratable route, Twig blocks, compatibility policy, example plugin | Developers | Extension surface | built, partly tested via example plugin | |

## Open questions about intent
- ~~Why is PayPal blocked by default?~~ Deliberate, see ADR-0009.
- ~~Shipping-cost rule for additional deliveries and the 4.90 default?~~ Deliberate, see ADR-0012.
- ~~Store API allows guests, storefront skips them?~~ Not the case: guests are supported in both, as intended.
- ~~Silent fallback or error for an invalid address or missing shipping method?~~ Show an error, see ADR-0013 (built in 1.4.0).
- ~~Why are third-party rules always offered?~~ Deliberate, see ADR-0010.
- ~~Use case for separate orders?~~ Deliveries to different countries with different tax. Payment handling is hardened per ADR-0004.
