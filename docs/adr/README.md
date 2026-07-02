# Architecture Decision Records

Significant, durable decisions about how the library.virginia.edu site is structured are
recorded here, each with its context and rationale.

## How ADRs work here

- **One decision per ADR.** Keep each ADR atomic and single-purpose.
- **Immutable against *redaction*.** The recorded Context / Decision / Consequences of an
  accepted ADR are never altered or removed — that record is the history of what was
  known and decided at the time.
- **Annotation is allowed; redaction is not.** A superseded ADR is *annotated* with a
  banner at the top pointing to its replacement (an additive marker — the original text
  below it stays intact). It is never edited to change the decision.
- **A changed decision is a new, whole ADR.** When a decision changes, write a new ADR
  that supersedes the old one in its entirety, and update this index. Do not amend a
  clause inside an accepted ADR.
- **This index is the source of current standing.** A reader should enter via this table;
  the Status column shows whether an ADR is current or superseded.

Supersession banner to add at the top of a superseded ADR:

```
> ⚠️ **Superseded by [ADR-0NN](0NN-title.md)** — YYYY-MM-DD.
> Retained for historical context; the decision below reflects what was known at the time.
```

## Index

| # | Title | Status |
|---|-------|--------|
| [001](001-config-in-separate-repo.md) | Store exported Drupal config in a separate repo | Accepted |
| [002](002-https-at-load-balancer.md) | Terminate HTTPS at the load balancer; serve HTTP in the container | Accepted |
| [003](003-runtime-cloned-themes-modules.md) | Clone first-party themes/modules at build time, not via Composer | Accepted |
| [004](004-target-drupal-11.md) | Upgrade to Drupal 11 (PHP 8.3) | Accepted |
| [005](005-theme-asset-delivery-bounded-divergence.md) | Theme/asset delivery: bounded divergence with pin-convergence | Proposed |
