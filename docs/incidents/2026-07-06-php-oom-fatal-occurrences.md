---
status: open
opened: 2026-07-06
jira: null
---

# 2026-07-06 — PHP OOM fatals: 30-day occurrence log

**Type:** recurring issue, not a single event — this is a factual log, updated as new scans
are run. The active investigation and next steps live in
[Troubleshooting: PHP OOM fatals](../troubleshooting/php-oom-fatals.md).

**Root cause:** not yet identified.

## What's being recorded

Both production nodes intermittently hit PHP's 1 GB memory limit and fatal:

```
PHP Fatal error:  Allowed memory size of 1073741824 bytes exhausted
  (tried to allocate 20480 bytes) in /opt/drupal/vendor/twig/twig/src/Error/Error.php on line 142
```

Wired to CloudWatch (`uva-library-drupal-production-php-{0,1}-errors`, a `LogMetrics` filter
on the literal `php:error` in each node's container log), paging
`uva-site-urgent-production` / `uva-drupal-urgent-production`.

## Occurrence log

### As of 2026-07-06 (30-day scan)

**113 OOM fatals** across both nodes (54 on node-0, 59 on node-1).

By day:

```
Jun  6-18   3 total (background rate)
Jun 19-30  99 total   ← storm, peaks of 24/day on Jun 20 and Jun 25
Jul  2- 6   6 total (back to background rate)
```

Two distinct signatures:

1. **Twig page-render OOM** — ~101 of 113 have no HTTP referer; a further handful referer
   `/teams/<uuid>`.
2. **KCFinder upload OOM** — 7 hits, referer `/libs/kcfinder/upload.php` (CKEditor's file
   manager), across both `http`/`https` and `www`/bare-domain host variants.

Alarm history shows these alarms originally paged at a threshold of **1** occurrence per
5-minute period; the threshold is now **10** (raised, presumably, to cut noise from the
background rate).

The Jun 19–30 storm window overlaps the Jun 25 staging rollout and Jun 29 prod deploy (see
[session logs](../session-logs/README.md) from that period) — not confirmed as causal, just
a temporal overlap worth investigating (tracked in
[Troubleshooting: PHP OOM fatals](../troubleshooting/php-oom-fatals.md)).

*(Append further scan results below as new dated subsections when re-run — see
[Troubleshooting: PHP OOM fatals](../troubleshooting/php-oom-fatals.md) for the
`scan-alerts` command to re-check.)*

## Related

- [Troubleshooting: PHP OOM fatals](../troubleshooting/php-oom-fatals.md) — active
  investigation and next steps
- [Redis cache backend](../maintenance/redis-cache-backend.md) — unrelated failure mode (DB
  deadlock, not OOM) but same "shared production infrastructure under load" theme
