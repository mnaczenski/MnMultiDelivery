# Multi Delivery – Example

A project plugin that customises [Multi Delivery](../../README.md) through its public extension points only. Copy whatever you need.

| File | Extension point | What it does |
|---|---|---|
| `Strategy/BulkyGoodsStrategy.php` | `DeliverySplitStrategyInterface` | Rule *By size*: items from 30 kg ship by freight, the rest by parcel; customers can still move items. |
| `Subscriber/KeepGiftCardsWithFirstDelivery.php` | `DeliveryAllocationEvent` | Gift cards always ship with the first delivery, whatever the rule. |
| `Blocker/PreorderBlocker.php` | `SplitBlockerInterface` | Carts with pre-order items are not split. |
| `Subscriber/ErpShipmentReference.php` | `SplitOrderConvertedEvent` | Adds an ERP shipment reference to each order delivery. |
| `Resources/views/…/split-delivery.html.twig` | Twig block `component_mn_multi_delivery_item_title` | Shows "Parcel" and "Freight" as delivery titles. |
| `Resources/snippet/…` | snippets | Names of the rule, its deliveries and the block reason. |

Install it next to the main plugin (`custom/plugins/MnMultiDeliveryExample`) and activate it. *By size* is then offered next to the built-in rules; the *Rules customers can choose* setting only limits the built-in ones. Its classes are unit-tested in the main plugin's suite (`tests/Unit/Example`).
