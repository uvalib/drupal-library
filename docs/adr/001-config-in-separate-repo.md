# ADR 001: Store exported Drupal config in a separate repo

**Status:** Accepted
**Date:** 2026-06-18 (documented retroactively)
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

Drupal exports its configuration as YAML to a `config/sync` directory. This site keeps
that exported configuration in its own repository
(`uvalib/drupal-library-config-sync`), checked out at `/opt/drupal/config/sync` in the
container. In this code repo, `package/data/opt/drupal/config/sync/` holds only a
`read.me` placeholder.

## Decision

Exported Drupal configuration is version-controlled in the separate
`drupal-library-config-sync` repository, not in this code repository.

## Consequences

- Config changes (often exported from a running environment) are decoupled from code and
  dependency changes here, and can move on their own cadence.
- `drush config:status` has a committed baseline to detect drift against.
- **Caveat:** the empty `config/sync/` placeholder here can mislead someone into thinking
  config is untracked. To inspect text formats, blocks, enabled modules, etc. without a
  running site, clone the config-sync repo and read the YAML.
- After changing config on the site, run `drush cex` and commit to the config-sync repo.
