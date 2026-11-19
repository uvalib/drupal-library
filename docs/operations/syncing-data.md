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

## Automated environment sync (TeamCity) — distinct from the above

The above is a developer pulling a copy into their **own local DDEV**. Separately, and
**automatically**, dev's live database is periodically overwritten wholesale from
production — this is a different mechanism, previously undocumented, that any content
authored directly on dev is exposed to.

- Managed in **TeamCity**, project `Library` → `Library / Drupal DB clone`
  (`https://teamcity.lib.virginia.edu/project/Library_DrupalDbClone`), 7 build
  configurations (one per environment pair, e.g. `production -> develop0`,
  `production -> staging0`, `develop0 -> staging0`).
- The one that overwrites **dev**: `production -> develop0`
  (id `Library_DrupalDbClone_ProductionStaging`) — trigger: **weekly, Monday 11:00**
  (server timezone), no other schedule, no path-based triggers.
- Each run: dumps prod's source DB → dumps dev's **current** DB ("Preserving destination
  dataset," i.e. a real pre-overwrite backup) → restores prod's dump into dev's tables
  (`DROP`+reload). Both dumps are uploaded to
  **`s3://library-drupal-content-global/db/`** as `<timestamp>-src.sql.gz` (prod) and
  `<timestamp>-dst.sql.gz` (dev, pre-overwrite) — the `-dst` file is what you'd pull to
  recover dev's state from just before any given sync.
- Runner scripts live in `gitlab.com:uvalib/build-deploy-scripts` (checked out locally at
  `~/Code/uvalib/build-deploy-scripts`), specifically
  `library-drupal-db/deploy.ksh` + `library-drupal-db/environment.vars`
  (`TARGET_BUCKET=library-drupal-content-global`, `TARGET_DIR=db`). The image that
  actually performs the dump/restore/upload is
  `uvalib/drupal-migrate-tools` (ECR), running `clone_db.ksh` then `s3_uploader.ksh`.

!!! danger "Consequence: dev and prod's auto-increment counters realign on every sync"
    Immediately after a sync, dev's tables are byte-identical to prod's at that moment —
    including each table's next auto-increment value. If content is created independently
    on **both** dev and prod before the next sync, there's a real chance they land on the
    *same* entity ID. The next sync then silently replaces dev's entity at that ID with
    prod's unrelated one — same URL, different content, no error, nothing that flags the
    collision. See
    [Incident: DLS-68 dev content loss](../incidents/2026-08-10-dls-68-dev-content-loss-and-recovery.md)
    for the full mechanism, the recovery procedure, and gotchas restoring entities from a
    raw dump. **Don't author production-bound content directly on dev** — see that
    incident's process recommendation.

!!! note "Backups only bracket a week, they don't cover it"
    Because syncs are weekly, anything created *and* overwritten-by-collision entirely
    within one week has no snapshot that catches it — the prior week's `-dst` backup
    predates its creation, and the current week's `-dst` backup is already the
    post-collision state. Only content that survives to the *next* sync boundary is
    recoverable from these backups.
