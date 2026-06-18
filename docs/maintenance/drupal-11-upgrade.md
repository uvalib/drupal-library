# Drupal 10 → 11 Upgrade

**Target agreed:** 2026-06-10. Upgrade from Drupal 10.x to Drupal 11, PHP 8.2 → 8.3.

## Prerequisites / blockers

- **Remove CKEditor 4.** `drupal/ckeditor` has no Drupal 11 support and must be removed
  first — see [CKEditor 4 → 5](ckeditor4-to-ckeditor5.md).
- **Contrib compatibility.** Every contrib module in `composer.json` must have a
  Drupal 11-compatible release. Use Upgrade Status (`drupal/upgrade_status`) to audit
  before bumping core.
- **Deprecated API usage** in the custom themes/modules (cloned at build time — see
  [Container & build](../architecture/container-and-build.md)) must be cleared.

## Environment changes

- `package/Dockerfile` base image → `drupal:11.x`.
- `.ddev/config.yaml` → `type: drupal11`, `php_version: "8.3"`.
- Pipeline specs and any PHP version references → 8.3.

## Status

**Planned.** CKEditor 4 removal is the first concrete step on the critical path.
