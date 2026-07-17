# Session Log: DLS-67 — timezone fix deployed to production

**Date:** 2026-07-17
**Participants:** Yuji Shinozaki, Claude (Opus 4.8)
**Branch:** `release` (prod artifact); `main` unchanged this session
**Outcome:** Deployed the DLS-67 timezone fix to **both production nodes** via a zero-downtime
rolling deploy of image tag **`build-20260714134450`** (= `gitcommit-4079ce3`). Both nodes now
run `TZ=America/New_York`; `https://library.virginia.edu/` served HTTP 200 throughout. `drush cr`
skipped per the locked decision (pure env swap). Behavioral verification done by the DLS-67 reporter
(Amber / akl3b) via NetBadge; the single affected row (node 3172) was re-saved on prod and verified
corrected. **DLS-67 functionally resolved** — only close-out (resolution comment, upstream bug) left.

Runbook: [operations/dls-67-timezone-fix-runbook.md](../operations/dls-67-timezone-fix-runbook.md).
Generic mechanics: [operations/production-deploy-runbook.md](../operations/production-deploy-runbook.md).

---

## 1. What shipped, and why it wasn't `main`

Prod runs its own deploy path (repo `uvalib/drupal-library-production-deploy`, explicit
`-e deploy_tag=`) and was 32 commits behind `main`. Shipping `main` would have tripped the
**CKEditor 4 landmine**: prod's DB still has `ckeditor` enabled, but `main` (commit `5d9513b`)
deletes the module files → broken bootstrap. So the fix was decoupled onto a **TZ-only image** —
`release` branch commit `4079ce3` = prod's live commit `2a1ddef` + the single
`ENV TZ=America/New_York` line (rest of the commit is an explanatory comment). Diff vs. prod's
running code is functionally one line.

- **Deploy tag:** `build-20260714134450` — confirmed in ECR to map to `gitcommit-4079ce3`.
- **Rollback anchor:** `build-20260625193203` (what both nodes ran before), captured pre-flight.

## 2. Pre-flight (Phase A)

- Both prod nodes confirmed on `build-20260625193203`.
- Node **3172** raw `unpublish_on` confirmed still the bad `1784721600` (via `php:script`, not
  `drush sql:query` — the latter fails on the RDS TLS cert).

## 3. Rolling deploy (Phase D)

Node 1 first, then node 0, per the generic runbook. For each node: drain from ALB → deploy →
verify out-of-rotation → re-add.

| Step | node 1 | node 0 |
|------|--------|--------|
| Ansible `deploy_backend.yml -e deploy_tag=build-20260714134450 --limit <node>` | `failed=0`, `changed=5` | `failed=0`, `changed=5` |
| Image on disk | `build-20260714134450` ✓ | `build-20260714134450` ✓ |
| `printenv TZ` | `America/New_York` ✓ | `America/New_York` ✓ |
| Direct `:8080` | HTTP 200 ✓ | HTTP 200 ✓ |

`drush cr` **skipped** (locked decision — pure env swap, anonymous pages never affected, and the
shared-DB-cache deadlock risk from a mid-rollout `cr` is the 2026-06-26 WSOD trap). Final check:
both nodes healthy in the ALB, public endpoint HTTP 200.

## 4. Tooling gotcha — `alb-state` interactive prompt vs. the `!` bridge

`alb-state` confirms with an interactive `read -r CONFIRM` and has **no `--yes`/`--force` flag**.
Driving it through Claude Code's `!` bridge by sending the command and then a *separate* `yes`
line **floods it** — the `!` bridge can't hold an interactive prompt, so the `yes` came back as a
runaway `y` stream (killed at 5GB output). **Production was untouched** — the flood hit the tool's
prompt, never the AWS API; ALB membership was verified unchanged and the site stayed 200.

**Fix:** run `alb-state` in a real terminal, **or** feed the confirmation in one command:
`printf 'yes\n' | ./alb-state … --add/--remove … --watch` (the `--watch` poll loop doesn't read
stdin, so one piped line is enough). Yuji ran the remaining mutating steps from a separate
terminal; Claude ran the read-only SSH/curl/`alb-state`-status verifications between them. Claude
is also classifier-blocked from running the ALB mutations directly — by design, the human runs
every mutating step.

## 5. Verification status

- **Container-level (both prod nodes):** image tag + `TZ=America/New_York` confirmed. ✓
- **Behavioral (authenticated NetBadge):** done by Amber (akl3b) on **staging**, which was running
  the identical prod artifact `build-20260714134450` — she reported "everything looked fixed."
  This is the gold-standard proof for the exact image now live on prod. ✓

## 6. Node 3172 corrected on prod

Amber re-saved node 3172 on prod via NetBadge (new revision `vid=22137`, `changed=2026-07-17 09:07`).
Verified via a direct-DB `php:script` read: `unpublish_on = 1784736000` (2026-07-22 **12:00 EDT**),
replacing the bad `1784721600` (08:00 EDT). She signed off: *"The times I save items matches our current
time zone now."* All three closure conditions met — fix live on both nodes, reporter-confirmed behavior,
and the one affected row corrected.

## 7. Related work this session (spun out of the docs push)

Pushing the docs commits to `main` auto-deployed a `main`-based image to **staging** (ckeditor files
removed) onto staging's prod-snapshot DB (ckeditor still enabled) → the CKEditor 4 **ghost-module WSOD**
on `/admin/modules`. Fixed staging via a guarded config removal, then added an interim mitigation
(`local/ddev/backups/ckeditor-ghost-cleanup.sh`, wired into `update-db-from-remote.sh`) and a
[troubleshooting page](../troubleshooting/ckeditor-ghost-wsod.md). Prod was never affected. See that page
and the [CKEditor 4 removal](../maintenance/ckeditor4-to-ckeditor5.md) notes; the durable fix is the prod
ckeditor uninstall.

## Next steps

1. ~~Amber re-saves node 3172 on prod~~ ✅ done + verified.
2. Post the DLS-67 resolution comment (drafted 2026-07-17; 3172 confirmed corrected).
3. File the upstream `drupal/simplesamlphp_auth` bug (draft in
   [proposals/saml-timezone-clobber-guard.md](../proposals/saml-timezone-clobber-guard.md)).
