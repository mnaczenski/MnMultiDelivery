---
id: ADR-0005
status: accepted
scope: extension-surface
created: 2026-11-17
source: /onboard
---
# Public extension API with a semver compatibility policy

## Context
Other plugins add rules, blockers, address policies and react to events [verified: README "Extending", examples/MnMultiDeliveryExample].

## Decision
Stable classes carry the docblock "Public API: stable within a major version" (22 classes), everything else `@internal` (18 files) [verified: grep]. Extension via service tags, decoration and events; scope defined in docs/compatibility.md [verified].

## Alternatives considered
Not documented.

## Consequences
Changes to public classes, events, custom fields, Twig block names, snippet keys and Store API fields need a major version [verified: docs/compatibility.md]. No `@deprecated`/`@api` markers exist yet, so the deprecation process is unexercised [verified: extension-surface.md].

## Confirmed (2026-11-17)
The policy is best effort, not a guarantee, because the plugin is a reference / showcase (ADR-0011).
