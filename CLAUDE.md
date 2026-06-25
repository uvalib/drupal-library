# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository overview

This is the **library.virginia.edu** Drupal 10 site repo for the University of Virginia Library. It manages the site's Composer dependencies, Drupal configuration, custom themes/modules, Apache config, and CI/CD pipeline definitions. The running site is a Docker container built from `package/Dockerfile`.

## Directory structure

```
drupal-library/
├── package/           # Production container definition
│   ├── Dockerfile     # Builds the production image (FROM drupal:10.x)
│   └── data/opt/drupal/
│       ├── composer.json / composer.lock   # Drupal dependencies
│       ├── config/sync/   # Drupal exported configuration (YAML)
│       └── scripts/       # Helper scripts (e.g., pull-uvalib-drupal-theme)
├── local/ddev/        # Local development root (ddev docroot)
│   ├── composer.json → ../../package/data/opt/drupal/composer.json (symlink)
│   ├── composer.lock → ../../package/data/opt/drupal/composer.lock (symlink)
│   ├── config → ../../package/data/opt/drupal/config (symlink)
│   ├── web/           # Drupal webroot (core + contrib; mostly gitignored)
│   ├── vendor/        # Composer-installed packages (gitignored)
│   ├── patches/       # Composer patches
│   └── backups/       # DB backup scripts and local SQL dumps
├── pipeline/
│   ├── buildspec.yml  # AWS CodeBuild: Docker image build + ECR push
│   └── deployspec.yml # AWS CodeBuild: Terraform + Ansible deploy
└── .ddev/             # DDEV local dev configuration
    ├── config.yaml    # DDEV project config (type: drupal10, php: 8.2, mysql: 8.0)
    └── web-build/Dockerfile  # Extra steps appended to ddev's webimage
```

**Key distinction:** `package/data/opt/drupal/` holds the files that are actually managed in git (composer.json/lock, config, Apache vhost). The `local/ddev/` directory is the ddev working root, with symlinks pointing back into `package/data/`.

## Local development (DDEV)

```bash
ddev start          # Start local environment (runs post-start hooks automatically)
ddev stop
ddev restart

ddev composer install          # Install/update PHP dependencies
ddev composer require drupal/some_module   # Add a new module
ddev composer update drupal/core --with-all-dependencies  # Upgrade core

ddev drush cr          # Clear caches
ddev drush updb        # Run database updates
ddev drush cim         # Import config from config/sync/
ddev drush cex         # Export config to config/sync/
ddev drush uli         # Generate one-time login link
```

On `ddev start`, the post-start hook in `.ddev/config.yaml` runs `git-checkout.sh` (clones external theme/module repos into the webroot if missing) and then `composer install`.

### Syncing remote database or files locally

```bash
# Pull and import DB from dev or prod (requires VPN + SSH access)
./local/ddev/backups/update-db-from-remote.sh [dev|prod]
./local/ddev/backups/update-db-from-remote.sh -n dev   # download only, skip import

# Rsync uploaded files from a remote environment
./local/ddev/backups/fetch-remote-files.sh [dev|staging|prod]

# Backup local DB
./local/ddev/backups/backup-local.sh
```

## Architecture: external repos cloned at runtime

The production Dockerfile and the ddev post-start hook both clone these repos into the webroot at build/start time — they are **not** subdirectories of this repo:

| Repo | Destination |
|------|-------------|
| `uvalib/uvalib-drupal-theme` | `web/themes/uvalib-drupal-theme` |
| `uvalib/uvalib_drupal_theme_2026` | `web/themes/custom/uvalib_drupal_theme_2026` |
| `uvalib/drupal_jsonapi_search_api_extension` | `web/modules/custom/drupal_jsonapi_search_api_extension` |
| `uvalib/drupal-uvaldap-module` | `web/modules/uvaldap` |

To update themes without rebuilding: `ddev drush exec /opt/drupal/scripts/pull-uvalib-drupal-theme` (inside the container).

## Deployment pipeline

**Branch strategy:** `main` → `release` (via PR). Merging `release` triggers CI.

**Build** (`pipeline/buildspec.yml`): runs on AWS CodeBuild, builds `package/Dockerfile`, pushes image to AWS ECR tagged with build timestamp and git SHA. The latest build tag is stored in AWS SSM Parameter Store at `/containers/$CONTAINER_IMAGE/latest`.

**Deploy** (`pipeline/deployspec.yml`): clones `uvalib/terraform-infrastructure` (local checkout: `/Users/ys2n/Code/uvalib/terraform-infrastructure`), decrypts keys with ccrypt, runs Terraform in `library.virginia.edu/staging/`, then runs Ansible playbooks (`deploy_netbadge.yml`, `deploy_backend.yml`) from `library.virginia.edu/staging/ansible/`. Edits in that repo are restricted to the `library.virginia.edu/` subdirectory.

Releases are tracked by ECR image tags (not git tags).

## Production infrastructure notes

- HTTPS is terminated at the upstream load balancer; the container serves HTTP on port 80.
- Apache (`package/data/etc/apache2/sites-enabled/000-default.conf`) proxies `/simplesaml/` → `http://sp:80/simplesaml/` (the `drupal-netbadge` SP container).
- `SetEnvIf X-Forwarded-Proto "https" HTTPS=on` is set in the Apache vhost so that SimpleSAMLphp's `session.cookie.secure` doesn't fail on the HTTP container.
- `SIMPLESAMLPHP_CONFIG_DIR=/var/simplesamlphp/config` is set as an Apache `SetEnv`.
- APCu is installed via `pecl install apcu` in the Dockerfile (no version pin).

## What to edit in this repo

- **Drupal dependencies:** `package/data/opt/drupal/composer.json` and `composer.lock`
- **Drupal configuration:** `package/data/opt/drupal/config/sync/` (export via `ddev drush cex`)
- **Apache vhost:** `package/data/etc/apache2/sites-enabled/000-default.conf`
- **Production image:** `package/Dockerfile`
- **Pipeline:** `pipeline/buildspec.yml`, `pipeline/deployspec.yml`
- **DDEV customization:** `.ddev/config.yaml`, `.ddev/web-build/Dockerfile`
- **Patches:** `local/ddev/patches/`

`local/ddev/web/` (the Drupal webroot) and `local/ddev/vendor/` are gitignored — do not edit files there directly; use Composer or Drush instead.
