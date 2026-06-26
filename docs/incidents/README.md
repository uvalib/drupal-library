# Incidents

Post-mortems for production incidents: what happened, the root cause, and what changed as a
result. An incident note records *what went wrong once*; the
[runbooks](../operations/README.md) record the *corrected procedure* that came out of it.

| Date | Incident | Root cause |
|------|----------|-----------|
| 2026-06-26 | [Production WSOD during rolling deploy](2026-06-26-prod-cache-deadlock-wsod.md) | Database deadlock on the shared cache (`drush cr` on one node while the other served live traffic) |
