# Proposal: a health-check endpoint that survives maintenance mode

**Status:** Proposal / discussion. Nothing adopted.
**Implementation home:** `terraform-infrastructure` (`*/alb-routing.tf` health check block) plus
a small route in this repo's Drupal image.
**Scope:** potentially **account-wide** — every Drupal target group in this AWS account shares
the same pattern, not just `library.virginia.edu`.
**Related:** [CKEditor 4 removal runbook](../operations/ckeditor4-prod-removal-runbook.md)
(where this bit), [Production deploy runbook](../operations/production-deploy-runbook.md),
[2026-06-26 cache-deadlock WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md).

## The problem

The ALB health check requests **`/`** and accepts only **`200-299`**:

```hcl
health_check {
  path                = "/"
  matcher             = "200-299"
  interval            = 120
  unhealthy_threshold = 3
}
```

`/` is served by Drupal, and **Drupal returns 503 in maintenance mode**. So the health check
conflates two entirely different questions:

- *Is this node capable of serving requests?* — what a health check is for.
- *Is the site currently in maintenance?* — a deliberate operational state.

The consequence is backwards: enabling maintenance mode makes the load balancer conclude the
nodes are broken and pull them from rotation. The pool empties and the ALB serves its own raw
error page — **replacing the controlled maintenance page you deliberately configured with an
uncontrolled one.**

With `interval = 120` and `unhealthy_threshold = 3`, a target can flip unhealthy **four minutes**
after maintenance mode goes on (failures at t=0, t=120, t=240). Any maintenance window longer
than that is racing a stopwatch it cannot see.

This is not hypothetical: it is why the CKEditor 4 removal cannot simply follow the generic
runbook's maintenance-mode variant, and it forces a temporary ALB matcher change into an
otherwise Drupal-only procedure.

## What 503 actually means here

Worth establishing precisely, because it determines what any fix may safely ignore. Exhaustively,
in non-test core code, only two things emit 503:

| Source | Trigger |
|---|---|
| `MaintenanceModeSubscriber` (+ the JSON:API variant) | **Maintenance mode** |
| `CKEditor5ImageController`, `FileUploadResource`, `jsonapi/FileUpload`, `TemporaryJsonapiFileFieldUploader` | **File already locked for writing** — transient, sent with `Retry-After: 1` |

The file-lock cases only fire on **upload endpoints** and can never affect a check against `/`.

Critically, the failures that genuinely warrant pulling a node **do not produce 503**:

| Failure | Status |
|---|---|
| Database / RDS unreachable | **500** |
| PHP fatal, OOM | **500** |
| Apache down, container dead | *no response* (refused / timeout) |
| Drupal bootstrap failure | **500** |

There is no 503 path tied to database connection failure anywhere in core. Note also that this
image runs **mod_php, not php-fpm** — with FastCGI, Apache emits 503 when the pool is down or
saturated, which *would* be a genuine "pull this node" signal. That failure mode does not exist
here. The only loaded proxy module serves the `/simplesaml/` `ProxyPass`, so a dead netbadge
container 503s on `/simplesaml/` while `/` stays healthy — correctly, since the rest of the site
still works.

**Conclusion:** on this stack, a 503 on `/` means "maintenance mode" and essentially nothing else.

## Approaches considered

### 1. Permanently widen the matcher to `200-299,503`

Simplest possible change — one line in each `alb-routing.tf`.

**Rejected.** It makes an *accidental* maintenance mode invisible: nodes stay in rotation, health
checks stay green, and nothing alerts. It also cements a workaround into the health contract
rather than fixing the conflation. Acceptable as a *temporary, attended* measure during a known
window (which is what the CKEditor runbook does), not as a standing configuration.

### 2. Static file served by Apache (e.g. `/alb-health.txt`)

Bypasses PHP entirely, so it survives maintenance mode trivially and is very cheap.

**Rejected as the primary check — it is too shallow.** It returns 200 whenever Apache is alive,
including when PHP is wedged, the database is unreachable, or Drupal cannot bootstrap. That is
strictly *worse* than today: broken nodes would stay in rotation indefinitely. A health check
must fail when the node genuinely cannot serve.

### 3. A Drupal route that opts out of maintenance mode

Drupal routes can set `_maintenance_access: TRUE`, which lets a route respond normally while the
rest of the site is in maintenance. A tiny controller can then answer the actual question —
*can this node serve?* — independent of site state.

**This is the recommended direction.** It is the only option that separates the two concerns
rather than trading one failure mode for another.

## The approach

A minimal route — `/health` (name TBD) — with:

- `options: _maintenance_access: TRUE` so maintenance mode does not suppress it.
- Anonymous access; no NetBadge, no role gating. It must be reachable by the ALB, which sends no
  credentials.
- **A trivial database round-trip** (e.g. `SELECT 1`), so a dead or unreachable RDS produces a
  non-200. This is what avoids the shallowness of option 2.
- `Cache-Control: no-store` — a cached health response defeats the purpose.
- A plain, tiny body. This endpoint is hit every 120s per node, forever.

The ALB health check path then moves from `/` to `/health` in each `alb-routing.tf`, and the
matcher **stays `200-299` permanently**. Maintenance windows need no ALB changes at all.

### How deep should the check go?

The central open question. Each additional dependency makes the check more truthful but more
prone to flapping — and a flapping health check pulls healthy nodes out of rotation, which is
its own outage.

Suggested floor: **Drupal bootstraps + one trivial DB query.** Deliberately *not* checked:
Solr/search, Redis, the SAML SP, or any external service. A node with search degraded can still
serve most of the site, and coupling the health check to a third-party dependency means that
dependency's outage becomes an outage here.

## Consequences

- **Maintenance mode becomes usable as designed.** Nodes stay in rotation, users see Drupal's
  maintenance page, and the four-minute stopwatch disappears.
- The CKEditor removal — and any future structural change — loses its ALB-modification step and
  the "do not deploy mid-window" hazard that comes with it.
- **The matcher stops being drift-prone.** Because `matcher` is Terraform-managed, today's
  temporary CLI widening is drift that a mid-window `terraform apply` would silently revert.
  Removing the need for that removes the hazard.
- One more anonymous public endpoint, hit continuously. Keep it cheap and unindexed.

## Open questions

- **Path name and collision risk.** `/health`, `/alb-health`, `/_health`? Must not collide with
  an existing route, alias, or redirect — the site has a large legacy URL surface.
- **Where does the code live?** A few dozen lines. A new tiny module, or folded into the existing
  `devops_docs` custom module (which already owns infrastructure-facing routes)? The latter is
  less scaffolding but muddies that module's purpose.
- **Contrib instead?** There are contrib modules in this space; worth evaluating one against
  ~30 lines of custom code before writing anything.
- **Rollout ordering.** Changing `HealthCheckPath` is itself an ALB change requiring a Terraform
  apply per environment. The endpoint must exist in the deployed image *before* the health check
  points at it, or every node instantly fails its check. Sequence: ship the route → verify it
  responds on every node → then move the path.
- **Scope.** Do this for `library.virginia.edu` only, or for every Drupal target group in the
  account (dh, dsf, mandala, …)? They all share the trap. Fixing one site leaves the same
  landmine for the others, but a coordinated change is a much larger piece of work.
