# Maintenance

Ongoing upkeep of the site: dependency updates, in-flight migrations, and the major
version upgrade.

| Topic | Page |
|-------|------|
| How module/core updates are done | [Module updates](module-updates.md) |
| Removing CKEditor 4, migrating to CKEditor 5 | [CKEditor 4 → 5](ckeditor4-to-ckeditor5.md) |
| Drupal 10 → 11 upgrade | [Drupal 11 upgrade](drupal-11-upgrade.md) |

## Validation / smoke tests

A lightweight validation suite is recommended to confirm the site functions after
deploys and dependency changes. Highest-value layers, in order:

1. **Smoke / E2E (Playwright)** — homepage + key pages return 200; site search returns
   results (exercises [Solr](../architecture/search-solr.md)); a JSON:API search
   endpoint returns valid JSON; NetBadge login *redirects* to the IdP (don't complete
   SSO in CI). Run against DDEV locally and against staging post-deploy.
2. **Drush health checks** — `drush status`, `drush core:requirements`,
   `drush watchdog:show --severity=Error`, `drush config:status` (drift vs the
   [config-sync repo](../architecture/config-management.md)).
3. **Static analysis at build time** — PHPCS (Drupal standards) + PHPStan, mostly for
   the custom themes/modules.
4. **Later** — Drupal Test Traits (PHPUnit against the real installed site), visual
   regression (useful during the 2026 theme work).
