# Incidents

The facts of what happened in production: timeline, evidence, and root cause (if known) —
whether that's a single discrete event or the accumulated occurrences of a recurring issue.
An incident note is the record; the [runbooks](../operations/README.md) capture any
*corrected procedure* that came out of it, and an active, unresolved problem's ongoing
investigation lives in [Troubleshooting](../troubleshooting/README.md).

| Date | Incident | Root cause |
|------|----------|-----------|
| 2026-06-26 | [Production WSOD during rolling deploy](2026-06-26-prod-cache-deadlock-wsod.md) | Database deadlock on the shared cache (`drush cr` on one node while the other served live traffic) |
| 2026-07-06 | [PHP OOM fatals — 30-day occurrence log](2026-07-06-php-oom-fatal-occurrences.md) | Not yet identified — Twig render + KCFinder upload, two distinct signatures |
| 2026-07-10 | [Site timezone off by 4h (DLS-67)](2026-07-10-scheduler-timezone-utc-clobber.md) | SimpleSAMLphp resets the request timezone to UTC in-process (`config.php` `getenv('TZ')`, container `TZ=UTC`), clobbering Drupal's `America/New_York`; fix verified locally, deploy pending |
| 2026-08-10 | [Dev content "disappearing": prod↔dev ID-collision trap (DLS-68)](2026-08-10-dls-68-dev-content-loss-and-recovery.md) | Dev's and prod's auto-increment counters realign on every weekly sync; content independently created on both sides can collide on the same ID, and the next sync silently overwrites dev's with prod's unrelated content at that ID. Both artifacts recovered from the pre-sync S3 backup. |
