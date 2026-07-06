# Proposals

Proposal-stage design work — ideas under discussion that are **not yet decided**. This is
the pipeline *before* an [ADR](../adr/README.md): a proposal captures a direction and its
open questions while it plays out; if and when it settles, it becomes an ADR (decided) and
the mechanism moves into [Operations](../operations/README.md) or
[Architecture](../architecture/README.md).

Nothing here is adopted or committed to.

| Proposal | Summary |
|----------|---------|
| [Ephemeral on-demand environments](ephemeral-environments.md) | Disposable EC2 instances plugged into fixed sockets (SAML slot, ALB remap, Redis index) for deployment-devops and NetBadge/SAML testing — freeing staging to be a clean release gate. |
| [Environment purposes & contracts](environment-contracts.md) *(stub)* | Give dev / devops / staging / prod each an explicit contract, so staging stops doubling as a devops scratch box. |
| [Rethink the baked-in util checkout](util-checkout-rethink.md) *(stub)* | Replace the 166 MB full-repo clone in the image (90 MB is `.git`) with a `COPY` of just the paths used; keep the "live repo" convenience as an optional host mount. |
| [SimpleSAMLphp version-compat check](saml-version-compat-check.md) | Advisory deploy-time check that the SP container and the Drupal-side SimpleSAMLphp share a `saml2` major (session-format compatibility); one shared Ansible task, no API/module. |

## Proposals that already have a home (cross-listed)

These are planned [Maintenance](../maintenance/README.md) work, not homeless ideas — but the
*proposal itself* (direction, open questions) isn't decided yet, so they're listed here too
for visibility. Once decided, these stay in Maintenance rather than moving elsewhere.

| Proposal | Home | Summary |
|----------|------|---------|
| [Config-sync mechanism review](../maintenance/config-sync-mechanism-review.md) | Maintenance | Redesign direction not chosen yet (three open options) for the config-sync export mechanism. |
| [Redis cache backend](../maintenance/redis-cache-backend.md) | Maintenance | Move Drupal's cache off the shared DB onto the existing `ha-redis` cluster — direction is fairly settled, but sizing/dedicated-instance questions are still open. |
