# Compatibility policy

Multi Delivery follows [semantic versioning](https://semver.org) for its **public API**.

## What is public API

Everything marked `Public API: stable within a major version` in its docblock, plus the contracts below:

- **PHP**
  - interfaces: `DeliverySplitStrategyInterface`, `SplitBlockerInterface`, `DeliveryAddressPolicyInterface`, `ShippingCostPolicyInterface`
  - classes: `AbstractDeliveryPlanRoute`, `DeliveryPlanRouteResponse`, `DeliveryPlan`, `DeliveryGroupInfo`, `RootItem`, `Allocation`, `DeliveryGroup`, `DeliveryCountRule`, `SplitGuard`, `SplitSettings`, and the public methods of `DeliveryPlanService`
  - events: `DeliveryAllocationEvent`, `SplitDeliveriesBuiltEvent`, `DeliveryPlanChangedEvent`, `SplitOrderConvertedEvent`
- **Service tags:** `mn.multi_delivery.strategy`, `mn.multi_delivery.blocker`, `mn.multi_delivery.address_policy`
- **Store API:** the routes and fields in `src/Resources/Schema/StoreApi`
- **Data**
  - cart extensions `mnDeliveryPlan` and `mnDeliveryGroup`
  - `order_delivery.customFields.mn_multi_delivery` (`group`, `label`, `warehouseId`, `warehouseName`)
  - the Rule Builder condition `mnMultiDeliveryCount`
  - the technical rule names (`manual`, `availability`, `deliveryTime`, `warehouse`) and their group id formats (see *Delivery groups* in the README)
  - setting keys `MnMultiDelivery.config.*`
- **Templates**
  - Twig block names in `component/mn-multi-delivery/planner.html.twig`, `component/mn-multi-delivery/delivery-overview.html.twig` and the admin card `mn-multi-delivery-card`
  - the mail templates `mn-multi-delivery/mail/order-deliveries.{html,txt}.twig` and their variables (`order`, `currencyIsoCode`, `mnDeliveryTexts`)
- **Storefront:** the form actions and field names of the planner, the JS plugin name `MnMultiDelivery` and its `updated` event
- **Snippet keys** under `mnMultiDelivery` and `mn-multi-delivery`

## What is not

- Classes marked `@internal`: controllers, subscribers, the cart processor, installers.
- Built-in implementations: `ManualStrategy`, `AvailabilityStrategy`, `DeliveryTimeStrategy`, `WarehouseStrategy`, `SameCountryAddressPolicy`, `ExtraDeliveryCostPolicy`, `DeliveryPlanRoute`. Use them through their interfaces, tags and service ids. Their code and exact results can improve in minor versions.
- Markup and CSS classes inside the blocks. Override blocks, don't depend on the HTML they render today.

## Versions

- **Patch (1.0.x):** bug fixes only.
- **Minor (1.x):** new features and new extension points. Nothing public is removed or changed incompatibly.
- **Major (2.0):** may remove or change public API. Anything removed is first marked `@deprecated` for at least one minor version, with the replacement named in the docblock and the changelog.

Each minor version supports the Shopware minor versions named in `composer.json` (currently `~6.7.0`). Raising the minimum Shopware version is announced in the changelog.
