# Deployment Strategy — Exploration & Open Questions

!!! note "Status: exploration, not a decision"
    This is a **thinking record** for future exploration and discussion — not an accepted
    decision (no ADR yet) and not an operational procedure. The procedure in force is the
    [production deploy runbook](production-deploy-runbook.md); the concrete near-term fix is
    [Redis cache backend](../maintenance/redis-cache-backend.md). This page captures *why*
    those are the frontier and what the bigger moves would cost, so the reasoning isn't
    re-litigated from scratch. Prompted by the
    [2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md).

## The core principle

Production runs **two nodes against shared state** (one RDS database — content, config,
schema; the cache; Solr). Every "can we deploy this without downtime?" question reduces to
a single axis:

> **Can node-old and node-new run *simultaneously* against the shared state without either
> one breaking?**

A node's own code/theme/Apache/PHP live in the container image and are *not* shared, so they
never block a rolling deploy. Only **shared, authoritative** state does. The rest of this
doc is consequences of that one question.

## A change taxonomy (the practical decision rule)

For any change, ask in order:

1. **Mutates shared DB schema/data non-additively?** → effectively **downtime**
   (maintenance/read-only window) *unless* decomposed via expand/contract.
2. **Mutates shared config?** → **rolling if made coexistence-safe**: determine
   compatibility direction, apply the shared change on the compatible side of the roll.
3. **Node-local only and needs no `drush cr`?** → **pure rolling**, any order.
4. **Node-local but needs a `cr`?** → class-3-style coupling sneaks in via the **shared
   cache**: one `cr` at the end (or Redis). The code change is trivial; the cache rebuild is
   the coupling.

This supersedes a "big vs small change" intuition. The driving question is *what shared
state does this touch, and can N and N+1 coexist against it* — answerable up front, per
change.

### Worked example: the 2026-06-26 deploy

A **mix** of class 1 (the Apache `HTTPS=on` line — node-local) and class 2 (`block_class`
2→3 — a contrib major bump with a config schema change). It was rollable **only because**
`block_class` 3 had *no pending `updb`* (additive config, no data migration). One destructive
update hook and it would have been class 3. The WSOD came from the class-4 trap above: a
per-node `cr` against the shared DB cache while the other node served live traffic.

## Making class 2 rolling

- **Compatibility direction.** Either old code tolerates new config (*backward-compatible* →
  apply shared change first, then roll code) or new code tolerates old (*forward-compatible*
  → roll code first, apply shared change last). Additive config with defaults is usually
  backward-compatible; renames/removals are not.
- **Make config import an explicit, ordered step** — not an implicit side effect of the
  container swap — placed on the compatible side of the roll.
- **Expand/contract (parallel change)** for anything not naturally compatible. This is what
  collapses the boundary between class 2 and class 3:
  1. **Expand** — add new schema/config additively (nullable column, new table, new key);
     old code ignores it.
  2. **Migrate/backfill** data.
  3. **Transition** — deploy code that reads the new shape.
  4. **Contract** — remove the old shape/code.

  Each deploy is individually rolling; the *sequence* accomplishes a breaking change with
  zero downtime. Cost: 2–4 deploys + discipline.

## The hard case: class 3 and DB-level approaches

### Why not blue-green the whole site

The DB is a **shared singleton**. You can't stand up a parallel "green" Drupal because it
would share (or fork) the one database — so a brief maintenance/read-only pause beats trying
to blue-green a stateful site. Keep the pause short; don't over-engineer around it.

### The DB blue-green idea (explored, parked)

**The proposal:** fork a DB replica, suspend replication, run the migration on the offline
(green) DB + new-code node, then cut the nodes over and let the replica "catch up."

**Why it doesn't catch up — the merge constraint.** Two DBs diverge in *different
dimensions* at once: the master keeps taking live **data** writes (old schema); the forked
replica gets new **schema**. Once DDL has run on the replica, **normal replication can't
resume** — the master's binlog events don't cleanly apply to the diverged shape, and even if
they did, those writes carry *old-schema semantics* and would need re-running through the
migration logic. That's CDC/forward-migration of the delta, not replication catch-up. It
only works cleanly if the cutover window is **write-free** (but then a short read-only freeze
+ in-place migration does the same job, and the blue-green bought nothing), or if you build
**delta reconciliation** (heavy — Debezium-class).

**The managed version: AWS RDS Blue/Green Deployments.** This is the proposal productized
(we're on RDS/MariaDB). Green is a clone kept *continuously* in sync via logical replication;
switchover blocks writes for ~sub-minute, confirms catch-up, flips endpoints. Crucially it
**only supports replication-compatible changes** (additive columns, indexes, minor-version
bumps) — *for exactly the merge reason above*. So it doesn't repeal the compatibility
question; it gives a clean cutover *once the change is already expand-shaped*. The mapping is
exact: **replication-safe ≈ additive/expand ≈ rolling-safe.**

**The decisive blocker for us: shared instance.** Our RDS instance hosts **several
projects' databases**. The binlog is a single whole-server stream, so a managed replica is
necessarily a copy of the *entire* instance — RDS Blue/Green is **all-or-nothing across every
database on the box**. A library deploy would force an unrequested whole-instance cutover:
co-tenants inherit the merge problem for data we aren't changing, plus cross-team
coordination for a routine push. The instance is simply the **wrong unit** of blue-green.

### What works on a shared instance instead

- **Online-DDL tools** — operate in-place on *your* tables only, no instance cutover.
  **gh-ost** (reads the binlog; lighter on a shared box) is generally friendlier than
  **pt-online-schema-change** (triggers add write overhead + metadata locks that can degrade
  neighbors). Right tool when a breaking *schema* change genuinely must be online.
- **Expand/contract** at the app layer — cheapest, no special infra, naturally
  project-scoped, and (not coincidentally) produces exactly the additive changes RDS
  Blue/Green *would* accept if the instance weren't shared.

### Drupal-specific friction

`hook_update_N` mixes **schema DDL and PHP data transforms**. Online-DDL tools and RDS B/G
handle the DDL-shaped part; they do nothing for the data-transform part, which still has to
be authored additively. So even with DB blue-green in hand, you're back to writing update
hooks that old code tolerates. Most contrib update hooks are *not* written this way — which
is why a contrib major bump with an update hook is usually genuinely class 3.

## Redis's role — derived vs. authoritative state

Redis sharpens the same distinction: **color-separate the things cheap to rebuild; share (or
reconcile) the things that are authoritative.**

- **Cache is derived.** Give green its own Redis **prefix**, warm it, discard blue's. The
  Drupal `redis` module supports a cache prefix, so this is clean and avoids re-creating the
  2026-06-26 bug. Cache color-separation is **logical** (a prefix), not an instance cutover —
  so it sidesteps the shared-infra all-or-nothing problem entirely, even on a shared
  ElastiCache. One place the shared infra doesn't bite.
- **Sessions, locks, semaphore, queue are authoritative-ish.** If those live in Redis and
  green gets a fresh prefix, everyone is logged out at cutover and in-flight locks/queue
  items vanish. Keep those on a *shared* backend across colors (or in the DB); only
  color-separate the cache.

This loops back to the core principle: blue-green is clean exactly to the extent the changing
state is **derived**. Schema/data and sessions are **authoritative** — and authoritative
shared state can't be forked-and-merged for free. That's why class 3 resists rolling,
restated at the infrastructure layer.

## Bottom line — the recommended frontier (in order)

1. **Redis cache backend** — kills the cache-coexistence deadlock class; logical/prefix
   scoping means the shared instance doesn't block it. ([follow-up](../maintenance/redis-cache-backend.md))
2. **Expand/contract discipline** — makes the rare breaking change rolling-safe (and
   RDS-Blue/Green-shaped, should that ever become available).
3. **Online-DDL (gh-ost)** — for a breaking *schema* change that genuinely must be online,
   without an instance cutover.
4. **Short read-only / maintenance window** — for the genuinely-coupled remainder. Accept
   it; keep it short.

Full DB blue-green is **off the table while the DB instance is shared** — the granularity
mismatch is fundamental, not a tuning problem.

## Open questions for future exploration

- **De-share the DB instance?** Giving the library project its own instance/cluster would
  re-enable per-project RDS Blue/Green *and* address blast radius, noisy-neighbor contention,
  independent right-sizing, and upgrade autonomy. Blue-green is only one symptom of the
  shared-instance coupling; the de-sharing decision deserves its own cost/benefit on those
  merits, not a deploy-mechanics justification alone. Candidate for a future ADR.
- **Read-only mode for Drupal** — how cleanly can the site run write-blocked (anonymous
  browsing intact) to shrink class-3 windows? Worth a small spike.
- **Where does Solr's shared schema sit** on the same coexistence axis for search-affecting
  deploys? Not analyzed here.

## Related

- [Production deploy runbook](production-deploy-runbook.md)
- [Incident: 2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md)
- [Redis cache backend](../maintenance/redis-cache-backend.md)
