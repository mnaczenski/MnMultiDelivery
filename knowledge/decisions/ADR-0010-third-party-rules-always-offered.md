---
id: ADR-0010
status: accepted
scope: splitting-strategies
created: 2026-11-17
source: user decision during /onboard question review
---
# Splitting rules from other plugins are always offered; they decide via isAvailable()

## Context
The setting "Rules customers can choose" lists the four built-in rules [verified: config.xml, src/Strategy/StrategyRegistry.php:53-57].

## Decision
The setting limits only the built-in rules (`manual`, `availability`, `deliveryTime`, `warehouse`). A rule from another plugin is offered whenever its `isAvailable()` returns true; that plugin adds its own switch if merchants need one [verified: README "Splitting rules"].

## Alternatives considered
Letting the setting also list and switch off third-party rules.

## Consequences
Plugins adding rules are responsible for their own on/off setting. The README states this for extension developers.
