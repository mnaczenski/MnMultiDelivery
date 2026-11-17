---
id: ADR-0006
status: superseded by ADR-0007
scope: global
created: 2026-11-17
source: /onboard
---
# Ship prebuilt storefront and admin assets in the repository

## Context
Installing should not require Node builds in the shop [inferred].

## Decision
Committed builds in `src/Resources/app/storefront/dist` and `src/Resources/public/administration` [verified: README "Installation", git history].

## Alternatives considered
Not documented.

## Consequences
Sources and builds can drift, and nothing checks it. The build scripts named in the README (`bin/build-storefront.sh`, `bin/build-administration.sh`) are Shopware's own scripts in the shop root, not in this repository [verified: present in the dockware shop and on the rotfuchs server; used for commit 126ac9e].
