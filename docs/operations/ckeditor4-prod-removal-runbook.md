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

!!! danger "CONFIRMED: plain maintenance mode will take the production pool down"
    Drupal's maintenance mode serves **HTTP 503** to anonymous requests. Verified
    2026-08-06 — the production target group health-checks a Drupal-served path and accepts
    only 2xx, so maintenance mode fails the health check:

    | Target group | Path | Matcher | Interval | Unhealthy threshold |
    |---|---|---|---|---|
    | `alb-library-drupal-production` | `/` | **`200-299`** | 120s | 3 |
    | `alb-library-drupal-production-1` | `/` | **`200-299`** | 120s | 3 |

    Corroborated by the Apache access log, which shows the checker hitting `/`:
    ```
    10.130.109.39 - - [06/Aug/2026:12:43:37 -0400] "GET / HTTP/1.1" 200 14987 "-" "ELB-HealthChecker/2.0"
    ```

    **The budget is as little as 4 minutes.** Three consecutive failures at a 120s interval
    marks a target unhealthy — failures at t=0, t=120, t=240 means unhealthy at **t=240s**.
    Phase 1 performs two cache rebuilds; exceeding four minutes is entirely plausible, and
    when both nodes flip the pool empties and the ALB serves its own error page instead of
    Drupal's maintenance page.

    Re-verify before the window (things change):
    ```bash
    aws-vault exec staging --prompt=osascript -- aws elbv2 describe-target-groups \
      --query 'TargetGroups[?contains(TargetGroupName,`library-drupal`)].
               {Name:TargetGroupName,Path:HealthCheckPath,Matcher:Matcher.HttpCode,
                Interval:HealthCheckIntervalSeconds,Unhealthy:UnhealthyThresholdCount}' \
      --output table
    ```

!!! tip "Widen the matcher for the window — the recommended path"
    Temporarily accept 503, run the window, then restore. This makes maintenance mode behave
    as the generic runbook assumes and removes the four-minute stopwatch entirely. The change
    causes no target churn — it only alters what the next health check (≤120s later) counts
    as passing.

    ```bash
    TG_ARN=arn:aws:elasticloadbalancing:us-east-1:115119339709:targetgroup/alb-library-drupal-production/e09a170c0b60c0f6

    # BEFORE the window
    aws-vault exec staging --prompt=osascript -- \
      aws elbv2 modify-target-group --target-group-arn "$TG_ARN" --matcher HttpCode=200-299,503

    # AFTER the window — restore
    aws-vault exec staging --prompt=osascript -- \
      aws elbv2 modify-target-group --target-group-arn "$TG_ARN" --matcher HttpCode=200-299
    ```

    **Only this one target group needs changing** *in the current configuration*.
    `alb-library-drupal-production-1` is the **preview** target group
    (`alb-routing.tf:172`). It reports `LoadBalancerArns: null` and target state `unused`
    only because `preview_passthrough_enabled` defaults to `false`, which makes the
    `preview.library.virginia.edu` listener rule a *redirect* rather than a *forward*. It is
    dormant by design, **not orphaned — do not delete it.** If preview passthrough is ever
    enabled, that target group carries the same `200-299` matcher and needs the same
    treatment.

    !!! danger "Restoring is mandatory, not hygiene"
        While `503` is accepted, an **accidental** maintenance mode becomes invisible — nodes
        stay in rotation, health checks stay green, and nothing alerts. The exposure lasts
        exactly as long as the matcher stays widened.

### Why accepting 503 does not mask real failures

The obvious worry about widening the matcher is that it blinds the health check to genuine
faults. On this stack it does not, and it is worth knowing why before running the window.

Exhaustively, in non-test core code, only two things emit 503:

| Source | Trigger |
|---|---|
| `MaintenanceModeSubscriber` (+ JSON:API variant) | **Maintenance mode** — the deliberate case |
| `CKEditor5ImageController`, `FileUploadResource`, `jsonapi/FileUpload`, `TemporaryJsonapiFileFieldUploader` | **File already locked for writing**, sent with `Retry-After: 1` |

The file-lock cases fire only on **upload endpoints**, never on `/`, so they cannot influence
the health check at all.

The failures that genuinely warrant pulling a node produce something else entirely, and
therefore **still fail a `200-299,503` matcher**:

| Failure | Status | Node still pulled? |
|---|---|---|
| Database / RDS unreachable | **500** | ✅ |
| PHP fatal, OOM | **500** | ✅ |
| Apache down, container dead | *no response* (refused / timeout) | ✅ |
| Drupal bootstrap failure | **500** | ✅ |

There is no 503 path tied to database connection failure anywhere in core — DB failures surface
as 500. Note too that this image runs **mod_php, not php-fpm**: with FastCGI, Apache emits 503
when the pool is down or saturated, which *would* be a real signal worth keeping. That failure
mode does not exist here. The only loaded proxy module serves the `/simplesaml/` `ProxyPass`, so
a dead netbadge container 503s on `/simplesaml/` while `/` stays healthy — correctly, since the
rest of the site still works.

**So during the window, the only thing the widened matcher hides is maintenance mode itself** —
which is precisely what you are trying to hide from it.

!!! tip "The real fix is to stop health-checking a Drupal page"
    All of this stems from the health check asking `/` — which conflates *"can this node
    serve?"* with *"is the site in maintenance?"*. A dedicated endpoint that opts out of
    maintenance mode would let the matcher stay `200-299` permanently and remove the ALB step
    from this procedure entirely. See
    [Proposal: a health-check endpoint that survives maintenance mode](../proposals/health-check-endpoint.md).

### Blast radius of the matcher change

The matcher is a property of the **target group**, not the ALB or its listeners — which
matters here because the load balancer is heavily shared:

| | |
|---|---|
| Load balancer | `uva-alb-public-production` (shared) |
| Target groups on it | **83** |
| Affected by this change | **1** |
| Instances affected | `i-0a0c51aa81f06a326`, `i-0fed24178c31ea2f8` |

The other 82 target groups — dh, dsf, mandala, and the rest — are untouched; each has its own
independent health check configuration.

Hostnames routed to this target group, i.e. the full public surface a maintenance window
takes offline:

```
library.virginia.edu
lib.virginia.edu
library-drupal.internal.lib.virginia.edu
```

The other production aliases (preview, libra, pressbooks) route to different target groups and
are unaffected.

!!! note "This trap is account-wide"
    Every Drupal target group in this account — dh, dsf, mandala, develop, staging — also
    health-checks `/` with a `200-299` matcher. Any maintenance-mode window on any of those
    sites carries the identical hazard.

### The matcher is Terraform-managed — the CLI change is drift

`matcher = "200-299"` is declared in `terraform-infrastructure`:

| File | Target group |
|---|---|
| `library.virginia.edu/production/alb-routing.tf:36` | `alb-library-drupal-production` (live) |
| `library.virginia.edu/production/alb-routing.tf:172` | `alb-library-drupal-production-1` (preview, dormant) |
| `library.virginia.edu/staging/alb-routing.tf:32` | staging |
| `library.virginia.edu/develop/alb-routing.tf:32` | develop |

So `modify-target-group` creates **drift that Terraform will heal on the next apply**. That
cuts both ways:

- **Useful:** it is self-restoring. If the explicit restore is somehow missed, the next apply
  puts `200-299` back rather than leaving 503 accepted indefinitely.
- **Dangerous:** an apply landing *mid-window* silently re-narrows the matcher while Drupal is
  still serving 503 — the pool then empties exactly as if the change had never been made.

!!! danger "Do not deploy or run Terraform between widening and restoring"
    The deploy pipeline runs Terraform against the environment directory before Ansible, so a
    deploy during the window will revert the matcher. Phase 1 is drush-only and triggers no
    Terraform, which is what makes the window safe — but do not let a Phase 2 deploy, or
    anyone else's apply, overlap it.

**Should this be done in Terraform instead?** For a short, attended window the CLI change is
the pragmatic choice — it needs no apply, and Terraform heals it afterwards. Doing it properly
in code would mean parameterising the matcher (mirroring how `preview_passthrough_enabled`
already gates preview behaviour) and running two applies, each of which touches the ALB itself.
That is more moving parts than the problem warrants unless maintenance windows become routine.

    If modifying the ALB is unacceptable, use the
    [no-maintenance-mode variant](#alternative-phase-1-without-maintenance-mode) below
    instead. Do **not** attempt to race the four-minute budget.

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

### Alternative: Phase 1 without maintenance mode

Use this only if modifying the target-group matcher is unacceptable. It trades the ALB change
for a genuine (if small) deadlock risk, so prefer the matcher widening above.

The hazard being managed is that both nodes share a **database** cache backend, so a `drush cr`
on one node collides with live traffic served by the other — the
[2026-06-26 WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md). Draining a node from
the ALB protects HTTP traffic but **not** the shared cache.

1. Pick a genuine low-traffic window (early morning). Fewer live requests means fewer rows
   contended.
2. Drain node 1 from the ALB and wait for the 300s deregistration delay to complete.
3. Run `drush pm:uninstall ckeditor` **once**, from the drained node. The config write and
   schema drop are small; the expensive part is the rebuild that follows.
4. Accept a single `drush cr` here rather than two. The uninstall already invalidates the
   container, so the pre-emptive `cr` from the maintenance-mode sequence is what you drop.
5. Verify on the drained node via `:8080`, then re-add it and confirm healthy.
6. **No second uninstall on node 0** — the change is in the shared database and applies to
   both nodes at once. Node 0 needs nothing beyond picking up the rebuilt cache.

!!! warning "This is the riskier path"
    There is a real window where node 0 serves live traffic against a cache being rewritten
    by node 1. It is smaller than a full `cr` under load, but it is not zero, and it is
    precisely the failure mode that produced the 2026-06-26 incident. The matcher widening
    avoids this class of risk rather than minimising it.

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
