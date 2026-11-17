---
id: ADR-0007
status: accepted
scope: global
created: 2026-11-17
source: user decision during /onboard question review
---
# Build storefront and admin assets at release time; no builds in git

## Context
Until 1.3.0 the built storefront JS (`src/Resources/app/storefront/dist`) and admin assets (`src/Resources/public/administration`) are committed, so the plugin can be installed by copying it from git without Node on the server (ADR-0006). Nothing checks that the committed builds match the sources, so a forgotten rebuild ships old code with green CI.

## Decision
Remove the built assets from git (and ignore them). On every version tag, CI builds the plugin with `shopware-cli extension zip`, which builds admin and storefront assets, and attaches the zip to the GitHub release. Shops, rotfuchs included, install the release zip.

## Alternatives considered
- Keep committing builds and add a CI check that rebuilds and compares them (keeps git installs working, drift caught by CI).
- Keep committing builds, clarify the README only (drift stays possible).

## Consequences
- No drift between sources and builds possible.
- Installing straight from a git checkout no longer works without a build (`bin/build-administration.sh` / `bin/build-storefront.sh` in the shop root, Node needed).
- CI needs a release job (shopware-cli); integration tests must build the assets too, or the admin-override check and storefront behaviour run against sources only [inferred].
- The rotfuchs deploy changes from copying the folder to installing the release zip.
- README "Installation" and "Development" need rewriting. Estimated about 1 day. Not yet planned as a feature.
