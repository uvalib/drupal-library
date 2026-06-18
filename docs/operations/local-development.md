# Local Development (DDEV)

Local development uses [DDEV](https://ddev.com). The DDEV root is `local/ddev/`, with
symlinks pointing back into `package/data/opt/drupal/` for `composer.json`,
`composer.lock`, and `config`.

DDEV config: `.ddev/config.yaml` (`type: drupal10`, `php: 8.2`, `mysql: 8.0`).

## Common commands

```bash
ddev start          # start local env (runs post-start hooks)
ddev stop
ddev restart

ddev composer install
ddev composer require drupal/some_module
ddev composer update drupal/core --with-all-dependencies

ddev drush cr       # clear caches
ddev drush updb     # run database updates
ddev drush cim      # import config from config/sync/
ddev drush cex      # export config to config/sync/
ddev drush uli      # one-time login link
```

On `ddev start`, the post-start hook runs `git-checkout.sh` (clones the external
theme/module repos into the webroot if missing — see
[Container & build](../architecture/container-and-build.md)) and then `composer install`.

## One project name per directory

!!! warning "DDEV maps one project *name* to one directory"
    If the project has been started from a different checkout (e.g.
    `~/Code/ddev/drupal-library` vs `~/Code/uvalib/drupal-library`), DDEV will refuse to
    run it from a second directory while the first is registered. The database lives in
    a Docker volume keyed by project *name*, so re-pointing is safe:

    ```bash
    ddev stop --unlist drupal-library     # release the name (files untouched)
    cd /path/to/the/checkout/you/want
    ddev start                            # re-registers here, reuses the db volume
    ```

    See [Troubleshooting → DDEV one name per directory](../troubleshooting/ddev-one-name-per-directory.md).
