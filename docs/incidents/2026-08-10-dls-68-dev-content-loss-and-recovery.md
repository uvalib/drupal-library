---
status: diagnosed
opened: 2026-08-10
jira: DLS-68
---

# 2026-08-10 — Dev content "disappearing": the prod↔dev ID-collision trap (DLS-68)

**Severity:** data loss (dev only — never touched staging/prod); one page's content and CSS
effectively unrecoverable-looking until root cause was found, then both recovered
**Trigger:** authoring new content directly on the `dev` environment across a weekly
prod→dev database sync boundary
**Status:** root cause identified and explained; the two affected artifacts were recovered;
the underlying mechanism (dev is a destructible weekly mirror) is unchanged and will recur
for anyone who repeats the same workflow — see [Resolution](#resolution) and the process
recommendation
**Jira:** DLS-68 (reporter: Ruobing / `wxf2nb`)

## Summary

Ruobing built a "Library Assessment" page on dev — content, a Layout Builder layout, a URL
alias, and a custom Asset Injector CSS rule — over several editing sessions around
**2026-07-27/28**, and had Christopher confirm he could see it on **7/30**. By the time she
checked again, the page at the URL she'd bookmarked (`/node/3190`) showed a different,
mostly-empty page, and the CSS rule was reduced to a fragment.

The instinctive read — "dev's database got reset around 7/30" — turned out to be wrong on
every specific. There was no reset near 7/30. What actually happened is structural: **dev and
production share the same auto-increment ID sequence** immediately after every weekly sync,
so new content created independently on both sides between syncs can land on the *same*
entity ID by coincidence. The next sync silently overwrites dev's version with production's
unrelated content at that ID — same URL, completely different page, no error, no log entry
that says "this was replaced."

## Investigation trail (what didn't work, and why it matters)

1. **"Reset around 7/30" — ruled out.** The only mechanism that overwrites dev is a
   TeamCity-scheduled DB clone (`Library_DrupalDbClone_ProductionStaging`, project
   `Library_DrupalDbClone`, id `Library_DrupalDbClone_ProductionStaging`), and it runs
   **weekly, Mondays 11:00 UTC only** — confirmed via TeamCity's REST API build history
   (7/13, 7/20, 7/27, 8/3, 8/10, nothing else). 7/30 was a Thursday; nothing ran near it.
2. **`information_schema.TABLES.CREATE_TIME` is not reliable evidence of a reset.** All of
   dev's core tables showed `CREATE_TIME` clustered within minutes of the *next* clone run —
   which looked like "the whole DB got recreated," but the same pattern showed up on
   **staging** too, which is known to run continuously. It's a MySQL 8 metadata-caching
   artifact, not a real recreation event. Don't use it for this kind of forensic.
3. **`node/3190` was a red herring.** Once the clone job's *own* backup mechanism was found
   (see below), the 8/3 pre-sync dev backup was searched for nid 3190 — it didn't exist there
   at all. The *current* node 3190 ("Library Assessment," single revision, created 7/28 by an
   unrelated user) is production's own, unrelated page that happened to land on the same ID.
4. **A genuine but unrelated deletion (nid 3183) was found and was also a red herring.** Dev's
   node-ID sequence had a clean gap at 3183 — created and later cleanly cascade-deleted
   (no orphaned revision/body/layout rows anywhere), consistent with a normal Drupal delete
   action, sometime between 7/30 and 8/3. Nothing ties this to Ruobing's page; who/what
   deleted it is still unknown and wasn't pursued further once the real culprit (below) was
   found. If it recurs, it's worth a dedicated look.
5. **The real page (node 3188) was never deleted.** Access-log evidence (Apache logs flow into
   the dev host's journald via the `syslog` docker logging driver, retained back to
   **February** — far longer than `watchdog`'s ~4.5h) showed a long Layout Builder editing
   session on 7/28 against `/node/3188/...`. The 8/3 pre-sync backup had node 3188 fully
   intact: title "Library Assessment," 56 revisions, and the final revision's own log message
   reads *"version 1 before feedback. css code functional but not yet cleaned up. pending
   comments and feedback on colors."* — conclusive.

## Root cause

**Dev and production's per-table auto-increment counters realign every time dev is cloned
from production.** If, in the week between syncs, *both* environments independently create
one new entity of the same type (a node, a block_content, whatever), there's a real chance
they land on the identical next-available ID — because both counters started that week from
the same number. The next sync then does a wholesale `DROP`+reload of dev's tables from a
`mysqldump` of production, silently replacing dev's row at that ID with production's row at
that ID. Same URL (`/node/{id}`), same config name (`asset_injector.css.{id}`) — completely
different content, with **no error, no log entry, nothing that flags the collision**. This
happened twice for the same page: once for the node (dev's 3188 → replaced by prod's
unrelated 3188), and independently for the Asset Injector CSS rule (dev's 13,005-byte
`asset_injector.css.library_assessment` → replaced by production's pre-existing 2,386-byte
stub of the same rule name — this is what "~10% left" was).

### The clone mechanism itself (previously undocumented)

- TeamCity project `Library_DrupalDbClone` (under `Library`) has 7 build configurations, one
  per environment pair. The one that overwrites dev is **`production -> develop0`**
  (id `Library_DrupalDbClone_ProductionStaging`), trigger: weekly, Monday 11:00 (server tz).
- Steps: dump prod's source DB → **dump dev's current DB as "Preserving destination
  dataset"** → restore prod's dump into dev.
- The "preserving destination" dump *is* a real backup of dev's pre-overwrite state — both
  dumps get uploaded to **`s3://library-drupal-content-global/db/`** as
  `<timestamp>-src.sql.gz` (prod) and `<timestamp>-dst.sql.gz` (dev, pre-overwrite), on every
  run. See [Syncing data → Automated environment sync](../operations/syncing-data.md).
- **The gap this doesn't cover:** backups only exist at the moment of each weekly sync.
  Anything created *and* subsequently overwritten-by-collision entirely within one week has
  no snapshot that catches it — the backup before creation predates it, the backup at the
  next sync is already the post-collision (i.e., already-overwritten) state.

## Resolution

Both artifacts were still recoverable because the backup at the *next* sync (2026-08-03,
`20260803-110015-dst.sql.gz`) captured dev's state **just before** that sync overwrote it —
i.e., exactly the content in question, one sync-cycle later than its creation.

- **CSS rule**: restored directly via Drupal's config API (`ConfigFactory::getEditable(...)
  ->set('code', ...)->save()`) after extracting the value byte-exact (see gotcha below).
- **Page content**: recreated as a **new node (nid 3194)**, not an overwrite of the live nid
  3188 (which now holds real, unrelated production content) — title, moderation state
  (`published`, via `moderation_state`, since this site uses Content Moderation and `status`
  isn't directly settable), the 7 Layout Builder sections, and the 7 inline `block_content`
  blocks those sections reference (IDs 1165–1174, confirmed free on live dev, so restored
  under their original IDs).

!!! warning "Gotcha #1: don't hand-parse `mysqldump` output"
    A first attempt manually unescaped a `mysqldump` row's PHP-serialized blob and silently
    truncated the last 322 bytes. Fix: import the actual row into a throwaway local MySQL
    container and let PHP's real `unserialize()`/MySQL's real parser do the work, then verify
    byte length against the value PHP's serialize format declares (`s:<N>:"..."`). Don't trust
    a hand-rolled escape parser on binary/serialized DB content.

!!! warning "Gotcha #2: restoring a `block_content` entity via raw SQL needs `*_field_revision`, not just `*_field_data`"
    The first restore attempt populated `block_content`, `block_content_field_data`, and
    `block_content_revision`, and the page **rendered fine** — but editing any restored
    inline block through Layout Builder failed with
    `SQLSTATE[23000]: ... Column 'default_langcode' cannot be null`. Root cause: Drupal's
    `SqlContentEntityStorage` reads translatable base fields (`default_langcode`, `status`,
    `changed`, `revision_translation_affected`) from **`block_content_field_revision`** for a
    given revision — a table distinct from `block_content_field_data` (the latter is only
    LEFT-JOINed in for the `reusable` field). Reads worked because rendering resolves via a
    different path; only *creating a new revision* (i.e., editing) exercised the missing
    table. Confirmed by tracing the actual query with `Database::startLog()`. The same
    `_field_data` vs `_field_revision` split applies to any revisionable+translatable content
    entity (`node` included) — but node restores here went through the real Entity API
    (`Node::create()->save()`), which handles this correctly on its own; the gotcha only bit
    the block_content entities because those were restored via raw SQL.

## What's still open

- **Process**: dev is a destructible weekly mirror by design and has no content-promotion
  path (unlike config, which — in principle — round-trips through
  `drush cex`/`cim`, though [that automation is currently broken](../maintenance/config-sync-mechanism-review.md)).
  Recommendation: author new production-bound **content** directly on production as
  unpublished/draft (Content Moderation is already enabled), and reserve dev for
  config/CSS/layout work you're prepared to re-export. Not yet communicated as team policy.
- **Node 3183's deletion** — cause unknown, unrelated to this incident, not pursued.
- A separate, unrelated finding surfaced while investigating: dev's container image was
  **6+ weeks stale** (no deploy pipeline exists for `dev`, only staging/prod auto-deploy),
  which was the cause of a *different* reported bug (Layout Builder's "Add Section" modal
  missing on dev). Manually redeployed dev to the current build as an immediate fix; a
  durable on-demand deploy pipeline for dev is proposed but **paused** — see
  [Proposal: develop deploy pipeline](../proposals/develop-deploy-pipeline.md).
