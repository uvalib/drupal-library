# Container & Build

The production site runs as a Docker container built from `package/Dockerfile`
(`FROM drupal:10.x`). Most of the image is assembled at build time:

- **Composer dependencies** — Drupal core and contrib modules/themes are installed from
  `package/data/opt/drupal/composer.json` / `composer.lock`.
- **APCu** — installed via `pecl install apcu` (no version pin).
- **Apache vhost** — `package/data/etc/apache2/sites-enabled/000-default.conf`.

## Repos cloned at build/run time

Several first-party themes and modules are **not** vendored through Composer. The
Dockerfile (and the DDEV post-start hook) clone them into the webroot at build/start time:

| Repo | Destination |
|------|-------------|
| `uvalib/uvalib-drupal-theme` | `web/themes/uvalib-drupal-theme` |
| `uvalib/uvalib_drupal_theme_2026` | `web/themes/custom/uvalib_drupal_theme_2026` |
| `uvalib/drupal_jsonapi_search_api_extension` | `web/modules/custom/drupal_jsonapi_search_api_extension` |
| `uvalib/drupal-uvaldap-module` | `web/modules/uvaldap` |

To update the themes in a running container without a full rebuild:

```bash
ddev drush exec /opt/drupal/scripts/pull-uvalib-drupal-theme
```

!!! note "Rationale"
    These repos are cloned at build/run time rather than required via Composer so the
    themes and custom modules can be developed and released on their own cadence,
    independent of this repo's `composer.lock`. The trade-off is that the exact theme
    commit is not pinned in `composer.lock` — see
    [ADR 003](../adr/003-runtime-cloned-themes-modules.md).

## What is managed in git

`package/data/opt/drupal/` holds the files actually tracked here — `composer.json`/`lock`,
the Apache vhost, helper scripts, and a `config/sync/` placeholder. The Drupal webroot
(`web/`) and `vendor/` are produced by Composer and are gitignored. Exported Drupal
configuration is tracked in a [separate repo](config-management.md).
