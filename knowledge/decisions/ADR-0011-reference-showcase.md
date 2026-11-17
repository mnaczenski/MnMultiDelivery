---
id: ADR-0011
status: accepted
scope: global
created: 2026-11-17
source: user decision during /onboard question review
---
# MnMultiDelivery is a reference / showcase plugin; the compatibility policy is best effort

## Context
The repository github.com/mnaczenski/MnMultiDelivery is public, has tags v1.0.0–v1.2.0 (none for 1.3.0) and no GitHub releases [verified]. On the rotfuchs shop the plugin is installed but inactive (1.3.0); 9 order deliveries carry its data [verified: live database, 2026-11-17]. docs/compatibility.md promises semver stability for 22 classes, events, custom fields, Twig blocks, snippet keys and Store API fields [verified].

## Decision
The plugin is a public reference of a well-built Shopware plugin, used by few. The compatibility policy stays as a statement of intent (best effort, not a guarantee). Fix the documentation so it doesn't overclaim.

## Alternatives considered
- Public product with a strict semver guarantee, tagged releases and an example plugin covering every extension point.
- Internal / rotfuchs only, with no guarantees.

## Consequences
Follow-ups (not yet planned):
- docs/compatibility.md: say the policy is best effort.
- README "Extending": fix the broken table row for `SeparateOrdersPlacedEvent` / `SplitOrderConvertedEvent` and add `SeparateOrdersPlacedEvent` to the Events table.
- README: narrow "the example plugin shows most of these extension points" to what it shows (strategy, blocker, `DeliveryAllocationEvent`, `SplitOrderConvertedEvent`, one Twig block); fix the example README's file name (`planner.html.twig`).
- Quality stays high, because a showcase is judged by its code: PHPStan level 8, tests and CI remain required.

## Implemented (1.4.0, 2026-11-17)
All four documentation follow-ups done (compatibility.md best-effort note, README Extending and Events tables, example plugin claim and file name). Also corrected: compatibility.md claimed the separate-order context keys are covered by integration tests; they are not.
