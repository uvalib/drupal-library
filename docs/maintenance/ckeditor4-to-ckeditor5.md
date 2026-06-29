# CKEditor 4 → CKEditor 5 Migration

## Why

`drupal/ckeditor` (the **CKEditor 4** contrib module) is abandoned, end-of-life, carries
an open vulnerability (CVE-2024-24815), and is **not** covered by Drupal's security
advisory policy. It has no Drupal 11 support, so it also blocks the
[Drupal 11 upgrade](drupal-11-upgrade.md). CKEditor 5 ships in Drupal core and is the
supported editor. `drupal/ckeditor_plugin_report` is installed in Composer but not
enabled — remove it too.

## Current state

> **Correction (verified against a live prod DB import, and again on staging 2026-06-29):**
> the migration below was already done in production. All **3 text formats** (`basic_html`,
> `rds_text_editor`, `webform_default`) already run `editor: ckeditor5`, CKEditor 5 (core) is
> enabled, and the v4 `ckeditor` module is enabled but **unused** (no format references it, no
> enabled module depends on it). So there is **no content/format migration to do** — only the
> module removal. The original 2026-06-18 assessment below read the config-sync repo's stale
> `main` branch instead of the `production` branch; ignore it for the format state.

Original 2026-06-18 assessment (superseded, kept for context) — from the
[config-sync repo](../architecture/config-management.md):

- **3 text formats** still use `editor: ckeditor` (CKEditor 4): `basic_html`,
  `rds_text_editor`, `webform_default`.
- **CKEditor 5 (core) is not yet enabled.**
- `ckeditor` and `ckeditor_accordion` are enabled; `ckeditor_plugin_report` is not.

### Toolbar migration notes

Most buttons (Bold, Italic, Blockquote, lists, HorizontalRule, Source, links, image/media,
Format) auto-map via core's CKEditor 4→5 upgrade. Specific items to watch:

- **PasteFromWord** — drop it; CKEditor 5 handles Office paste automatically.
- **Styles** (`stylescombo`, with `img.blog-image-float-left/right`) — migrates to the
  CKEditor 5 *Style* plugin; re-verify the image-float options carry over.
- **Accordion** — `ckeditor_accordion` 2.3.0 ships a CKEditor 5 plugin (verified;
  supports D9.4/10/11), so re-add the button to the toolbar and confirm existing
  accordion markup still renders.

## Procedure

Do this against a **real prod DB imported locally** (see
[Syncing data](../operations/syncing-data.md)) so the migration runs over real content.

1. Enable the `ckeditor5` core module.
2. For each of the 3 formats (`/admin/config/content/formats`), switch the editor to
   CKEditor 5 — core runs the upgrade and flags incompatible buttons.
3. Resolve the flagged items (Style options, Accordion); drop PasteFromWord.
4. Once no format references CKEditor 4:
   ```bash
   ddev drush pmu ckeditor ckeditor_plugin_report
   ddev composer remove drupal/ckeditor drupal/ckeditor_plugin_report
   ```
5. `ddev drush cex` and commit the result to the config-sync repo.

## Rollout sequencing (important)

The Ansible deploy (`deploy_backend.yml`) only swaps the container — it runs **no
`pm:uninstall`, no `updb`, no `cim`**, so DB state (incl. `core.extension`) is never touched
by a deploy. The module must therefore be **uninstalled from the database before** the
code-removal image is deployed:

1. `drush pm:uninstall ckeditor` **while the running image still contains the code**.
2. Then deploy the image with `drupal/ckeditor` removed from Composer.

Reverse that order and bootstrap breaks with *"module ckeditor does not exist."*

## Production rollout

> Production runs **two nodes behind the ALB sharing one RDS database** (and a **database
> cache backend**). This makes the uninstall step a deadlock hazard — see the
> [2026-06-26 cache-deadlock incident](../incidents/2026-06-26-prod-cache-deadlock-wsod.md).

`drush pm:uninstall ckeditor` is **itself a cache-writer**: it deletes config and forces a
container/discovery rebuild, i.e. a burst of writes to the shared `cache_*` tables. Running
it on one node while the other serves live traffic is the same concurrent-writer pattern
that caused the WSOD. **Dropping `drush cr` from the deploy is necessary but not sufficient**
— the uninstall carries the same risk.

Do the production rollout one of these ways:

- **Maintenance-mode variant** (recommended for this change — it's a structural DB/config
  change): put the site in maintenance mode per the
  [production deploy runbook](../operations/production-deploy-runbook.md), run the uninstall
  + deploy the code removal, single `drush cr`, then exit maintenance. No live-traffic node to
  collide with → deadlock not a concern.
- **Or land [Redis](redis-cache-backend.md) first**, which removes the deadlock class entirely
  (no SQL row locks), then this becomes an ordinary deploy.

After deploy, verify on **both** prod nodes: code gone, bootstrap Successful, no
missing-module error, all 3 formats still `ckeditor5`, front page 200.

## Status

- **Local:** done 2026-06-25 (uninstall + `composer remove drupal/ckeditor`).
- **Staging:** ✅ done & verified 2026-06-29 — uninstalled, then shipped the code removal
  via PR #9 (`build-20260629205554`); all post-deploy checks green.
- **Production:** ⏳ pending — code removal is on `main` but prod is **manual-deploy**; run the
  [production rollout](#production-rollout) above (uninstall first, under maintenance mode).
- `ckeditor_plugin_report` left in Composer (disabled / not installed; can be dropped later).

block_class (the companion item assessed at the same time) is done — see
[Module updates](module-updates.md).
