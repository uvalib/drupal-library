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
