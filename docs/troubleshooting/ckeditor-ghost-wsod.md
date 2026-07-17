---
status: mitigated
opened: 2026-07-17
jira: null
---

# CKEditor 4 "ghost module" WSOD on /admin/modules

**Status:** mitigated (interim helper 2026-07-17); durable fix pending the prod uninstall

## Symptom

A non-prod environment (typically **staging**, or local ddev) throws a white screen of
death on **`/admin/modules`** — and other pages that enumerate the full extension list —
while normal pages (home, `/user/login`) still return 200. The container log shows:

```
Uncaught PHP Exception Drupal\Core\Extension\Exception\UnknownExtensionException:
  "The module ckeditor does not exist." at .../ExtensionList.php
  referer: .../admin/modules
```

## Cause

A **mismatch between code and database**:

- The running image is built from `main` (or any commit after `5d9513b`), so the CKEditor 4
  contrib module files (`web/modules/contrib/ckeditor`) are **removed**.
- The database still has **`ckeditor` enabled** in `core.extension`.

Drupal boots fine (it tolerates the missing module as a warning), but any operation that
enumerates every extension — `/admin/modules` — calls `getName()` on the enabled-but-missing
module and fatals.

This happens whenever a **prod DB snapshot lands on `main` code**, because:

- **Prod's DB still has `ckeditor` enabled** (prod deliberately runs a *pre-removal* image so
  its files and DB match — see the [CKEditor 4 removal](../maintenance/ckeditor4-to-ckeditor5.md)
  status), and
- the same snapshot also **disables `devops_docs`**, since neither module is in config-as-code
  (`core.extension.yml`).

It is the reverse of the required order: **files were removed before the DB was uninstalled.**
Live example: the 2026-07-14 prod→staging DB sync, and again after the 2026-07-17 `main` deploy
to staging.

!!! note "Prod is not affected"
    Prod runs an image where the ckeditor files are still present, matching its DB — so prod
    never hits this. This is exactly why the DLS-67 timezone fix shipped a decoupled image to
    prod instead of `main`.

## Fix (interim mitigation)

A guarded, idempotent helper normalises non-prod module state:

```bash
./local/ddev/backups/ckeditor-ghost-cleanup.sh [local|dev|staging]   # default: local
```

It:

- removes `ckeditor` from `core.extension` + deletes its `system.schema` key — **only when it
  is enabled AND its files are absent** (a genuine ghost). A plain `drush pm:uninstall ckeditor`
  cannot be used here: with the files already gone it throws the same `UnknownExtensionException`.
- re-enables `devops_docs` when the files are present but it is disabled.
- rebuilds cache only if it changed something.
- **refuses `prod`** (exit 2) — see below.

Because it is guarded, it is a **safe no-op** on a healthy environment (e.g. dev/prod, where
ckeditor's files are still present), so it can be run any time. It is also invoked automatically
at the end of `update-db-from-remote.sh` after a local DB import.

## Why it refuses prod

On prod's **two live nodes sharing one RDS cache backend**, a config delete + container/discovery
rebuild is a mass write to the shared `cache_*` tables — the same deadlock class as `drush cr`
that caused the [2026-06-26 WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md). The prod
uninstall must be done under the maintenance-mode variant of the
[Production Deploy Runbook](../operations/production-deploy-runbook.md), not this helper.

## Durable fix

Uninstall `ckeditor` on **prod while its files still exist** (maintenance-mode window), then deploy
the ckeditor-removed image. After that, prod's DB no longer seeds the ghost, every prod→staging
sync is clean, `main` becomes deployable to prod, and this helper becomes a permanent no-op that can
be retired. Tracked with the [CKEditor 4 removal](../maintenance/ckeditor4-to-ckeditor5.md) work and
the [Drupal 11 upgrade](../maintenance/drupal-11-upgrade.md).
