# Module & Core Updates

Dependencies are declared in `package/data/opt/drupal/composer.json` /
`composer.lock`. Update through Composer in DDEV, never by editing files in `web/`.

## Routine update

```bash
ddev composer update drupal/<module> --with-dependencies
ddev drush updb        # apply any DB updates
ddev drush cex         # export resulting config changes (commit to config-sync repo)
```

## Core update

```bash
ddev composer update drupal/core --with-all-dependencies
ddev drush updb
```

Pin `drupal/core-recommended`, `drupal/core-composer-scaffold`, and
`drupal/core-project-message` to the same explicit version when bumping core.

## Major-version module bumps

A major version bump (e.g. `^2.0` → `^3.0`) can change data structures. Before bumping:

- Read the release notes / upgrade path for the new branch.
- Check what config or content actually depends on the module (the
  [config-sync repo](../architecture/config-management.md) is the fastest place to look
  without a running site).
- After updating, run `drush updb` and **verify the affected feature still works**.
- Avoid releases flagged *experimental* / *alpha* / *beta* for production unless there
  is no stable alternative.

### Example: block_class 2.x → 3.x (June 2026)

`drupal/block_class` was bumped `^2.0` → `^3.0`. Verified beforehand that 3.x keeps the
same storage key (`third_party_settings.block_class.classes`), so the ~14 blocks that
carry CSS classes were not at risk. The experimental 4.x branch was avoided. Procedure:

```bash
ddev composer update drupal/block_class
ddev drush updb
# then spot-check a few class-carrying blocks render their classes
```
