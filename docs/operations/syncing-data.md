# Syncing Data from Remote Environments

Scripts in `local/ddev/backups/` pull a remote database or uploaded files into the
local DDEV environment. **All require VPN + SSH access** to the internal hosts.

## Database

```bash
# Pull and import the DB from dev or prod
./local/ddev/backups/update-db-from-remote.sh [dev|prod]

# Download only, skip import
./local/ddev/backups/update-db-from-remote.sh -n dev

# Back up the local DB
./local/ddev/backups/backup-local.sh
```

`update-db-from-remote.sh` SSHes to the host, runs `drush sql-dump` inside the
`drupal-0` container, validates and gzips the dump into `local/ddev/backups/sql/`, then
imports it into DDEV and clears caches.

The remote hosts:

| Env | Host |
|-----|------|
| prod | `library-drupal-0.internal.lib.virginia.edu` |
| dev | `library-drupal-develop-0.internal.lib.virginia.edu` |

!!! note "MariaDB TLS quirk"
    The remote DB is MariaDB reached over TLS with a self-signed cert. The dump uses
    `--skip-ssl-verify-server-cert` to avoid `mysqldump` error 2026. If a dump fails on
    TLS, see
    [Troubleshooting → MariaDB SSL dump](../troubleshooting/mariadb-ssl-dump.md).

!!! tip "Disk space"
    A prod dump is ~150–180 MB gzipped and expands to several GB on import. Make sure
    Docker Desktop has headroom (Settings → Resources → Disk usage limit) before
    importing, or the restore can fail mid-way.

## Files

```bash
# Rsync uploaded files from a remote environment
./local/ddev/backups/fetch-remote-files.sh [dev|staging|prod]
```
