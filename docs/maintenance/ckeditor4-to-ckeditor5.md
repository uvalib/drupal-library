# CKEditor 4 → CKEditor 5 Migration

## Why

`drupal/ckeditor` (the **CKEditor 4** contrib module) is abandoned, end-of-life, carries
an open vulnerability (CVE-2024-24815), and is **not** covered by Drupal's security
advisory policy. It has no Drupal 11 support, so it also blocks the
[Drupal 11 upgrade](drupal-11-upgrade.md). CKEditor 5 ships in Drupal core and is the
supported editor. `drupal/ckeditor_plugin_report` is installed in Composer but not
enabled — remove it too.

## Current state (assessed 2026-06-18)

From the [config-sync repo](../architecture/config-management.md):

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

## Status

**Planned / not yet executed.** block_class (the companion item assessed at the same
time) is done — see [Module updates](module-updates.md).
