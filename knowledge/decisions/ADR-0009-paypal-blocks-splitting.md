---
id: ADR-0009
status: accepted
scope: cart-delivery-plan
created: 2026-11-17
source: user decision during /onboard question review
---
# PayPal blocks splitting by default

## Context
PayPal (Swag\PayPal) only receives the first delivery's shipping cost as the selected shipping option, which PayPal can reject [verified: help text in src/Resources/config/config.xml:17-19].

## Decision
The setting "Payment handlers that keep a single delivery" defaults to `Swag\PayPal`; `SplitGuard` keeps carts with such a handler unsplit [verified].

## Alternatives considered
Sending the summed shipping costs of all deliveries to PayPal (not implemented) [inferred].

## Consequences
Customers paying with PayPal can't split. Merchants can remove the default if a PayPal version handles split shipping.
