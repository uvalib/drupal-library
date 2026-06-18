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
    `drupal-library-config-sync` repo.

## Per-environment branches — use `production`

The config-sync repo has **one branch per environment**: `development`, `staging`,
`production` (plus `main`). An automated job exports config and commits it
("Automatic Commit …").

!!! danger "`main` is stale — clone `--branch production`"
    `main` is the *default* branch but is **not** what's deployed and can be badly out of
    date (e.g. it showed CKEditor 4 long after production had moved to CKEditor 5). A
    plain `gh repo clone …` checks out `main` and will mislead you. Always target the
    environment branch you mean:

    ```bash
    gh repo clone uvalib/drupal-library-config-sync -- --branch production
    ```

    For the authoritative current state, prefer the `production` branch or a live DB
    import over `main`.

!!! note "Mechanism under review"
    The automated export/commit is **not yet self-sufficient** — keeping `production`
    current has required manual fixes to production config. The export mechanism is
    slated for a complete review. Until then, treat the automation as unproven and verify
    against `production` or a live DB. See the
    [Config-sync mechanism review brief](../maintenance/config-sync-mechanism-review.md)
    for how it currently works, its provenance, and the open redesign decisions.

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
