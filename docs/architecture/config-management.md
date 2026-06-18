# Configuration Management

Drupal's exported configuration (`config/sync`) is **version-controlled in a separate
repository**, not in this one:

- **Repo:** `git@github.com:uvalib/drupal-library-config-sync.git`
- **In the container:** checked out at `/opt/drupal/config/sync`
- **In this repo:** `package/data/opt/drupal/config/sync/` contains only a `read.me`
  placeholder.

!!! warning "Don't assume config is untracked"
    Because `config/sync/` in this repo is just a placeholder, it can look as though the
    site's configuration isn't under version control. It is — just in the
    `drupal-library-config-sync` repo. To inspect text formats, blocks, enabled modules,
    etc. without a running site, clone that repo and read the YAML directly:

    ```bash
    gh repo clone uvalib/drupal-library-config-sync
    ```

## Working with config

```bash
ddev drush cex      # export config from the site to config/sync/
ddev drush cim      # import config from config/sync/ into the site
ddev drush config:status   # show drift between the site and the committed config
```

After changing configuration on the site, run `drush cex` and commit the result to the
**config-sync repo**, not this one.

!!! note "Rationale"
    Keeping exported config in its own repo decouples content/config changes (which
    happen frequently and may be exported from a running environment) from code and
    dependency changes in this repo. Because the config is committed, `drush config:status`
    has a real baseline to detect drift against. See
    [ADR 001](../adr/001-config-in-separate-repo.md).
