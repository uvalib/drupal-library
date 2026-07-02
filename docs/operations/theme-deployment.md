# Theme deployment (build-time clone + deploy-time tag pin)

First-party themes and modules are **cloned from GitHub at image-build time**
(see [ADR 003](../adr/003-runtime-cloned-themes-modules.md) and
[Container & build](../architecture/container-and-build.md)). That clone captures
whatever the repo's default branch (`main`) pointed at *when the image was built*,
so the image's theme can lag the latest theme work. The **active theme** is
therefore re-pinned to a specific git **tag at deploy time** to close that gap and
to guarantee staging and production run the exact same revision.

!!! note "Scope: only the active theme is deploy-time refreshed"
    The active theme is machine-name **`uvalibrary_v2a`**, which lives in the
    **`uvalib-drupal-theme`** repo (dir `/opt/drupal/web/themes/uvalib-drupal-theme`).
    The deploy-time tag checkout only touches that directory. The other cloned
    repos — `uvalib_drupal_theme_2026` (not yet active) and the custom modules
    (`drupal_jsonapi_search_api_extension`, `uvaldap`) — are **not** refreshed at
    deploy; they run at whatever `main` HEAD the image baked. If the 2026 theme
    becomes active, this mechanism would need to be extended to it.

## How the deploy-time pin works

The shared task **`library.virginia.edu/tasks/theme_deployment_task.yml`** does the
work. It is included from two playbooks per environment:

| Playbook | When it runs | Clears cache after? |
|----------|--------------|---------------------|
| `<env>/ansible/deploy_backend.yml` | Full backend deploy | No — the container is recreated fresh (cold cache), so no `drush cr` is needed |
| `<env>/ansible/deploy_theme_only.yml` | Theme-only deploy (no image change) | **Yes** — runs `drush cr` (the container is already warm) |

The task:

1. Reads the git tag from **SSM**: `aws ssm get-parameter --name "/themes/uvalib/drupal-library/release"`. The `release` segment comes from the calling playbook's `ssm_release_theme_tag` var.
2. Resolves the **effective tag** = `deploy_theme_tag` (if passed on the command line) else the SSM value.
3. Runs `docker exec drupal-0 bash -c "cd /opt/drupal/web/themes/uvalib-drupal-theme && git fetch && git checkout <effective_tag>"` — leaving the theme repo on that tag in detached HEAD.

Per environment:

- **Staging & production** run this (staging via the shared task include; production has an inline equivalent).
- **Develop** has no `deploy_theme_only.yml` and no theme task. Theme updates on dev are **manual** — `git pull`/`git checkout` in the container, typically via the `pull-uvalib-drupal-theme` helper (`/opt/drupal/scripts/pull-uvalib-drupal-theme`), which fetches `origin/main` for both theme dirs and runs `drush cr` if anything changed. So dev tracks `main`, not a pinned tag.

## Runbook: promote a theme change

Theme changes flow dev → release → staging → production, decoupled from the
container image.

### 1. Cut the tag & set the default (updates the SSM pointer)

In a checkout of `uvalib-drupal-theme`:

```bash
git pull
git fetch --tags                 # ensure you have all tags
git tag                          # list; copy the tag to release, e.g. prod-theme-20260519-netbadge-login

# point the default at that tag (this is the manual "write" side of the SSM pin)
aws ssm put-parameter --name "/themes/uvalib/drupal-library/release" \
  --value "<tag>" --overwrite
aws ssm get-parameter --name "/themes/uvalib/drupal-library/release"   # verify
```

### 2. Deploy the theme

```bash
# deploy the current default (SSM 'release') tag
ap deploy_theme_only.yml

# OR: test a specific tag WITHOUT changing the default
# (a later redeploy reverts to the SSM default — good for trialling a tag)
ap deploy_theme_only.yml -e deploy_theme_tag=<git theme tag>
```

`ap` is the `aws-vault exec … -- ansible-playbook` alias, run from the env's
`ansible/` directory.

!!! warning "Production is deployed one node at a time — never both at once"
    Production runs two nodes. Deploy the theme to **one node, verify it, then the
    other** — do not let a bad theme land on both simultaneously. `preview.library.virginia.edu`
    is pinned to node 0 for exactly this kind of single-node verification (see
    [Hostnames & URLs](../architecture/hostnames-and-urls.md)); the legacy runbook
    verified via the direct node URLs (`library-drupal-0…:8080`, then `-1`).

## Container/code changes are a separate track

Application/container changes are promoted by **ECR image tag**, not this theme
mechanism: the `release` branch auto-builds an image (`release-YYYYMMDDHHMMSS`)
that auto-deploys to staging; after testing, the image is promoted to production
by tagging it `prod-yymmdd`. See [Deployment](deployment.md). So a full production
release is really *two* coordinated pins: the **image tag** (code) and the **SSM
theme tag** (theme).
