---
status: resolved
opened: 2026-06-26
jira: null
---

# 2026-06-26 — Production WSOD during rolling deploy (shared-cache deadlock)

**Severity:** brief partial outage (WSOD) on production
**Duration:** short — a single fatal at 11:14:50 EDT, cleared by a manual `drush cr`
**Trigger:** manual `drush cr` on one node while the other served live traffic
**Status:** resolved; preventive changes captured below

## Summary

During the manual rolling deploy of `build-20260625193203` (the SimpleSAMLphp
secure-cookie fix + `block_class` 3.0.0), `library.virginia.edu` served a **White Screen of
Death** for a period. The site was restored by running `drush cr`. Root cause: a **database
deadlock on the shared cache**, triggered by rebuilding cache on one node while the other
node was serving all live production traffic.

## Timeline (EDT)

| Time | Event |
|------|-------|
| ~10:50 | Node 1 drained from ALB; node 0 serving 100% |
| ~11:00 | Node 1 deployed (new image) |
| ~11:04 | `drush cr` run on node 1 (out of rotation, but **shares DB cache with live node 0**) |
| ~11:05 | Node 1 re-added, healthy |
| ~11:06 | Node 0 drained; **node 1 now serving 100%** |
| ~11:13 | Node 0 deployed |
| **11:14:50** | **`drush cr` on node 0 collides with node 1's live traffic → deadlock → fatal on node 1 → WSOD** |
| ~11:15 | Node 0 re-added; manual `drush cr` clears the cached error page |

## Evidence

One uncaught fatal in node 1's container log, at 11:14:50 EDT:

```
Uncaught PHP Exception Drupal\Core\Database\DatabaseExceptionWrapper:
"SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock;
 try restarting transaction: INSERT INTO "cache_entity" (...)"
```

- Exactly **one** deadlock fatal logged, on `cache_entity`. None before or since.
- Cache backend confirmed: **default database cache**, no Redis/Memcache enabled →
  all `cache_*` bins live in the shared RDS database, written by **both** nodes.

## Root cause

1. The two production nodes share **one database cache backend**.
2. `drush cr` is a **mass write** (bulk `INSERT`/`DELETE`) to the shared `cache_*` tables.
3. Running it on node 0 while **node 1 served live traffic** put two concurrent writers on
   the same `cache_entity` InnoDB rows → **deadlock (SQLSTATE 40001 / 1213)**. InnoDB chose
   node 1's transaction as the victim and rolled it back.
4. Drupal did not retry the cache write; the exception bubbled up **uncaught** → fatal.
5. **Why one fatal looked like a sustained outage:** the broken render was stored in the
   page/dynamic-page cache and re-served to subsequent visitors (cache hits = no new PHP =
   no new log lines). The manual `drush cr` flushed the cached bad page, restoring the site.

### The misconception that enabled it

The team's practice was *"`drush cr` each node while it's offline."* But **"offline" only
means out of the ALB** — the node still shares the database cache with the live node. So a
cache rebuild on the drained node still collides with live traffic on the shared cache
tables. Draining isolates HTTP, not the cache.

(The same shared-cache root cause produced a benign symptom earlier in the deploy:
`drush pm:list` reported `block_class 2.0.12` even after a `cr`, because the still-old node
kept repopulating the shared discovery cache. The on-disk code was correct.)

## Resolution

`drush cr` after both nodes were on the new image (no competing writer) rebuilt the cache
cleanly and flushed the cached error page.

## Corrective actions

**Immediate (done):**

- [Production deploy runbook](../operations/production-deploy-runbook.md) rewritten:
  **never** `drush cr` on one node while the other serves live traffic; do **one** `cr` at
  the end after both nodes are upgraded, or under maintenance mode for structural changes.

**Follow-up (the real fix):**

- [ ] **Move the cache off the shared database to Redis (or Memcache).** Redis handles
  concurrent writes without SQL row locks, eliminating this entire deadlock class. This is
  the standard multi-node Drupal production setup and the highest-value change. Tracked in
  [Redis cache backend](../maintenance/redis-cache-backend.md).

**Defensive (lower priority):**

- [ ] Consider deadlock-retry on cache writes and/or not caching error renders. Largely
  moot once Redis lands.

## Related

- [Production deploy runbook](../operations/production-deploy-runbook.md)
- [Redis cache backend](../maintenance/redis-cache-backend.md)
- [SimpleSAMLphp secure cookie](../troubleshooting/simplesamlphp-secure-cookie.md) — the fix
  this deploy was shipping
