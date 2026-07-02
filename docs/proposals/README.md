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
