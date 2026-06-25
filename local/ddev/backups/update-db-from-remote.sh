#!/bin/bash

# Configuration
HOSTS="prod:library-drupal-0.internal.lib.virginia.edu dev:library-drupal-develop-0.internal.lib.virginia.edu"
DEFAULT_ENV="dev"
SCRIPT_DIR=$(dirname "$(readlink -f "$0")")
BACKUP_DIR="$SCRIPT_DIR/../backups/sql"
IMPORT_DB=true
# Minimum plausible dump size (bytes). A real dump is >100MB; anything this
# small is almost certainly an error message captured instead of SQL.
MIN_DUMP_BYTES=1000000

while getopts "hn" opt; do
    case $opt in
        h) show_help=true ;;
        n) IMPORT_DB=false ;;
        *) show_help=true ;;
    esac
done
shift $((OPTIND-1))

if [[ ! -d "$BACKUP_DIR" ]]; then
    echo "Creating backup directory: $BACKUP_DIR"
    mkdir -p "$BACKUP_DIR"
fi

show_help() {
    echo "Usage: $0 [-n] [env]"
    echo "Options:"
    echo "  -n    Download only, skip import and cache clear"
    echo "  -h    Show this help message"
    echo "Environments:"
    for pair in $HOSTS; do
        env=${pair%%:*}
        host=${pair#*:}
        [[ "$env" == "$DEFAULT_ENV" ]] && default=" (default)" || default=""
        echo "  $env: $host$default"
    done
    exit 1
}

[[ -n "$show_help" ]] && show_help
ENV="${1:-$DEFAULT_ENV}"

HOST=""
for pair in $HOSTS; do
    if [[ "${pair%%:*}" == "$ENV" ]]; then
        HOST="${pair#*:}"
        break
    fi
done

[[ -z "$HOST" ]] && echo "Error: Unknown environment '$ENV'" && show_help

TSTAMP=$(date +%Y%m%d-%H%M%S)
BACKUP="${BACKUP_DIR}/library-drupal-$ENV-backup-$TSTAMP.sql.gz"
TEMP_SQL=$(mktemp)

eval "$(ssh-agent -s)"
ssh-add

echo "Using host: $HOST"

echo "Checking connection to $HOST..."
if ! ssh -o ConnectTimeout=5 "$HOST" sudo true 2>/dev/null; then
    echo "Connection to $HOST failed.  Check that you are on VPN"
    exit 1
fi

echo "Retrieving db from $HOST..."
# Notes:
#  - No `ssh -t` / `docker exec -it`: a tty merges stderr into stdout and adds
#    CR/control chars, which previously contaminated the dump file. Without it,
#    drush errors go to our terminal and only SQL lands in $TEMP_SQL.
#  - `--skip-ssl-verify-server-cert` keeps the DB connection encrypted but skips
#    cert-chain verification (MariaDB's equivalent of MySQL's --ssl-mode=REQUIRED),
#    working around the self-signed cert in the server's chain (error 2026).
#    If prod refuses TLS entirely, use `--skip-ssl` instead.
ssh "$HOST" 'sudo docker exec -i drupal-0 drush sql-dump --extra-dump="--no-tablespaces --skip-ssl-verify-server-cert"' > "$TEMP_SQL"
dump_status=$?

if [[ $dump_status -ne 0 ]]; then
    echo "Error: remote sql-dump failed (exit $dump_status). First lines of output:"
    head -5 "$TEMP_SQL"
    rm -f "$TEMP_SQL"
    exit 1
fi

echo "Validating SQL dump..."
dump_bytes=$(wc -c < "$TEMP_SQL")
if [[ "$dump_bytes" -lt "$MIN_DUMP_BYTES" ]]; then
    echo "Error: dump is only $dump_bytes bytes (< $MIN_DUMP_BYTES) — likely an error message, not a database. Contents:"
    head -5 "$TEMP_SQL"
    rm -f "$TEMP_SQL"
    exit 1
fi
if ! head -c 4096 "$TEMP_SQL" | grep -qE '^-- (MySQL|MariaDB) dump'; then
    echo "Error: dump does not start with a mysqldump header — not valid SQL. Contents:"
    head -5 "$TEMP_SQL"
    rm -f "$TEMP_SQL"
    exit 1
fi

echo "Compressing dump..."
if ! gzip -c "$TEMP_SQL" > "$BACKUP"; then
    echo "Error: failed to compress dump"
    rm -f "$TEMP_SQL" "$BACKUP"
    exit 1
fi
rm -f "$TEMP_SQL"

if ! gzip -t "$BACKUP" 2>/dev/null; then
    echo "Error: Backup file is not a valid gzip file"
    rm -f "$BACKUP"
    exit 1
fi

echo "Backup created successfully: $BACKUP ($(du -h "$BACKUP" | cut -f1))"

if $IMPORT_DB; then
    echo "Restoring db..."
    gunzip -c "$BACKUP" | ddev import-db
    echo "Clearing cache..."
    ddev drush cr
fi
