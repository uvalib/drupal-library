# Maintenance

Two kinds of planned work, neither of which is "a new feature":

1. **Regular upkeep** to keep the service running normally — upgrades, security/bug
   patches, module installation and retirement.
2. **Planned risk mitigation and gradual improvement** — work scheduled to head off a
   known problem or incrementally improve something that already works (e.g. moving cache
   onto Redis, refactoring NetBadge), as opposed to a new user-facing capability.

| Topic | Page |
|-------|------|
| How module/core updates are done | [Module updates](module-updates.md) |
| Removing CKEditor 4, migrating to CKEditor 5 | [CKEditor 4 → 5](ckeditor4-to-ckeditor5.md) |
| Drupal 10 → 11 upgrade | [Drupal 11 upgrade](drupal-11-upgrade.md) |
| Config-sync export mechanism (review/redesign) **(proposal)** | [Config-sync mechanism review](config-sync-mechanism-review.md) |
| Move cache off the shared DB to Redis (real fix for deploy deadlocks) **(proposal)** | [Redis cache backend](redis-cache-backend.md) |
| Fix `drush sql:*` failing on the RDS TLS cert (ship RDS CA bundle + `pdo` SSL config) — *should be a Jira ticket* | [drush RDS TLS cert](drush-rds-tls-cert.md) |

**(proposal)** = this maintenance work is still at the proposal stage — direction/details not
yet decided. Also listed in [Proposals](../proposals/README.md) for visibility; the doc lives
here rather than there because its home won't change once decided (unlike a pure proposal,
which moves to Operations/Architecture once it settles).

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
