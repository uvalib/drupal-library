# ADR 004: Upgrade to Drupal 11 (PHP 8.3)

**Status:** Accepted
**Date:** 2026-06-10
**Deciders:** Yuji Shinozaki (Lead Architect)

## Context

The site runs Drupal 10.x on PHP 8.2. Drupal 11 is the actively developed branch and
Drupal 10 is approaching end-of-life.

## Decision

Upgrade the site from Drupal 10.x to **Drupal 11**, moving from **PHP 8.2 to PHP 8.3**.

## Consequences

- `package/Dockerfile` base image → `drupal:11.x`; `.ddev/config.yaml` →
  `type: drupal11`, `php_version: "8.3"`; pipeline PHP references → 8.3.
- Every contrib module must have a Drupal 11-compatible release before core is bumped
  (audit with Upgrade Status).
- **CKEditor 4 (`drupal/ckeditor`) must be removed first** — it has no Drupal 11 support.
  See [CKEditor 4 → 5](../maintenance/ckeditor4-to-ckeditor5.md).
- Deprecated API usage in the custom themes/modules must be cleared.
- See [Drupal 11 upgrade](../maintenance/drupal-11-upgrade.md) for the working plan.
