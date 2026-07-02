# Proposal: ephemeral on-demand environments

**Status:** Proposal / discussion — nothing adopted.
**Audience:** primarily a starting point for the conversation with the AWS architect
(Dave), who may already have patterns or implementations for on-demand environments.
**Related:** [ADR 005](../adr/005-theme-asset-delivery-bounded-divergence.md) (theme
convergence), [Theme deployment](../operations/theme-deployment.md), the SAML
config-as-code work ("item B").

!!! note "How to read this"
    The orchestration — disposable EC2 instance, CodeBuild-driven apply/deploy, TTL +
    auto-reaping — is **already Dave's established pattern** (`pipeline/buildspec.yml` +
    `deployspec.yml` under CodeBuild/CodePipeline); we want to follow it, not reinvent it.
    The parts that are genuinely *ours* to specify are the **app-specific sockets** the
    instance plugs into (SAML identity, ALB remap, Redis index) and the **Redis
    sizing/allocation** asks. The spin-up section below is deliberately framed as "the
    same CodeBuild recipe, parameterized," to stay inside his existing tooling.

## Why we need this

Two things ddev structurally cannot do, plus one environment-hygiene problem:

- **Deployment/infra devops** — ddev never exercises the real path (Dockerfile → ECR →
  Terraform/Ansible → EC2 → ALB → two-node topology → host-side config-sync). The things
  that actually break on deploy don't exist in ddev.
- **NetBadge/SAML testing** — SAML is bound to a *registered* SP identity (entityID + ACS
  URL the IdP trusts). `*.ddev.site` isn't registered, so the real flow can't be tested.
- **Staging role-blur** — for want of a devops sandbox, this work has landed on **staging**,
  turning the clean release gate into a scratch box. An on-demand env frees staging to be
  purely "what's about to be production," and leaves dev as the Communications team's
  authoring sandbox.

## The essence

**A disposable, on-demand EC2 instance that plugs into a small set of fixed "sockets."**
The instance churns; the sockets don't. Everything else reuses existing shared infra —
this is *not* a new environment, VPC, load balancer, or database.

## Reused as-is (existing shared infra — no new provisioning)

VPC / subnets / security groups (a base environment's `network` + `security` state) ·
the shared ALB (`loadbalancer-visibility`, `uvaonly` = VPN-only) · Route53 (`global/dns`) ·
the shared Redis cluster · RDS (or a sidecar DB) · bastion · the ccrypt secret mechanism
(`decrypt-key.ksh`) · the **stored SAML keypair** · ECR images · the existing
`deploy_backend.yml` / `deploy_netbadge.yml` playbooks.

## The fixed sockets (app-specific — our requirements)

These are stable reservations the transient instance occupies. They exist *because* the
instance is recurring:

1. **SAML slot** — a dedicated devops SP identity: keypair `library-drupal-saml-devops.{pem.cpt,crt}`
   (ccrypt-stored like every other env's), its `.crt` registered **once** with the NetBadge
   team at a fixed ACS host. The instance selects it via `SIMPLESAML_ENV=devops` in
   `container.env`; an ALB host-header rule swings the fixed ACS host onto the current
   instance. Because the cert is reused, the instance *is* the trusted SP with no per-env
   registration.
2. **ALB listener-rule slot** — a priority-pinned host-header rule remapped to the current
   instance (the same primitive as `preview.library.virginia.edu → node-0`).
3. **Redis DB index** — a reserved index (session; and cache if the Redis cache backend
   lands) so ephemeral sessions don't collide with the envs already using indices.
4. *(optional)* a fixed "current devops env" DNS name.

## Spin-up: the existing CodeBuild pattern, parameterized

**We don't need new orchestration** — this is exactly what `pipeline/deployspec.yml`
already does under CodeBuild/CodePipeline: clone `terraform-infrastructure`, `decrypt-key.ksh`,
`terraform apply`, then run the `deploy_netbadge.yml` / `deploy_backend.yml` playbooks. The
ephemeral flow is the same recipe against a parameterized target:

- An **`ephemeralspec.yml`** CodeBuild project, a sibling of `buildspec.yml`/`deployspec.yml`:
  decrypt keys → `terraform workspace select/new eph-$EPHEMERAL_ID` → `terraform apply` the
  `ephemeral/` module → `ansible-playbook deploy_backend.yml deploy_netbadge.yml`.
- **Parameters as `start-build` environment-variable overrides** (the same way
  buildspec/deployspec already take env): `EPHEMERAL_ID`, `DEPLOY_TAG`, `CLAIM_SAML_SLOT`,
  `TTL_HOURS`, `BASE_ENV`.
- **On-demand trigger:** `aws codebuild start-build --project-name library-drupal-ephemeral
  --environment-variables-override name=EPHEMERAL_ID,value=saml-test …` — or a CodePipeline
  button / chatops, whatever fits the existing tooling.
- **Teardown:** the same project with `ACTION=destroy` (or a sibling `ephemeral-destroy`
  project) running `terraform destroy` for that workspace.
- **TTL / auto-reaping:** a **scheduled CodeBuild** (EventBridge cron) that runs the destroy
  path for any workspace/instance past its `expires_at` tag — still the CodeBuild pattern, no
  bespoke Lambda.

State is isolated per `EPHEMERAL_ID` (Terraform workspace, or a per-id backend key), and
**no image is built during spin-up** — it deploys an existing ECR tag, so spin-up is just
`terraform` + `ansible` (minutes). A developer can also run the same steps locally via the
`tf` / `ap` (aws-vault) aliases, but CodeBuild is the intended path since it matches how
staging already deploys.

## App-specific module delta (ours regardless)

A parameterized `ephemeral/` Terraform module — `backend.tf` / `dns.tf` / `ansible.tf` are
near-copies of `develop/`; the only novel resources are the target group + the two ALB
host-header rules (app host, and the conditional `claim_saml_slot` SAML rule). Key inputs:
`ephemeral_id`, `base_environment`, `deploy_tag`, `deploy_theme_tag`, `claim_saml_slot`,
`saml_fixed_hostname`, `redis_session_db_index`, `ttl_hours`.

## Redis — combined asks for Dave

Worth one conversation with the [Redis cache-backend](../maintenance/redis-cache-backend.md)
thread rather than two. Three converging demands on the shared cluster:

1. **Drupal cache backend on Redis** — the real fix for the two-node prod cache-deadlock
   WSOD; needs a cache index per env + headroom.
2. **Drupal session store on Redis** — makes containers stateless (sessions survive
   recreate/rolling deploys), which is exactly what the ADR-005 convergence model wants.
3. **Ephemeral envs** — each is another consumer needing a **reserved index**, cycled often.

Ask: agree an **index allocation** (a reserved range for ephemerals), **sizing/eviction
policy**, and whether ephemeral traffic shares the cluster or gets a small separate one.

## Questions for Dave

1. Should the ephemeral spin-up be a **new parameterized CodeBuild project** (`ephemeralspec.yml`)
   alongside `buildspec.yml`/`deployspec.yml`, following your existing CodePipeline pattern —
   or do you already have an on-demand-environment mechanism we should plug into instead?
2. Where's the natural split — your CodeBuild/pipeline orchestration (instance lifecycle,
   TTL/reaping via scheduled builds, state isolation, cost tagging) vs. what stays ours (the
   SAML slot, the Redis index, config-as-code, the `ephemeral/` module)?
3. **Placement** — which shared VPC/environment should ephemerals live in (leaning
   `develop`), and any **ALB listener-rule quota** concerns?
4. **DB** — bless a sidecar DB container per env, or a scratch-schema convention on the
   shared RDS?
5. **Redis** — index allocation + sizing + shared-vs-separate (see above).
6. **DNS + SAML** — record-creation and the one-time SP cert registration at the fixed ACS
   host.
7. **Cost governance** — tagging + an auto-teardown/TTL policy.

## Not adopted

This is proposal-stage. It leans on the still-in-flight SAML **config-as-code** ("item B",
which makes the SP entityID/baseurl portable) and complements **ADR 005**. Nothing here is
committed; the intent is to align with existing patterns before building.
