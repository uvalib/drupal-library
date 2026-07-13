# DLS-67 Timezone Fix — Deploy Runbook

!!! info "Scope"
    A **change-specific** runbook for shipping the DLS-67 timezone fix. It layers on top of the
    generic [Production Deploy Runbook](production-deploy-runbook.md) — that page owns the
    zero-downtime rolling mechanics; this page owns what's *specific* to this change (what to
    verify, the one data correction, the sequencing traps). Background:
    [incident writeup](../incidents/2026-07-10-scheduler-timezone-utc-clobber.md).

## What ships

- **`package/Dockerfile`** — `ENV TZ=UTC` → `ENV TZ=America/New_York`. This one line *is* the fix:
  it makes the in-process SimpleSAMLphp (`getenv('TZ')`) agree with Drupal's `America/New_York`
  instead of clobbering it to UTC on every authenticated request.
- **`/devops-docs` static rebuild** — the deferred `build-docs.sh` output for the docs already
  committed in `3d37cad`.

**Commit structure** — keep the code fix isolated and reviewable:

1. `fix: DLS-67 set container TZ to America/New_York` — `package/Dockerfile` only
2. `docs: rebuild devops-docs static site` — `scripts/build-docs.sh` output
   (`package/data/opt/drupal/web/modules/custom/devops_docs/static`)

## The crux that shapes verification

!!! danger "The bug only exists on authenticated *web* requests"
    SimpleSAMLphp is booted in-process only on authenticated requests, so:

    - **drush/CLI cannot reproduce or verify the fix.** drush never boots SAML, so a scheduled
      node created via drush stores correctly *regardless* of whether the fix is in — it proves
      nothing. The CLI-vs-web split is the whole nature of this bug.
    - **True behavioral verification needs a real NetBadge login.** Per
      [auth is user-driven], that's an editor's job, not an automated step.

    We already have strong indirect proof from the local reproduction (`TZ=America/New_York` →
    SAML sets Eastern → noon stores as `16:00 UTC` / `1784736000`). So the container-level
    `printenv TZ` check is high-confidence; the authenticated-node check is gold-standard
    confirmation.

## Decisions locked for this deploy

| Decision | Choice |
|----------|--------|
| Prod cache rebuild | **Skip `drush cr`** — pure env swap (not structural; anonymous pages were never affected). Roll the nodes, no `cr`. |
| Node 3172 correction | **UI re-save to noon** — uses the now-correct code path and validates the fix on real data. |
| Interactive verify + 3172 re-save | **An editor (akl3b)** — the DLS-67 reporter confirms the fix, closing the loop. Phases C-Tier2 and E gate on their availability. |

There is **no config/DB change** in this deploy — the fix is Dockerfile-only. No `cex`/`cim`,
no config-sync branch update, no `drush updb`.

## Phase A — Pre-flight

- On the **UVA VPN**.
- **Capture the current prod image tag from BOTH nodes** — this is the rollback anchor:
  ```bash
  for h in library-drupal-0 library-drupal-1; do
    ssh $h.internal.lib.virginia.edu 'sudo docker ps --format "{{.Image}} {{.Names}}" | grep drupal-0'
  done
  ```
- **Confirm node 3172 is untouched** (raw `unpublish_on` still the bad value; nothing re-saved it):
  ```bash
  ssh library-drupal-0.internal.lib.virginia.edu \
    'sudo docker exec -i drupal-0 /opt/drupal/vendor/bin/drush php:script -' <<'PHP'
  $u = (int) \Drupal\node\Entity\Node::load(3172)->get('unpublish_on')->value;
  print("nid=3172 unpublish_on=$u | ET=".date('Y-m-d H:i:s T', $u).PHP_EOL);
  PHP
  # expect 1784721600  (2026-07-22 08:00 EDT — the bad value; 1784736000 = already corrected)
  ```

!!! note "Why `php:script` and not `drush sql:query`"
    `drush sql:query` / `sql:cli` currently **fail** against the RDS server's TLS cert
    (`ERROR 2026: self-signed certificate in certificate chain`) — the MariaDB CLI verifies the
    chain and lacks the RDS CA, while PHP's PDO doesn't verify, so `php:script` (which rides
    Drupal's PDO) reads the DB fine. **That TLS issue is a separate concern with its own fix and
    deploy — it is deliberately *not* part of this runbook.** Use `php:script` for the reads here;
    do not try to fix the cert inline.

## Phase B — Ship to staging

1. Make the two commits above; run `./scripts/build-docs.sh` and commit the `static/` output.
2. **Push `main`** → triggers build (~2.5 min) + staging deploy (~5 min); ~7–9 min to live.
   *This push auto-deploys to staging — do it only with explicit go-ahead.*
3. Confirm the CodeBuild **deploy** went green — don't assume (≈2 of last 12 deploys failed):
   ```bash
   aws-vault exec staging -- aws codebuild list-builds-for-project \
     --project-name uva-drupal-library-project-deploy --sort-order DESCENDING \
     --query 'ids[0:3]' --output json
   ```

## Phase C — Verify staging

**Tier 1 — container-level (non-interactive, over VPN):**

```bash
ssh library-drupal-staging-0.internal.lib.virginia.edu '
  sudo docker ps --format "{{.Image}} {{.Names}}" | grep drupal-0   # = the new build tag
  sudo docker exec drupal-0 printenv TZ                             # = America/New_York
  sudo docker exec drupal-0 date                                    # = EDT
'
```

Staging's `deploy_backend.yml` runs **no `drush cr`**, so clear rendered-timestamp caches once
(single node — safe here):

```bash
ssh library-drupal-staging-0.internal.lib.virginia.edu \
  'sudo docker exec drupal-0 /opt/drupal/vendor/bin/drush cr'
```

**Tier 2 — behavioral (editor, interactive NetBadge login):** as an authenticated editor,
create a **date-only** scheduled node → confirm the stored `unpublish_on` = `16:00:00 UTC`
(not `12:00:00`), and the admin content list renders Eastern. Verify the raw value with the
`php:script` read from Phase A (swap in the new nid).

## Phase D — Ship to prod

1. Open + merge the **`main → release` PR** to build the production image.
2. Run the **rolling deploy** per the [Production Deploy Runbook](production-deploy-runbook.md)
   (drain node 1 → deploy that node → verify → re-add → repeat node 0). Change-specific check at
   step 3 of each node (direct on `:8080`, out of rotation):
   ```bash
   ssh library-drupal-1.internal.lib.virginia.edu '
     sudo docker ps --format "{{.Image}} {{.Names}}" | grep drupal-0   # = your deploy_tag
     sudo docker exec drupal-0 printenv TZ                             # = America/New_York
   '
   ```
3. **Skip `drush cr`** (locked decision). This is a pure env swap, not structural, and anonymous
   pages were never affected — so the generic runbook's "pure code swap → roll, skip `cr`" applies.
   Do **not** `cr` per-node mid-rollout (that's the [2026-06-26 WSOD] trap).

## Phase E — Correct node 3172 (only *after* the fix is live on prod)

!!! warning "Sequencing"
    Re-save node 3172 **only after** the TZ fix is live on the serving node. Under the old
    clobber the edit form re-corrupts the value. It fires **2026-07-22**, so there is runway —
    do not rush this ahead of the fix.

- **Editor (akl3b):** open node 3172, re-enter the unpublish date as **noon**, save. Under the
  fixed TZ it stores `16:00 UTC` = `1784736000`.
- **Verify** the stored raw value (same `php:script` read as Phase A):
  ```bash
  ssh library-drupal-0.internal.lib.virginia.edu \
    'sudo docker exec -i drupal-0 /opt/drupal/vendor/bin/drush php:script -' <<'PHP'
  $u = (int) \Drupal\node\Entity\Node::load(3172)->get('unpublish_on')->value;
  print("nid=3172 unpublish_on=$u | ET=".date('Y-m-d H:i:s T', $u).PHP_EOL);
  PHP
  # expect 1784736000  (= 2026-07-22 12:00 EDT)
  ```

## Phase F — Close out

- Post the **DLS-67 resolution comment** (root cause + one-line fix + the single corrected entry).
- File the upstream **`drupal/simplesamlphp_auth` bug report** — draft ready in
  [the proposal](../proposals/saml-timezone-clobber-guard.md); outward-facing, so filed by a human.
- Write the session log; update the DLS-67 project memory to closed.

## Rollback

One-line env change — very low risk. If needed, re-deploy the **pre-flight-captured previous prod
tag** the same rolling way (per the generic runbook).

!!! warning "Don't correct 3172 before you're confident the fix stays"
    If node 3172 is corrected to `1784736000` and the TZ fix is then rolled back, the returned
    clobber re-corrupts its *display/handling*. Only do Phase E once the fix is settled on prod.

## Related

- [Production Deploy Runbook](production-deploy-runbook.md) — the generic rolling mechanics
- [Incident: 2026-07-10 timezone UTC clobber](../incidents/2026-07-10-scheduler-timezone-utc-clobber.md)
- [Proposal: SAML timezone-clobber guard](../proposals/saml-timezone-clobber-guard.md) — the long-term upstream fix
