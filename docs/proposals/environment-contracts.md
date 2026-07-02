# Proposal (stub): environment purposes & contracts

**Status:** Proposal / discussion — nothing adopted. Captured 2026-07-02 from a design
conversation; recorded so it persists, not yet worked out.
**Related:** [Ephemeral on-demand environments](ephemeral-environments.md) (the likely
resolution for the "devops" purpose), [ADR 005](../adr/005-theme-asset-delivery-bounded-divergence.md).

## The problem

There are (at least) **four distinct purposes but effectively three non-prod environments**,
and two of the purposes collide:

- **A — Comms authoring** (Communications team): theme/content work; wants a stable,
  always-available sandbox, no infra churn. → today: **dev** ("wild west," which is *fine* for
  this).
- **B — DevOps / infra experimentation**: needs to *break* things (deploys, container/infra)
  without touching A or gating releases. → today: has been **squatting on staging**.
- **C — Release validation**: a clean pre-prod mirror of exactly the release candidate. →
  nominally **staging**, but compromised by B.
- **D — Production.**

The unease about "staging became a dev box" is diagnostic: staging has an *implicit contract* —
**"staging is what's about to be production"** — and using it as a scratch box quietly breaks
that contract. B and C are mutually corrosive: you can't be a trustworthy release gate and a
demolition site at once.

## The direction

"Nailing down the divides" = **give each purpose an environment with an explicit contract**
(owner, stability guarantee, who may deploy, what its state *means*). Options to resolve the
A/B/C collision:

1. **Dedicated devops environment** — cleanest contracts, standing cost.
2. **Ephemeral devops environment** — spin up/down on demand (see the
   [ephemeral proposal](ephemeral-environments.md)); no permanent box, B never touches A or C.
   **Leaning this way** — it reuses infra-as-code and is where the ephemeral thread already
   points.
3. **Share dev between A and B, protect staging as C** — no new infra, but reintroduces the
   original interference (devops vs. Comms on dev) unless coordinated.

Resolving this **simplifies ADR 005**: once each environment has a known contract, its
desired-state (pin) semantics fall out — dev tracks `main` by design, a release gate holds the
candidate pin, prod holds the released pin, a devops sandbox needs no pin. `preview → node-0`
also gives some prod-side validation, easing the need for staging to be a perfect mirror.

## Open questions

- Which resolution (1/2/3)? Largely a question of budget for another environment vs. the
  ephemeral path.
- Is it worth formalizing as an ADR, or does the ephemeral proposal + this stub suffice while
  it plays out?
- ddev's role: great for the inner loop, but *not* for deployment-devops or NetBadge/SAML
  (the two things pushing toward ephemeral).
