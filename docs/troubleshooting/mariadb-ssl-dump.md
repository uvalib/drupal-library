# MariaDB SSL Dump Failure (error 2026)

## Symptom

`./local/ddev/backups/update-db-from-remote.sh prod` produces a tiny (~4 KB) "dump"
that, when decompressed, contains:

```
mysqldump: Got error: 2026: "TLS/SSL error: self-signed certificate in
certificate chain" when trying to connect
In SqlCommands.php line 215:
  Unable to dump database.
```

## Cause

The remote DB is **MariaDB**, reached over TLS with a self-signed cert in the chain.
`drush sql-dump` invokes `mysqldump`, which fails verifying that chain.

Note: MySQL's `--ssl-mode=REQUIRED` does **not** work here — MariaDB's `mysqldump`
rejects it as `unknown variable 'ssl-mode'`. MariaDB uses different SSL flags.

## Fix

The dump passes MariaDB's "encrypt but don't verify" flag via `--extra-dump`:

```bash
drush sql-dump --extra-dump="--no-tablespaces --skip-ssl-verify-server-cert"
```

This is baked into `local/ddev/backups/update-db-from-remote.sh`. If prod ever refuses
TLS entirely, use `--skip-ssl` instead.

## Related hardening

The same script was hardened so a failed dump can no longer masquerade as success:

- Capture the real `drush` exit code (no `tee | gzip` pipe masking it).
- No `ssh -t` / `docker exec -it` — a tty merged stderr into the dump and added control
  characters.
- Validate by **size** (≥ 1 MB) and a **mysqldump header** check, replacing the old
  `mysql --execute="quit"` check (which ignored the file contents and always passed).
