# Troubleshooting

Active issues that need troubleshooting — known symptoms and either their fix, or the current
state of investigation if not yet resolved. Sorted **newest first** so this table doubles as
a recency log: scan it to see everything recently troubleshot, resolved or not, without
having to open every page.

| Opened | Status | Symptom | Page |
|--------|--------|---------|------|
| 2026-07-06 | 🟡 open | `PHP Fatal error: Allowed memory size ... exhausted` (Twig / KCFinder upload) | [PHP OOM fatals](php-oom-fatals.md) |
| 2026-06-26 | ✅ resolved | WSOD / cache `INSERT` deadlock (`SQLSTATE 40001` / 1213) after a deploy | [Incident: 2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md) |
| 2026-06-18 | ✅ resolved | `drush sql-dump` fails with TLS error 2026 / self-signed cert | [MariaDB SSL dump](mariadb-ssl-dump.md) |
| 2026-06-18 | ✅ resolved | SimpleSAMLphp `session.cookie.secure` error on the HTTP container | [SimpleSAMLphp secure cookie](simplesamlphp-secure-cookie.md) |
| 2026-06-18 | ✅ resolved | DDEV refuses to run the project from this directory | [DDEV one name per directory](ddev-one-name-per-directory.md) |
