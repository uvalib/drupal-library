# CKEditor 4 Removal — Production Runbook

!!! info "Scope"
    A **change-specific** runbook for removing the abandoned CKEditor 4 module from
    production. It layers on top of the generic
    [Production Deploy Runbook](production-deploy-runbook.md) — that page owns the
    zero-downtime rolling mechanics; this page owns the **ordering constraint** that makes
    this change different from a normal deploy. Background:
    [CKEditor 4 → 5 notes](../maintenance/ckeditor4-to-ckeditor5.md) and the
    [ckeditor ghost WSOD](../troubleshooting/ckeditor-ghost-wsod.md) that hit staging.

## The crux: DB before code, and it's a one-way door

!!! danger "The uninstall must happen while the module files are still on disk"
    `main` (commit `5d9513b`) **deletes** the CKEditor 4 module files. Production's DB still
    has `ckeditor` **enabled**. These must be reconciled in one order only:

    - **DB first (correct):** uninstall while files are present → plain `drush pm:uninstall`
      works, cleanly and reversibly.
    - **Code first (broken):** deploy `main`, then try to uninstall → Drupal throws
      `UnknownExtensionException: "The module ckeditor does not exist."`, `/admin/modules`
      WSODs, and `pm:uninstall` *itself* throws, so the normal fix is unavailable. Recovery
      then requires surgical [ghost-module removal](../troubleshooting/ckeditor-ghost-wsod.md).

    **This is exactly how staging broke on 2026-07-14.** Production has not hit it only
    because it still runs the decoupled TZ-only image.

Production currently runs `build-20260714134450` (= `gitcommit-4079ce3`), which **has the
files**. That is the window this runbook depends on. It closes the moment a `main`-based
image is deployed.

## Verified prod state

Confirmed read-only on 2026-08-06, both nodes:

| Check | Value | Meaning |
|---|---|---|
| Running image (nodes 0 and 1) | `build-20260714134450` | ckeditor files **present** ✓ |
| `ckeditor` in `core.extension` | enabled | the thing to remove |
| `ckeditor5` | enabled | the replacement, already in use ✓ |
| `basic_html` / `rds_text_editor` / `webform_default` | all `editor: ckeditor5` | **no format migration needed** ✓ |
| `full_html` / `restricted_html` | no editor config | nothing to do ✓ |
| Runtime dependents on `ckeditor` | none | uninstall won't cascade ✓ |
| `ckeditor_plugin_report` | **not** enabled | composer-only; no DB action ✓ |
| `maintenance_mode` | `0` | |

!!! note "`quickedit` is a false alarm"
    `quickedit` is enabled and its `.info.yml` mentions `ckeditor` — but under
    **`test_dependencies:`**, not `dependencies:`. Drupal's uninstall validation does not
    consult test dependencies. It does not block the uninstall. (Worth re-checking with a
    `dependencies:`-scoped grep if you audit this again; a naive `grep ckeditor` matches it
    and looks alarming.)

## What ships in Phase 2

The gap between prod's `4079ce3` and `main` is 41 commits, but almost all of it is docs. The
**runtime** delta is only:

| Change | Runtime effect |
|---|---|
| `drupal/ckeditor` dropped from `composer.json` | module files gone — the change this runbook is about |
| `devops_docs` symlinked into the image (`e921fb7`) | new custom module, ships **disabled**; enabling is a separate manual step per env |
| Dockerfile docs-builder rework (`691f9a1`, `224a70c`) | build-time only |

`1591f6e` (the TZ fix) is already live via `4079ce3` — same change, different SHA.
**No other composer changes, so no `drush updb` is expected.**

---

## Phase 0 — Pre-flight

- On the **UVA VPN**.
- **Capture the rollback anchor** — the current image tag on both nodes:
  ```bash
  for h in library-drupal-0 library-drupal-1; do
    ssh $h.internal.lib.virginia.edu 'sudo docker ps --format "{{.Image}} {{.Names}}" | grep drupal-0'
  done
  # expect build-20260714134450 on both
  ```
- **Re-confirm the window is still open** (files present + module enabled):
  ```bash
  ssh library-drupal-0.internal.lib.virginia.edu '
    sudo docker exec drupal-0 sh -c "ls -d /opt/drupal/web/modules/contrib/ckeditor" &&
    sudo docker exec drupal-0 /opt/drupal/vendor/bin/drush config:get core.extension module | grep -w ckeditor
  '
  ```
- **Take a DB backup** before a config-changing prod operation — see
  [Syncing data](syncing-data.md). Note `drush sql:dump` needs the
  [RDS TLS workaround](../troubleshooting/mariadb-ssl-dump.md).
- **Both nodes healthy** in the ALB:
  ```bash
  /Users/ys2n/Code/scripts/uvalib/aws/alb-state drupal-prod
  ```

!!! danger "Confirm the ALB health check matcher before enabling maintenance mode"
    Drupal's maintenance mode serves **HTTP 503** to anonymous requests. If the ALB target
    group's health check hits a path that goes through Drupal and matches only 200, both
    targets will flip **unhealthy** during the window — the pool empties and the ALB serves
    its own error instead of Drupal's maintenance page.

    **Partially confirmed 2026-08-06, and not in the reassuring direction.** The Apache
    access log shows the health checker requesting **`/`** — a Drupal-served path, which is
    precisely the risky case:
    ```
    10.130.109.39 - - [06/Aug/2026:12:43:37 -0400] "GET / HTTP/1.1" 200 14987 "-" "ELB-HealthChecker/2.0"
    ```
    So the checked path *will* return 503 under maintenance mode. The **only** remaining
    unknown is whether the target group's success matcher tolerates 503. Treat this as a
    hard gate: verify the matcher before the window, not during it.

    Check the health check's path and success matcher first:
    ```bash
    aws-vault exec staging -- aws elbv2 describe-target-groups \
      --query 'TargetGroups[?contains(TargetGroupName,`drupal`)].
               {Name:TargetGroupName,Path:HealthCheckPath,Port:HealthCheckPort,Matcher:Matcher.HttpCode}' \
      --output table
    ```
    If the matcher is 200-only on a Drupal-served path, either widen it to accept `200,503`
    for the window, or **skip maintenance mode** and use the drain-one-node-at-a-time
    approach instead (accepting that the cache is still shared — see the
    [one rule](production-deploy-runbook.md#the-one-rule-that-matters-do-not-rebuild-cache-while-the-other-node-serves-traffic)).
    This has not yet been verified on this target group.

---

## Phase 1 — Uninstall `ckeditor` from the DB

Runs against the **current** image. Nothing is deployed in this phase.

!!! danger "Why maintenance mode"
    A module uninstall is a **structural change** — it rewrites `core.extension`, drops the
    module's schema, and invalidates plugin/service definitions, so it needs a cache rebuild.
    Both nodes share a single **database** cache backend, so a `drush cr` while the other node
    serves traffic risks the InnoDB deadlock that caused the
    [2026-06-26 WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md). Maintenance mode
    removes live traffic from the equation.

`system.maintenance_mode` is DB-backed **state**, shared by both nodes — set it once, from
either node, and it applies to both.

```bash
NODE=library-drupal-0.internal.lib.virginia.edu
DRUSH='sudo docker exec drupal-0 /opt/drupal/vendor/bin/drush'

# 1. Maintenance mode ON
ssh $NODE "$DRUSH state:set system.maintenance_mode 1"
ssh $NODE "$DRUSH cr"

# 2. The uninstall (files are present, so this is the normal, supported path)
ssh $NODE "$DRUSH pm:uninstall ckeditor"

# 3. Rebuild once, while no traffic is being served
ssh $NODE "$DRUSH cr"
```

### Verify before leaving maintenance mode

```bash
# core.extension no longer lists ckeditor (ckeditor5 / ckeditor_accordion MUST remain):
ssh $NODE "$DRUSH config:get core.extension module" | grep -i ckeditor
# expect: ckeditor5 and ckeditor_accordion only — no bare 'ckeditor'

# Text formats untouched:
for f in basic_html rds_text_editor webform_default; do
  ssh $NODE "$DRUSH config:get editor.editor.$f editor"
done
# expect ckeditor5 for all three

# Extension list enumerates without throwing (this is what WSODs when a ghost is present):
ssh $NODE "$DRUSH pm:list --status=enabled --format=list | wc -l"
```

```bash
# 4. Maintenance mode OFF
ssh $NODE "$DRUSH state:set system.maintenance_mode 0"
ssh $NODE "$DRUSH cr"
```

### Post-phase verification

```bash
curl -s -o /dev/null -w 'HTTP %{http_code}\n' https://library.virginia.edu/
/Users/ys2n/Code/scripts/uvalib/aws/alb-state drupal-prod
```

**Interactive check (an editor, via NetBadge):** open `/admin/modules` — it should render
rather than WSOD — and edit any node using a rich-text format to confirm the CKEditor 5
toolbar still loads. Authentication is user-driven — a real NetBadge login is not an
automated step.

!!! success "Rollback for Phase 1 is easy — and only easy *now*"
    The files are still on disk, so the uninstall is reversible with
    `drush pm:enable ckeditor`. **This escape hatch disappears once Phase 2 deploys.**
    That asymmetry is the reason to let Phase 1 bake before deploying.

### Config-sync consequence

The host cron runs `drush cex --commit` **every 2 hours**, so this `core.extension` change
will be auto-exported and pushed to the `production` branch of `drupal-library-config-sync`
without intervention. Confirm it landed rather than assuming — that automation is
[not reliable](../maintenance/config-sync-mechanism-review.md) and is itself an open review
item. If it did not fire, commit the export manually; otherwise a later `drush cim` could
re-enable the module from stale config.

---

## Phase 2 — Deploy the `main`-based image

**Precondition: Phase 1 is complete and verified.** Deploying this image against a DB that
still has `ckeditor` enabled reproduces the staging WSOD.

1. **Push `main`** if it is still ahead of origin. Note that a push
   [auto-deploys to staging](deployment.md) and triggers the build.
2. **Identify and verify the deploy tag:**
   ```bash
   aws-vault exec staging -- aws ssm get-parameter \
     --name "/containers/uvalib/drupal-library/latest" \
     --query 'Parameter.Value' --output text

   aws-vault exec staging -- aws ecr describe-images \
     --repository-name uvalib/drupal-library \
     --image-ids imageTag=<deploy_tag> \
     --query 'imageDetails[0].imageTags' --output text   # confirm gitcommit-<sha> is main HEAD
   ```
3. **Roll the nodes** per the
   [generic runbook](production-deploy-runbook.md#rolling-deploy-step-by-step) — node 1
   first, then node 0: drain → `ansible-playbook deploy_backend.yml -e deploy_tag=<tag>
   --limit <node>` → verify on `:8080` → re-add.
4. **Cache rebuild:** not required mid-roll. The DB is already consistent with the new code
   after Phase 1, and `devops_docs` ships disabled. A single optional `drush cr` at the end,
   in a low-traffic window, is fine — never per-node.

### Verify

```bash
for h in library-drupal-0 library-drupal-1; do
  ssh $h.internal.lib.virginia.edu 'sudo docker ps --format "{{.Image}}" | grep drupal-0'
done                                             # both = new tag
ssh library-drupal-0.internal.lib.virginia.edu \
  'sudo docker exec drupal-0 sh -c "ls -d /opt/drupal/web/modules/contrib/ckeditor 2>/dev/null || echo ABSENT"'
                                                 # expect ABSENT — files gone, DB already clean
curl -s -o /dev/null -w 'HTTP %{http_code}\n' https://library.virginia.edu/
```

Plus the interactive `/admin/modules` check — that page is the canary for this whole class
of problem.

### Rollback

Redeploy `build-20260714134450` the same rolling way.

!!! warning "Roll back code, never the DB"
    Reverting to the old **image** after Phase 1 is safe: the files come back and `ckeditor`
    is simply an available-but-uninstalled module. What is **not** safe is restoring a
    **DB snapshot from before Phase 1** while a `main`-based image is deployed — that
    recreates the ghost exactly.

---

## After this lands

- **Retire the interim mitigation** — `local/ddev/backups/ckeditor-ghost-cleanup.sh` (and its
  auto-invocation at the end of `update-db-from-remote.sh`) exists only to paper over this
  mismatch. Once prod's DB is clean it is dead code.
- **Remove `drupal/ckeditor_plugin_report`** from `composer.json` — it is installed but never
  enabled, and the original removal commit (`5d9513b`) only dropped `drupal/ckeditor`. No DB
  action needed; it is a composer-only cleanup.
- **prod→staging DB syncs become safe again.** They currently reintroduce the ghost on every
  sync.
- **Unblocks the [Drupal 11 upgrade](../maintenance/drupal-11-upgrade.md)** — CKEditor 4 has
  no Drupal 11 support and was a hard blocker.
- **`main` becomes deployable to production** by the normal path, ending the decoupled
  single-purpose-image workaround that DLS-67 needed.
- Consider enabling `devops_docs` on prod (a separate, manual, per-environment step — it is
  not in the config-sync `core.extension`).
