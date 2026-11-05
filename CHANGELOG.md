# Changelog

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
