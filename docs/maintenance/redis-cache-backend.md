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

## Existing infrastructure (discovered 2026-06-29)

**No new Redis cluster is needed to provision — one already exists and the Drupal container
already connects to it.** Scanned `terraform-infrastructure`:

- There is **no dedicated Redis** for library-drupal (`library.virginia.edu/` has no
  `cache/` terraform dir).
- There **is a shared, environment-wide HA cluster**, defined at the repo top level in
  `staging/cache/` and `production/cache/`: `aws_elasticache_replication_group "redis_ha"`,
  named `ha-redis-{env}`, **engine Valkey 8.2**, port 6379, `multi_az_enabled = true`,
  `automatic_failover_enabled = true`, node type **`cache.t2.small`**. (Note:
  `number_cache_clusters = 2` is **commented out** — confirm the live node count backing the
  "HA" claim.)
- The library Drupal containers **already reach it today** — but only for **SimpleSAMLphp
  session storage**, not Drupal cache. In `container_0.env.managed` / `container.env` and the
  SAML `config.php`: `SIMPLESAML_STORE_TYPE=redis`, host
  `ha-redis-{env}.wbueu6.ng.0001.use1.cache.amazonaws.com`, **redis database 8**, key prefix
  `simplesaml:`. No AUTH/TLS (username/password default to null → SG-restricted in-VPC).

## Proposed fix

Point Drupal's cache backend at the **existing** `ha-redis-{env}` cluster so cache
reads/writes leave the relational database entirely. Redis/Valkey handles concurrent writes
without SQL row locks, which **eliminates this deadlock class** and removes the "don't `cr`
while the other node serves traffic" constraint.

Rough shape (to be spiked, not prescriptive):

- **No Terraform provisioning needed** — reuse the shared `ha-redis-{env}` endpoint (subject
  to the sizing review below).
- `composer require drupal/redis`; add the **PhpRedis** extension to `package/Dockerfile`
  (`pecl install redis`); enable the module.
- Point the cache backend at Redis in `settings.php`
  (`$settings['redis.connection']`, `$settings['cache']['default'] = 'cache.backend.redis'`),
  injected via the same container-env mechanism that already feeds the `SIMPLESAML_REDIS_*`
  vars.
- **Use a different redis database index and key prefix** from SAML — db 8 / `simplesaml:`
  are taken. Give Drupal its own (e.g. db 0 + prefix `drupal:`) so the two tenants don't
  collide on keys.
- Keep `cache_form` and anything requiring consistency on a safe backend per Drupal's
  Redis module guidance.

## Sizing assessment — questions for the cloud architect (Dave Goldstein)

Ballpark: the current **`cache.t2.small` (~1.55 GB, ~1.37 GB usable, burstable single-vCPU)**
is *plausibly* adequate for this site's Drupal cache dataset (a content site of this scale
typically holds a few hundred MB of `cache_*` data) **plus** the tiny SAML session load — but
it is **not generously sized**, and there are two real concerns beyond raw memory:

1. **Eviction-policy conflict from co-tenanting cache + sessions (the important one).**
   Redis `maxmemory-policy` is **instance-wide**, not per-database. A cache backend wants an
   LRU eviction policy (e.g. `allkeys-lru`) so it sheds entries under memory pressure instead
   of erroring. But this same instance holds **SAML sessions that must not be evicted** —
   `allkeys-lru` would happily evict session keys and **log users out**. Drupal also writes
   many *permanent* (no-TTL) cache entries, so `volatile-lru` + TTLs won't reliably bound it
   either. This argues for either a **dedicated cache node/instance for Drupal** (cleanest),
   or a larger node sized with confidence the combined working set never hits `maxmemory`.
2. **CPU / generation.** `t2` is an old burstable generation (single vCPU, CPU credits).
   Drupal cache ops are frequent but cheap (GET/SET); fine at this traffic, but sustained
   bursts can exhaust credits. Moving to **Graviton `t4g`** (e.g. `cache.t4g.small` ≈ same
   1.37 GB but 2 vCPU and cheaper, or `cache.t4g.medium` ≈ 3 GB for headroom) would be a
   strict improvement for a primary cache.

**Concrete data to bring to the conversation:** measure the actual dataset rather than guess
— from a local prod DB import, sum the `cache_*` table sizes (rough proxy for the Redis
working set, same order of magnitude). That converts "probably fits" into a number.

**Open questions for Dave:**
- Is `ha-redis-{env}` intended as a shared multi-tenant cluster, and who else is on it
  (noisy-neighbor / blast-radius)?
- What is the current `maxmemory-policy`, and is co-tenanting evictable cache with
  must-keep SAML sessions acceptable — or should Drupal cache get a dedicated instance?
- Is there memory/CPU headroom on the current node (CloudWatch:
  `global/cloudwatch-dashboards/cache-overview.tf`), or should we size up / move to `t4g`?
- Live node count (the `number_cache_clusters` line is commented out — is failover real?).

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
