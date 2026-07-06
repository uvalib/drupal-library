# PHP OOM Fatals (Twig render + KCFinder upload)

**Status:** open — root cause of the memory growth not yet identified; alarm noise controlled
**Priority:** low-to-medium (self-clearing so far; worth fixing before it becomes user-visible)

## Problem

Both production nodes intermittently hit PHP's 1 GB memory limit and fatal:

```
PHP Fatal error:  Allowed memory size of 1073741824 bytes exhausted
  (tried to allocate 20480 bytes) in /opt/drupal/vendor/twig/twig/src/Error/Error.php on line 142
```

`Error.php:142` (occasionally `:165`) is just Twig's exception constructor — that's where the
process happened to run out of memory while building the error, not the actual allocation
hotspot. The real cause is further up the call stack and hasn't been isolated yet.

This is wired to CloudWatch (`uva-library-drupal-production-php-{0,1}-errors`, a `LogMetrics`
filter on the literal `php:error` in each node's container log), which pages
`uva-site-urgent-production` / `uva-drupal-urgent-production`.

## Findings (30-day scan, as of 2026-07-06)

**113 OOM fatals** across both nodes (54 on node-0, 59 on node-1). Two distinct signatures:

1. **Twig page-render OOM** — the large majority (~101 of 113 have no HTTP referer; a further
   handful referer `/teams/<uuid>`). Some single page or template appears to balloon memory
   during rendering.
2. **KCFinder upload OOM** — 7 hits, referer `/libs/kcfinder/upload.php` (CKEditor's file
   manager), across both `http`/`https` and `www`/bare-domain host variants. Distinct failure
   mode from the page-render OOM — likely a large file/image being processed on upload.

**Heavily clustered, then tapering** — not a steady background rate:

```
Jun  6-18   3 total (background rate)
Jun 19-30  99 total   ← storm, peaks of 24/day on Jun 20 and Jun 25
Jul  2- 6   6 total (back to background rate)
```

The Jun 19–30 storm window overlaps the Jun 25 staging rollout and Jun 29 prod deploy
(see [session logs](../session-logs/README.md) from that period) — worth checking whether a
theme/module change in that window changed what's rendered on the affected page(s), though
this hasn't been confirmed as causal.

**Alarm threshold already adjusted.** Alarm history shows these alarms originally paged at a
threshold of **1** occurrence per 5-minute period; the threshold is now **10**, presumably
raised specifically to cut noise from the low background rate. Current single-digit-per-day
volume no longer pages; a real storm like Jun 19–30 still would.

## Investigating further

A general-purpose tool for exactly this — "an alarm paged, what's actually in the logs" — now
lives outside this repo in the personal ops-scripts collection:
`uvalib/aws/scan-alerts` (see its
[README](https://github.com/ys2n/scripts/blob/main/uvalib/aws/README.md) for design notes).
Re-run periodically to check whether the rate is climbing again, e.g.:

```
scan-alerts -d 30 -q 'Allowed memory size' uva-library-drupal-production
```

## Next steps

- [ ] Identify the specific template/view behind the no-referer and `/teams/<uuid>` OOMs —
  likely something in the active theme (`uvalibrary_v2a`) or a view with an unbounded
  render (e.g. a large entity reference field with no pager).
- [ ] Confirm or rule out the Jun 25/29 deploys as the trigger for the Jun 19–30 storm.
- [ ] Separately investigate the KCFinder upload OOM — likely an image-processing step
  (thumbnailing/resizing) on a large upload; may just need a stricter upload size/dimension
  limit rather than a code fix.
- [ ] Decide whether `memory_limit` (currently 1 GB) should be raised as a stopgap while the
  underlying render is fixed, weighed against the risk of masking the real problem and
  increasing per-request memory footprint fleet-wide.

## Related

- [Redis cache backend](redis-cache-backend.md) — unrelated failure mode (DB deadlock, not
  OOM) but same "shared production infrastructure under load" theme.
- [Production deploy runbook](../operations/production-deploy-runbook.md)
