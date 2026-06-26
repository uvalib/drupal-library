# Redis Cache Backend (follow-up)

**Status:** proposed — not yet implemented
**Priority:** high (real fix for the [2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md))

## Problem

Production runs **two nodes** sharing a **single database cache backend** (Drupal's
default; no Redis/Memcache). All `cache_*` bins live in the shared RDS database and are
written by both nodes. Under concurrency — especially a `drush cr` mass-write on one node
while the other serves live traffic — the nodes deadlock on the same InnoDB cache rows
(`SQLSTATE 40001 / 1213`), producing uncaught fatals and a WSOD. This happened during the
2026-06-26 production deploy.

The [deploy runbook](../operations/production-deploy-runbook.md) now works around it
procedurally (single `cr` at the end / maintenance mode), but the procedure is fragile —
the database cache is simply not appropriate for a multi-node site at this traffic level.

## Proposed fix

Add a shared **Redis** (or Memcache) cache backend so cache reads/writes leave the
relational database entirely. Redis handles concurrent writes without SQL row locks, which
**eliminates this deadlock class** and removes the "don't `cr` while the other node serves
traffic" constraint.

Rough shape (to be spiked, not prescriptive):

- Provision a Redis endpoint (ElastiCache) reachable from both nodes — Terraform in
  `terraform-infrastructure/library.virginia.edu/`.
- `composer require drupal/redis`; enable the module.
- Point the cache backend at Redis in `settings.php`
  (`$settings['redis.connection']`, `$settings['cache']['default'] = 'cache.backend.redis'`),
  injected via the container environment alongside the other prod settings.
- Keep `cache_form` and anything requiring consistency on a safe backend per Drupal's
  Redis module guidance.

## Notes / caveats

- A **shared** Redis still has the harmless version-skew display quirk mid-deploy (the
  discovery cache reflects whichever node wrote last) — but **not** the WSOD-class deadlock,
  which is the part that caused the outage.
- Verify behavior on staging first; staging is single-node, so load-test the concurrent
  case deliberately.
- Revisit the deploy runbook once this lands — the maintenance-mode requirement for
  structural changes can likely be relaxed.

## Related

- [Incident: 2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md)
- [Production deploy runbook](../operations/production-deploy-runbook.md)
- [Deploy strategy exploration](../operations/deploy-strategy-exploration.md) — the broader rolling/blue-green analysis and where Redis fits
