# Production Deploy Runbook

!!! info "What is a *runbook*?"
    A **runbook** is a step-by-step operational procedure for a routine-but-risky task —
    the exact commands to run, in order, with the checks and decision points in between.
    It's the difference between "we know how to deploy" (in someone's head) and "anyone on
    the team can deploy safely by following these steps." A runbook captures the *how*; an
    [incident note](../incidents/README.md) captures *what went wrong once* and what we
    changed because of it. This page is the how; it was rewritten after the
    [2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md).

## When this applies

Production (`library.virginia.edu`) runs **two nodes** behind an ALB
(`library-drupal-0`, `library-drupal-1`), both serving the `drupal-0` container. Unlike
staging — where a push to `main` auto-deploys — **production is deployed manually** by
running the Ansible backend playbook against each node, then rotating it through the load
balancer.

This runbook covers a **zero-downtime rolling deploy**: one node out of the ALB at a time,
so the site stays up throughout.

## The one rule that matters: do not rebuild cache while the other node serves traffic

!!! danger "Shared database cache"
    Both nodes share a **single database cache backend** (no Redis/Memcache — see the
    [Redis follow-up](../maintenance/redis-cache-backend.md)). A `drush cr` is a mass write
    to the shared `cache_*` tables. If you run it on one node **while the other node is
    serving live traffic**, the two collide on the same InnoDB rows and MySQL throws a
    **deadlock (SQLSTATE 40001 / errno 1213)**. The uncaught fatal renders a broken page,
    which then gets cached and served → **WSOD**. This caused the
    [2026-06-26 incident](../incidents/2026-06-26-prod-cache-deadlock-wsod.md).

    **"Out of the ALB" is *not* "isolated."** A drained node still shares the database
    cache with the live node. Draining protects HTTP traffic, not the cache.

Consequences for the procedure below:

- **Pure code/config swap (no cache rebuild needed):** roll the nodes, skip `drush cr`.
- **Change that needs a cache rebuild** (module major-version bump, core update, anything
  altering services/plugins/entity definitions): do **one** `drush cr` at the **end**,
  after both nodes are on the new image — ideally in a **low-traffic window**, or under
  **maintenance mode** (see [Structural changes](#structural-changes-maintenance-mode)).
  Do **not** `drush cr` per-node mid-rollout.

## Preconditions

- On the **UVA VPN** (hostnames won't resolve otherwise).
- `aws-vault` configured; the `staging` profile has the needed access (yes, prod uses the
  `staging` profile — that's how the favorite and the deploy are wired).
  **Have a fresh cached session before you start.** A long rollout outlives the cached MFA
  session; when it expires mid-run, every command fails with `AccessDenied: MultiFactorAuthentication
  failed`. Refresh it in a separate terminal window (`aws-vault exec staging -- aws sts
  get-caller-identity`); a prompt cannot be answered from a non-interactive shell
  (`--prompt=terminal` fails with `open /dev/tty: device not configured`).
- Know the **deploy tag** you're shipping. The current `latest` build tag:
  ```bash
  aws-vault exec staging -- aws ssm get-parameter \
    --name "/containers/uvalib/drupal-library/latest" \
    --query 'Parameter.Value' --output text
  ```
  Confirm the image's source commit before shipping:
  ```bash
  aws-vault exec staging -- aws ecr describe-images \
    --repository-name uvalib/drupal-library \
    --image-ids imageTag=<deploy_tag> \
    --query 'imageDetails[0].imageTags' --output text   # includes gitcommit-<sha>
  ```

## Tools

- **`alb-state`** — `/Users/ys2n/Code/scripts/uvalib/aws/alb-state`; favorite `drupal-prod`
  targets `alb-library-drupal-production`. Adds/removes nodes from the ALB target group,
  refuses to empty the pool, and `--watch` polls until health stabilizes.
- **Ansible** — `terraform-infrastructure/library.virginia.edu/production/ansible/`,
  `deploy_backend.yml`, run with `-e deploy_tag=<tag>` and `--limit <node>`.

## Rolling deploy — step by step

Do node 1 first, then node 0 (so node 0 keeps serving while node 1 deploys). Repeat the
whole block for the second node.

### 0. Baseline

```bash
cd /Users/ys2n/Code/scripts/uvalib/aws
./alb-state drupal-prod          # confirm BOTH nodes healthy before starting
```

Also capture, on **each** node, what is running now (this is your rollback), the theme revision,
and that nobody has left maintenance mode on:

```bash
ssh library-drupal-0.internal.lib.virginia.edu '
  sudo docker ps --format "{{.Names}} {{.Image}}" | grep -E "drupal-0|netbadge-0"
  sudo docker exec drupal-0 git -C /opt/drupal/web/themes/uvalib-drupal-theme describe --tags --always
  sudo docker exec drupal-0 /opt/drupal/vendor/bin/drush state:get system.maintenance_mode'
```

!!! warning "Check the theme before you deploy"
    `deploy_backend.yml` checks out the SSM pin `/themes/uvalib/drupal-library/release` on the
    node it deploys to, so a backend deploy **also moves that node's theme**. Compare the live
    theme tag on both nodes with the SSM value first. On 2026-10-22 node 0 was on an unpushed
    commit made on the staging host and node 1 was on the pinned tag; the deploy converged
    node 0 onto the pin, which was the intended state but a live theme change.

### 1. Drain the node from the ALB

```bash
./alb-state drupal-prod --remove uva-library-drupal-production-1 --watch --interval 30
```

`--watch` holds until the node fully drains (the **deregistration delay is 300s**), then
prints "All targets are in a stable state." Wait for that — draining lets in-flight
requests finish before the container is swapped.

!!! note "`alb-state` asks you to type `yes`"
    It prompts "CONFIRMATION REQUIRED … Type 'yes' to continue" and has no non-interactive
    flag. Run from a script or an agent with no terminal, it exits 1 having done nothing; pipe
    the answer (`printf 'yes\n' | ./alb-state …`) only for the action you intend.

### 2. Deploy the new image to that node only

```bash
cd /Users/ys2n/Code/uvalib/terraform-infrastructure/library.virginia.edu/production/ansible
aws-vault exec staging -- ansible-playbook deploy_backend.yml \
  -e deploy_tag=<deploy_tag> --limit uva-library-drupal-production-1
```

Expect `failed=0` in the PLAY RECAP. If the NetBadge image is changing too, deploy it to the same
(drained) node straight after, with its own pinned tag (`deploy_netbadge.yml -e
deploy_tag=<netbadge tag> --limit <node>`); the pipeline's `latest` is not used in production. The `"…saml-staging.pem missing"` line is a
pre-existing **non-fatal** warning from the backend playbook (the NetBadge SP is a separate
container) — ignore it.

### 3. Verify the node directly (out of rotation)

Hit the node **directly on `:8080`** — this checks *that specific node*, bypassing the ALB:

```bash
curl -s -o /dev/null -w 'HTTP %{http_code}  %{time_total}s\n' \
  http://library-drupal-1.internal.lib.virginia.edu:8080/      # 200; first hit is cold/slow
```

On the node, confirm the image and any change-specific expectations:

```bash
ssh library-drupal-1.internal.lib.virginia.edu '
  sudo docker ps --format "{{.Image}} {{.Names}}" | grep drupal-0       # = your deploy_tag
  sudo docker exec drupal-0 grep -i X-Forwarded-Proto \
    /etc/apache2/sites-enabled/000-default.conf                          # change-specific
'
```

!!! warning "Do NOT run `drush cr` here"
    See [the one rule](#the-one-rule-that-matters-do-not-rebuild-cache-while-the-other-node-serves-traffic).
    Verifying module versions with `drush pm:list` at this point is also unreliable — the
    shared cache reflects whichever node wrote last, so a half-rolled deploy shows the
    *old* version even though the new code is on disk. Trust the on-disk `.info.yml`, not
    `pm:list`, until the rollout is complete.

### 4. Re-add the node to the ALB

```bash
cd /Users/ys2n/Code/scripts/uvalib/aws
./alb-state drupal-prod --add uva-library-drupal-production-1 --watch --interval 30
```

`--watch` holds until the node is `healthy` (health check is **120s interval × 2** ≈ up to
4 min from cold). Do not proceed to the other node until this node is healthy — that's what
keeps the pool from going empty.

### 5. Repeat for node 0

Same five steps with `uva-library-drupal-production-0` /
`library-drupal-0.internal.lib.virginia.edu`.

### 6. Single cache rebuild at the end (only if the change needs it)

After **both** nodes are on the new image — and ideally in a low-traffic window:

```bash
ssh library-drupal-0.internal.lib.virginia.edu \
  'sudo docker exec drupal-0 /opt/drupal/vendor/bin/drush cr'
```

The cache is shared, so one `cr` covers both nodes. Now `drush pm:list` will report the
correct (new) versions. If the change has database updates, run `drush updb` here too.

### 7. Final verification

```bash
# Both nodes healthy in the ALB:
/Users/ys2n/Code/scripts/uvalib/aws/alb-state drupal-prod
# Public endpoint through the load balancer:
curl -s -o /dev/null -w 'HTTP %{http_code}\n' https://library.virginia.edu/
```

## Structural changes — maintenance mode

!!! danger "Maintenance mode empties the pool, and the matcher cannot be widened (2026-10-22)"
    The health check hits `/`, which returns 503 in maintenance mode, and the ALB will **not**
    accept 503 as a passing code (it only allows 200–499; see the
    [health-check proposal](../proposals/health-check-endpoint.md)). So whenever maintenance mode
    is on, both nodes fail the check and flip unhealthy after about **4 minutes** (three
    failures at a 120 s interval). Earlier versions of this runbook and the CKEditor runbook
    suggested widening the matcher to `200-299,503`; that cannot be done. Plan for the
    stopwatch, and never leave maintenance mode on unattended.

    What worked on 2026-10-22 for a core patch release with no database updates: roll the nodes
    **without** maintenance mode and **without** `drush cr` (steps 1–5), and do the single cache
    rebuild as a **short maintenance burst** that fits inside the four minutes. A bounded,
    trap-protected version (dry-run by default) is `scripts/library-prod-cache-rebuild.sh` in the
    local `drupal-config-scan` checkout (not published); its `--run` path is untested. Or skip
    the rebuild if the nodes are healthy and erroring nothing; staging and develop get a `drush cr`
    from their pipelines, production does not.

    If maintenance mode is already on when you start the rollout (someone set it for the
    window), the second node cannot be drained, because `alb-state` refuses to empty the pool.
    Deploy it in place; it is serving only the maintenance page anyway. Add the first node back
    to the ALB only **after** maintenance mode is off, since it cannot pass the health check
    while `/` returns 503.

For module **major-version** bumps, **core** updates, or anything changing
services/plugins/entity definitions, the safest path avoids serving live traffic against a
mid-rebuild cache entirely:

1. `drush state:set system.maintenance_mode 1` (and `drush cr`).
2. Deploy **both** nodes (steps 1–5, but you can drain both since the site is in
   maintenance).
3. **One** `drush cr` (+ `drush updb` if needed).
4. `drush state:set system.maintenance_mode 0`.

This trades a short, controlled pause for eliminating the deadlock/WSOD risk. Until the
[Redis cache backend](../maintenance/redis-cache-backend.md) lands, prefer this for any
deploy that must rebuild cache.

## Rollback

Re-deploy the previous tag the same way (rolling, per node). The prior production tag
before a deploy is whatever the nodes were running — capture it from
`sudo docker ps` **before** you start. Releases are tracked by
[ECR image tags, not git tags](deployment.md#release-tracking).

## Related

- [Session log: 2026-10-22 PHP2Shell / core 10.6.19 production rollout](../session-logs/2026-10-22-php2shell-production-rollout.md)
- [Incident: 2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md)
- [Redis cache backend (follow-up / real fix)](../maintenance/redis-cache-backend.md)
- [Deployment pipeline overview](deployment.md)
- Host/SSH details are in the repo `CLAUDE.md`.
