# Changelog

## 1.2.2

Security review of the storefront and Store API endpoints.

- **Shipping methods per delivery** are limited to active methods of the current sales channel. The planner accepted any shipping method id; Shopware's own checks block inactive methods and those whose availability rule doesn't match, but not methods of another sales channel (for example a free shipping method of a B2B channel). This also applies to separate orders, which take their shipping method from the delivery.
- **Addresses per delivery** are only used when they belong to the logged-in customer, also when the cart's data was collected earlier (they were already loaded for the customer only).
- **Delivery settings** (address, shipping method) are only stored for deliveries the planner shows, at most 30: made-up delivery ids grew the stored cart without limit.
- Fixed the integration test of the order conversion, which failed since separate orders decorate Shopware's order route.

## 1.2.1

- Fixed: placing separate orders failed on Shopware releases before 6.7.16 (`SalesChannelContextService::SHIPPING_ADDRESS` exists only since 6.7.16).
- Requires Shopware 6.7.14 or newer. The administration's Deliveries tab builds on `sw-order-detail` internals that exist since 6.7.14; `composer.json` promised `~6.7.0`.
- Continuous integration: PHPStan level 8 and the unit tests against the lowest and highest Shopware 6.7 on PHP 8.2 to 8.4; integration tests in installed shops (6.7.14.0, 6.7.16.0, trunk) with seeded test data; a check that the administration internals the plugin overrides still exist. See `docs/compatibility.md`.
- The tests also run without a shop (`composer install` in the plugin, `composer test`).

## 1.2.0

- New mode for *Deliveries to addresses in another country*: **Separate orders: every delivery is its own order**, in the shipping country too (setting value `allSeparate`). Only the main delivery stays in the main order. With a payment method that doesn't allow separate orders, deliveries in the shipping country stay one order.
- The existing mode `separateOrders` is now labelled **Hybrid: separate orders only for other countries**; its behaviour is unchanged.
- The settings page explains all three modes; the planner marks every delivery that becomes a separate order.
- Store API: `separateOrders.mode` and `separateOrders.separateGroupIds` in `GET /store-api/checkout/multi-delivery`.
- New `SplitSettings::COUNTRY_ALL_SEPARATE` and `SplitSettings::separateOrdersEnabled()`.

## 1.1.0

- New setting *Deliveries to addresses in another country*: keep deliveries in the shipping country (default, as before) or place each delivery to another country as a separate order, calculated by Shopware for that country. The settings page explains what happens.
- New setting *Payment handlers that allow separate orders* (default: Shopware's offline payment handlers).
- Confirm page: deliveries abroad are marked as separate orders, and the summary shows each order's totals and the sum of all orders.
- Finish page shows every order of the checkout in full (address, shipping and payment method, items, totals per order and in total); account order details list the orders placed together.
- New order custom field `mn_multi_delivery_orders`, new event `SeparateOrdersPlacedEvent`, new exception `MultiDeliveryException` (error codes `MN_MULTI_DELIVERY__SEPARATE_ORDER_PAYMENT`, `MN_MULTI_DELIVERY__SEPARATE_ORDER_INVALID`).
- Store API: `separateOrders` in `GET /store-api/checkout/multi-delivery`; `/store-api/checkout/order` places separate orders the same way as the storefront.
- Fixed: `GET /store-api/checkout/multi-delivery` now returns `shippingCountryId`, as documented.
- Previews of separate orders are calculated on copies with tokens of their own, so the customer's cart is never changed by them.

## 1.0.0

- First release.
