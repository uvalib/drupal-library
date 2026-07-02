# ADR 005: Theme/asset delivery — bounded divergence with pin-convergence

**Status:** Proposed (under active iteration — not yet Accepted)
**Date:** 2026-07-02
**Deciders:** Yuji Shinozaki (Lead Architect)
**Related:** Extends [ADR 003](003-runtime-cloned-themes-modules.md) (clone first-party themes/modules at build time). Mechanism documented in [Theme deployment](../operations/theme-deployment.md).

## Context

[ADR 003](003-runtime-cloned-themes-modules.md) established that first-party themes/modules
are cloned at build time rather than pinned via Composer, so they release on their own
cadence. In practice the **active theme** (`uvalibrary_v2a`, in the `uvalib-drupal-theme`
repo) changes routinely and is re-pinned to a git tag **at deploy time** via an SSM
parameter.

This arrangement is a deliberate response to two anti-patterns we refuse to accept:

- **Anti-pattern 1 — "small change → full container rebuild."** A minor theme/asset change
  must not force rebuilding and redeploying the container image.
- **Anti-pattern 2 — "redeploy regresses to baked-in state."** A container redeploy (for
  *any* reason) must not silently roll the theme back to whatever the image froze.

Avoiding both means the running theme can legitimately differ from what the image baked.
That divergence has until now been implicit and unnamed. This ADR makes it explicit and
states the invariant that keeps it safe.

## Vocabulary

- **Baked state** — what `docker build` froze into the image (the theme/asset revision
  present at build time).
- **Running state** — what a live container actually serves after deploy-time
  reconciliation (the revision at the current pin).
- **Divergence** — the delta between baked and running state.
- **The pin** — the external source of truth for the *desired* running state (today: SSM
  `/themes/uvalib/drupal-library/release`).
- **Convergence** — a deploy/boot step that reconciles a container's running state toward
  the pin.

## Decision — principles & invariant

These are the durable commitments this ADR ratifies:

1. **The image is a standalone-functional baseline.** A container must serve correctly from
   its baked state alone, with no *load-bearing* deploy-time dependency. Convergence is an
   enhancement layer, not a prerequisite for the site to function.
2. **Deploys catch up without a rebuild.** Running state advances to the current pin at
   deploy time; a small change ships by moving the pin, never by rebuilding the image
   (rejects anti-pattern 1).
3. **Redeploys converge; they do not regress.** Every deploy reconciles toward the pin, so
   re-running a container lands on the desired state, not the baked baseline (rejects
   anti-pattern 2).
4. **The governing invariant is fleet consistency, not image equality.**
    - Baked-vs-running divergence is **accepted and bounded**.
    - Inter-instance divergence (two running containers serving different state) is
      permitted **only while transient and intentional** — e.g. mid-rolling-deploy, node 0
      ahead of node 1 during verification.
    - **Persistent or accidental** inter-instance divergence (a missed node; a
      non-orchestrated recreate that regressed to baked) is a defect.
5. **Divergence must be explicit and observable.** Each container should be able to report
   `{baked_state, running_state}` so the invariant can be checked mechanically rather than
   assumed.
6. **Divergence is bounded by routine reconciliation of the baseline.** The baked baseline
   is periodically refreshed (rebuild + rolling redeploy) so running never drifts far from
   baked — keeping the fallback current and reproducibility tractable.

## Target design — PROPOSED, not yet adopted

Recorded as direction; deliberately *not* committed by this ADR. To be ratified/iterated
separately (likely a follow-up ADR once settled):

- **Immutable artifacts instead of live git.** Convergence pulls a per-tag theme *artifact*
  (e.g. tarball in S3/ECR) rather than doing `git fetch/checkout` inside the container —
  removing the GitHub-at-deploy dependency and detached-HEAD fragility while preserving
  principles 1–3.
- **Convergence at container startup (best-effort).** Reconcile on every boot, not only
  during an Ansible deploy, so non-orchestrated recreates self-heal (guards invariant 4).
  Best-effort: if the artifact store is unreachable, serve the baked baseline (honors
  principle 1).
- **Explicit `{baked, running}` observability.** A per-container status record/endpoint,
  plus a per-release manifest `{image_tag, theme_tag, date}`, making divergence visible and
  rollback reproducible (satisfies principle 5).
- **Routine rolling rebuilds.** Scheduled image rebuilds that re-absorb accumulated
  divergence, deployed node-by-node to preserve fleet consistency (satisfies principle 6).

## Consequences

- Theme/asset delivery is **named and governed** rather than implicit; "what is actually
  running" and "how far has it diverged" become answerable questions.
- **Correctness and hygiene are separated:** convergence keeps the site self-healing toward
  the pin; bounded baseline refresh keeps the baked image from silently rotting.
- Preserves the independent, fast theme-release cadence from ADR 003 while addressing its
  reproducibility/traceability gaps.
- The proposed design adds moving parts (an artifact build/store, a startup convergence
  step, a manifest). These are **not** yet adopted — this ADR commits only to the
  principles, vocabulary, and invariant above.
- The model generalizes: it can extend to the 2026 theme and custom modules, which today
  have no convergence path (they run baked `main` HEAD).
