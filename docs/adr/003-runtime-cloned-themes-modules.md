# ADR 003: Clone first-party themes/modules at build time, not via Composer

**Status:** Accepted
**Date:** 2026-06-18 (documented retroactively)
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

Several first-party themes and modules (`uvalib/uvalib-drupal-theme`,
`uvalib/uvalib_drupal_theme_2026`, `uvalib/drupal_jsonapi_search_api_extension`,
`uvalib/drupal-uvaldap-module`) are developed in their own repositories.

## Decision

These repos are cloned into the webroot at build/run time (by the Dockerfile and the
DDEV post-start hook) rather than required through Composer.

## Consequences

- Themes and custom modules are developed and released on their own cadence, independent
  of this repo's `composer.lock`.
- Updating themes in a running container is possible without a rebuild:
  `ddev drush exec /opt/drupal/scripts/pull-uvalib-drupal-theme`.
- **Trade-off:** the exact theme/module commit is *not* pinned in `composer.lock`, so a
  rebuild can pick up upstream changes. Reproducible builds depend on the state of those
  upstream repos at build time.
- See [Container & build](../architecture/container-and-build.md).
