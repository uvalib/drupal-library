# Session Log: DevOps Docs Live on Staging + Design Proposals Wave

**Date:** 2026-07-02
**Participants:** Yuji Shinozaki, Claude (Opus 4.8)
**Branch:** `main`
**Outcome:** Deployed and verified the `devops_docs` module on staging; refactored docs delivery so mkdocs is build-/dev-time only (built `static/` committed, no mkdocs in the image); documented the theme-deployment mechanism; drafted **ADR 005** (theme/asset delivery — bounded divergence) and an **ephemeral-environments proposal** through a long design discussion; root-caused and fixed the recurring `vendor-archive` ghost symlink; disabled the SAML `debug` flag on staging and scheduled the prod change for Monday. Continues [2026-07-01 devops-docs module](2026-07-01-devops-docs-module.md).

---

## 1. devops_docs → staging (live & verified)

The `691f9a1` build (after fixing a `.git`-in-CodeBuild bug) deployed to staging. Enabled the
module (`drush en devops_docs`), which fired `hook_install()` to create the `devops` role and
seed `ys2n`/`xw5d`. Ran the full matrix against the staging container: anon → 403; nested
paths → 301 → trailing slash → 200; assets correct MIME; missing → 404; no watchdog errors.
`/devops-docs` is live at `https://library-drupal-staging.internal.lib.virginia.edu/devops-docs`.

## 2. Docs delivery refactor — mkdocs out of the image

Reworked the approach: the built `static/` mkdocs site is now **committed to the repo** and
shipped as-is (the image already clones the repo + symlinks the module), so the whole
`docs-builder` Docker stage is gone. Rationale: a broken docs page or the mkdocs toolchain can
no longer block the application image build. Docs became a **local dev step**:
`scripts/build-docs.sh` (local mkdocs or Docker), an **advisory-only** pre-commit drift hook
(`scripts/git-hooks/pre-commit` — warns "N doc files changed, static N commits behind", never
blocks), and `scripts/install-git-hooks.sh`. `.gitattributes` marks `static/` generated.
Validated end-to-end: the new no-`docs-builder` image built and deployed to staging, and the
running image contains **zero mkdocs/python tooling** (only the static artifacts).

## 3. Styling — mandala adoption + sidebar accordions

Adopted `mandala-navina`'s sidebar CSS (`docs/stylesheets/extra.css`: bold section labels,
nested indentation, last-updated date), and enabled collapsible **accordions** by removing
`navigation.sections` from `mkdocs.yml`. Also enabled the `admonition`/`pymdownx` extensions
(a page used a `!!!` callout that was rendering as literal text).

## 4. Theme deployment documented

Deep-dive into how the active theme deploys, resolving several ambiguities with the user
(and correcting a stale legacy runbook). Captured in `docs/operations/theme-deployment.md`:
the active theme (`uvalibrary_v2a`, in the `uvalib-drupal-theme` repo) is re-pinned to a git
**tag at deploy time**, the tag coming from **SSM** `/themes/uvalib/drupal-library/release`;
`theme_deployment_task.yml` is called from `deploy_backend.yml` (full deploy, cold cache) and
`deploy_theme_only.yml` (warm container, runs `drush cr`). The SSM default is set **manually**
(`aws ssm put-parameter`); dev is manual `git pull` of `main`; production is done node-by-node.
Container/code promotion is a separate track (ECR image tag).

## 5. ADR 005 (Proposed) + the design discussion behind it

A long architecture discussion produced **ADR 005 — Theme/asset delivery: bounded divergence
with pin-convergence** (Status: Proposed). It names two anti-patterns the current design
avoids (*small-change → full rebuild*, and *redeploy → regress to baked state*), introduces
the vocabulary (**baked state / running state / divergence / the pin / convergence**), and
states the governing invariant: **fleet consistency, not image equality** — baked-vs-running
divergence is accepted and bounded; inter-instance divergence is allowed only while transient
and intentional. Implementation (immutable artifacts, best-effort startup convergence,
`{baked,running}` observability, routine rolling rebuilds) is recorded as *proposed*, not
adopted.

## 6. Ephemeral-environments proposal

Motivated by: ddev can't do deployment-devops or NetBadge/SAML testing, and devops work had
been squatting on **staging** (blurring its role as the clean release gate). Designed a
**disposable on-demand EC2 instance plugged into a small set of fixed sockets** — a dedicated
SAML SP identity (ccrypt keypair + one-time registration; reuses `decrypt-key.ksh`), an ALB
host-header remap (same primitive as `preview → node-0`), a reserved Redis index. Everything
else reuses existing shared infra. Captured in `docs/proposals/ephemeral-environments.md`
(a new **Proposals** section). Framed for the AWS architect (Dave), who likely already has an
on-demand-env pattern: the spin-up is a **parameterized CodeBuild spec** in the same family as
`buildspec.yml`/`deployspec.yml`, and the Redis asks (cache backend + session store + ephemeral
index allocation + sizing) are bundled for one conversation. Nothing adopted.

## 7. Image inspection — the util checkout

While confirming no mkdocs machinery ships, found the image bakes a **166 MB full-repo clone**
at `/opt/drupal/util/drupal-library` (`.git` alone is 90 MB) for the config/patches/
vendor-archive/module symlinks. Only build-time-needed paths are actually used; the clone could
be replaced by `COPY`ing those paths (no `.git`, no docs/mkdocs source). Flagged as a rethink
that dovetails with the config-sync review; the util checkout is referenced *only* in the
Dockerfile.

## 8. vendor-archive ghost symlink — root-caused & fixed

The recurring self-referential `vendor-archive/vendor-archive` symlink was root-caused (and
reproduced): the ddev post-start `ln -sf TARGET LINKNAME` hook **dereferences** the existing
`local/ddev/vendor-archive` symlink into its target dir on the 2nd+ `ddev start`, creating the
ghost inside. Fixed with `ln -sfn` (`--no-dereference`) on both that hook and the `devops_docs`
hook added earlier this session (same latent bug). Verified: no ghost after `ddev restart`.

## 9. SAML debug flag

`simplesamlphp_auth.settings.debug` was `true` on staging and both prod nodes (can log SAML
assertion detail). **Staging fixed** (set `false` via type-safe `php:eval`; verified; login
still 303-redirects). **Production deferred to Monday 2026-07-06** per the user — a calendar
reminder was set, and the memory note holds the runbook (flip live on both nodes + update the
config-sync `production` branch so a `cim` doesn't revert it).

## 10. Also

Added `docs/architecture/hostnames-and-urls.md` (browser URLs per env, prod ALB aliases,
backend/SSH hosts) + memory. Normalized "Valkey" → "Redis" in the proposal per team
terminology (the ElastiCache engine is technically Valkey 8.2, but everyone calls it Redis).

## 11. SimpleSAMLphp SP ↔ Drupal version compatibility

Examined a SAML deployment code-smell: the SP runs as a separate container (`drupal-netbadge`)
while each Drupal app runs its own SimpleSAMLphp library — two installs that interoperate via a
shared Redis session store. The **config** contract is already handled by terraform env vars;
the **gap** is that the two SimpleSAMLphp/saml2 *library versions* are governed by independent
`composer.lock`s with nothing coordinating them, and a **major** drift breaks session
deserialization (silent auth failure). Verified they're currently aligned (both `saml2 v5.0.5`)
but already patch-skewed — proof the locks float independently. Converged (after discarding
runtime version-endpoints and a static lock-diff as over-built) on an **advisory deploy-time
check**: the deploy already runs both playbooks, so a small shared Ansible task reads
`simplesamlphp/saml2` from each container's `installed.json` and warns on a major divergence — no
API, module, or cron. Captured as a proposal; drupal-netbadge is the implementation home, but the
**documentation stays in this devops-docs site** since drupal-netbadge has no docs infrastructure
(reinforcing that this site is becoming the cross-repo devops-docs hub).

## 12. Proposals section + loose-ends capture

Created a **Proposals** section (proposal-stage, nothing adopted) and captured the session's
design threads so they persist: ephemeral environments, environment contracts, the util-checkout
rethink, and the SAML version-compat check. Also did a loose-ends scan across memory + docs and
produced a next-steps summary.

---

## Open items (carried forward — see the session summary for next steps)

- **SAML `debug` flag → production** — scheduled Mon 2026-07-06 (staging done).
- **Config-sync mechanism redesign** — foundational; the debug-flag dance is a live symptom.
- **Redis cache backend + session store** — architecture review with Dave; bundled with the
  ephemeral Redis asks.
- **SAML config-as-code ("item B")** — also gates ephemeral-env "turnkey" SAML.
- **CKEditor 4 removal → production**, and **devops_docs enablement → production** — both
  pending, both carry the two-node cache-deadlock caveat.
- **ADR 005 ratification** and the **ephemeral proposal → Dave conversation**.
- **Proposals to iterate/advance** (all now captured in the Proposals section): ephemeral
  environments, environment contracts, util-checkout rethink, SimpleSAMLphp version-compat check.
- **Drupal 11 upgrade** (gated on CKEditor); **validation/smoke-test suite** (not built).
