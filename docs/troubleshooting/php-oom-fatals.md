---
status: open
opened: 2026-07-06
priority: medium
jira: null
---

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

## Current state

**113 OOM fatals** in the last 30 days (as of 2026-07-06), two distinct signatures (Twig
page-render vs. KCFinder upload), heavily clustered Jun 19–30 then back to a low background
rate. Full occurrence data, day-by-day breakdown, and alarm-threshold history:
[Incidents: PHP OOM fatals — 30-day occurrence log](../incidents/2026-07-06-php-oom-fatal-occurrences.md).
Append new scan results there as they're gathered, rather than duplicating them here.

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

- [Incidents: PHP OOM fatals — 30-day occurrence log](../incidents/2026-07-06-php-oom-fatal-occurrences.md)
- [Redis cache backend](../maintenance/redis-cache-backend.md) — unrelated failure mode (DB
  deadlock, not OOM) but same "shared production infrastructure under load" theme.
- [Production deploy runbook](../operations/production-deploy-runbook.md)
