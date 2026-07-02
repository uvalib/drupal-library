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

## Editing these docs

This documentation site is built with mkdocs and served in-app at `/devops-docs`
(gated by the `devops` role — see [Hostnames & URLs](../architecture/hostnames-and-urls.md)).

**The built site is committed to the repo** — there is *no* docs build step in the
Docker/CI image build. The image ships whatever `static/` is committed. So the docs
build is a **local dev step**, not a build-time one:

```bash
# 1. edit markdown under docs/ (or mkdocs/mkdocs.yml)
# 2. rebuild the static site
./scripts/build-docs.sh
# 3. commit BOTH the markdown and the regenerated static/ output
git add docs/ package/data/opt/drupal/web/modules/custom/devops_docs/static
git commit
```

`build-docs.sh` uses a local `mkdocs` if present, otherwise a throwaway Docker
container (no local Python needed).

### Advisory drift hook

Because the build is manual, it's possible to commit doc changes and forget to
rebuild `static/`. A tracked pre-commit hook catches that — **advisory only, it
never blocks a commit**; it just prints a heads-up so you can decide whether to
rebuild now or ship the fix and circle back. Install it once per clone:

```bash
./scripts/install-git-hooks.sh    # sets core.hooksPath to scripts/git-hooks
```

!!! note "Why committed static/ instead of building in CI"
    Keeping mkdocs out of the image build means a broken docs page (or the mkdocs
    toolchain itself) can never block the application deploy — docs and the app
    have very different urgency. The tradeoff is that `static/` build output lives
    in git history; acceptable here since the docs change infrequently.
