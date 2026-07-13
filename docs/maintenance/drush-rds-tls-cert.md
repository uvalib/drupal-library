# drush SQL commands fail against RDS TLS (self-signed cert in chain)

!!! info "Status"
    **Diagnosed, fix identified, not yet done.** Small change, but it touches the DB connection
    (`$databases`), so it needs live CA validation + its own deploy. **Should be entered in Jira
    as its own ticket** — it surfaced during the DLS-67 work (2026-07-13) but is an entirely
    separate concern and should not ride along with that fix.

## Symptom

`drush sql:query`, `drush sql:cli`, and `drush sql:dump` fail against the environment databases:

```
ERROR 2026 (HY000): TLS/SSL error: self-signed certificate in certificate chain
```

The site itself is unaffected — pages load, `php:script` reads the DB fine. Only the drush
`sql:*` family breaks. (Discovered reading node 3172 during the
[DLS-67 timezone incident](../incidents/2026-07-10-scheduler-timezone-utc-clobber.md); the
DLS-67 runbook works around it with `php:script`.)

## Root cause

The database is **AWS RDS MySQL 8** (`MYSQL_HOST` → `rds-mysql8-<env>.internal.lib.virginia.edu`).
RDS presents a TLS cert signed by the **Amazon RDS CA**, which is not in the container's trust
store — so any client that *verifies* the chain sees a "self-signed certificate in certificate
chain." Two clients, opposite defaults:

| Client | Verifies server cert? | Result |
|--------|-----------------------|--------|
| **PHP PDO** (the site, `php:script`) | **No** by default, and `settings.php` sets no SSL config | connects fine |
| **MariaDB CLI** (`11.8.x`, what drush shells out to) | **Yes** by default | rejects the RDS chain |

`settings.php` (in-repo, `package/data/opt/drupal/web/sites/default/settings.php`, baked via
`package/Dockerfile`) builds the connection purely from `MYSQL_*` env vars with **no SSL keys**,
so nothing tells either client where the RDS CA is.

Crucially, drush invokes the client as `mysql --defaults-file=/tmp/drush_XXXX …`. **`--defaults-file`
suppresses all other option files** (`~/.my.cnf`, `/etc/mysql/my.cnf`), so a dropped-in `my.cnf`
would be *ignored* — the SSL option has to land in the temp file drush itself writes.

## The fix

Drush builds that temp file in `Drush\Sql\SqlMysql::creds()`, which maps a specific set of
`pdo` keys onto the `mysql` CLI — `PDO::MYSQL_ATTR_SSL_CA` → `ssl-ca=…` (also `SSL_CAPATH`,
`SSL_CERT`, `SSL_KEY`, `SSL_CIPHER`). So a single `pdo` block in `settings.php` fixes the CLI
across **all** environments (all use RDS via `MYSQL_HOST`):

```php
// package/data/opt/drupal/web/sites/default/settings.php — in $databases['default']['default']
'pdo' => array(
  PDO::MYSQL_ATTR_SSL_CA                 => '/opt/drupal/rds-global-bundle.pem',
  PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,   // pin PDO's current behavior — see risk note
),
```

Plus ship the RDS CA bundle in `package/Dockerfile`:

```dockerfile
RUN mkdir -p /opt/drupal && \
    curl -fsSL https://truststore.pki.rds.amazonaws.com/global/global-bundle.pem \
      -o /opt/drupal/rds-global-bundle.pem
```

Effect:

- **drush** gets `ssl-ca=/opt/drupal/rds-global-bundle.pem` in its temp defaults file → the
  MariaDB CLI verifies the RDS chain → `sql:query` / `sql:cli` / `sql:dump` work, no flags, no
  `php:script` workaround.
- Providing only `ssl-ca` makes the CLI verify the **chain** but not the hostname, so the internal
  CNAME (`rds-mysql8-…` → the real RDS endpoint) won't trip a hostname mismatch — the current
  failure is a chain-trust failure specifically.

### Why not "just skip verification"?

`creds()` has **no** mapping for `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT`, so a "skip verify" toggle
can't be propagated to the CLI through drush. The CA-trust route is the only clean lever — which
is fine, because it's the more correct one (encrypted **and** authenticated).

## Risk & how to de-risk

`$databases` is the site's lifeline. A wrong bundle path or a CA that doesn't match this RDS
server would be a **full DB-connection outage**, not a cosmetic failure. Therefore:

1. **Separate deploy from DLS-67** (different blast radius — DLS-67 is a harmless env var; this is
   the DB connection).
2. **Setting `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false`** deliberately pins PDO to its current
   "connect, don't verify" behavior so the running site's connection is unchanged; only the CLI
   starts verifying (via `ssl-ca`).
3. **Validate the CA against the live server first — no deploy needed.** Inside the container, run
   the MariaDB CLI manually with the bundle (password via `MYSQL_PWD` so it's not in `argv`):
   ```bash
   # download the bundle to /tmp first (or curl it if the container has egress)
   MYSQL_PWD="$MYSQL_PASSWORD" mysql -h "$MYSQL_HOST" -u "$MYSQL_USER" "$MYSQL_DATABASE" \
     --ssl-ca=/tmp/rds-global-bundle.pem -e 'SELECT 1'
   ```
   `SELECT 1` succeeding with the bundle (and failing without it) confirms the bundle is the right
   trust anchor before any `settings.php` change.
4. Roll out per the [production deploy runbook](../operations/production-deploy-runbook.md); verify
   `drush sql:query "SELECT 1"` works on each node after.

## Current workaround (until this ships)

Read the DB through Drupal's PDO instead of the CLI — `drush php:script -` on stdin:

```bash
sudo docker exec -i drupal-0 /opt/drupal/vendor/bin/drush php:script - <<'PHP'
print(\Drupal::database()->query('SELECT 1')->fetchField().PHP_EOL);
PHP
```

## Related

- [Production deploy runbook](../operations/production-deploy-runbook.md)
- [DLS-67 timezone fix runbook](../operations/dls-67-timezone-fix-runbook.md) — where this was hit and worked around
- `package/data/opt/drupal/web/sites/default/settings.php` — where the `pdo` block goes
- `package/Dockerfile` — where the CA bundle is shipped
