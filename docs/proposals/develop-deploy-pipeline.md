---
status: paused
opened: 2026-08-11
---

# Proposal: on-demand deploy pipeline for `develop`

!!! warning "Status: paused 2026-08-11, pending further thought"
    Scaffolding was written and committed to a **local, unpushed** `wip/develop-deploy-pipeline`
    branch in both `drupal-library` and `terraform-infrastructure`, then explicitly set aside
    at the user's request ("let's hold off on the codebuild project... put your current
    changes into a local branch and re-checkout main"). Both repos are back on their default
    branch with clean working trees; nothing here is merged or deployed. This page exists so
    the WIP and the reasoning aren't lost.

## Why

[Dev has no automated deploy pipeline](../operations/deployment.md#dev-has-no-automated-deploy-pipeline)
— unlike staging and production, updating dev's container requires someone to manually run
Terraform + Ansible locally. This was diagnosed as the cause of a real bug (dev running a
build 6+ weeks stale, missing a Layout Builder fix present on staging/prod) and fixed
one-off by running the deploy manually. The proposal is to stop that from silently recurring.

## Decisions made before pausing

- **On-demand only, not auto-triggered.** The user chose this explicitly over auto-deploy on
  push — dev shouldn't redeploy on every `main` commit the way staging does; someone should
  choose when dev gets refreshed.
- **Standalone `aws_codebuild_project`, no `aws_codepipeline`/webhook.** The shared
  `modules/codepipeline` module (used by every other pipeline in this repo) always wires up a
  CodePipeline + webhook that auto-triggers on push — not what's wanted here. The template
  instead is `aws_cicd/pipelines/main-lib-site-legacy-content-deploy/` (a standalone
  `aws_codebuild_project`, invoked only via `aws codebuild start-build --project-name ...` or
  the console; that example also happens to add a `trigger.tf` CloudWatch schedule, which
  should **not** be replicated here — no schedule, no push trigger, purely manual).
- **New buildspec, not the existing one.** `pipeline/deployspec.yml` hardcodes
  `library.virginia.edu/staging` with no parameter — rather than adding conditionals to it,
  wrote a sibling `pipeline/deployspec-develop.yml` (same phases, targets
  `library.virginia.edu/develop`, adjusts the decrypted-key paths accordingly). This file
  exists on the WIP branch, not on `main`.
- **IAM: reuse an existing role, don't create a new one.** The standalone-project template's
  own `iam.tf` (new `aws_iam_role` + policies, boilerplate-identical to ~10 other pipelines in
  this repo) got blocked by the Claude Code auto-mode permission classifier as a new-IAM-policy
  write. Rather than route around that, the user chose to point the new CodeBuild project at
  an **existing** compatible role instead — the natural candidate is
  `drupal-library-codebuild-iam-role` (the role backing the existing staging
  `uva-drupal-library-project-deploy`/`-builder`, created by `modules/codepipeline`), since it
  already has the secrets-read, S3 terraform-state, and general CodeBuild execution
  permissions this needs. **This part was not finished** — need to confirm that role's trust
  policy allows another CodeBuild project to assume it, or whether a `service_role` pointing
  at another project's IAM role even works as intended across separate Terraform state files
  (`aws_cicd/pipelines/drupal-library/` owns that role's state).

## What's on the WIP branch right now

- `drupal-library` (`wip/develop-deploy-pipeline`, commit `e3189e6`): `pipeline/deployspec-develop.yml`.
- `terraform-infrastructure` (`wip/develop-deploy-pipeline`, commit `0214622`):
  `aws_cicd/pipelines/drupal-library-develop-deploy/{main.tf,variables.tf,remote-state.tf,versions.tf}`
  — **no `iam.tf`, no `projects.tf`, no `cloud-watch.tf`** yet. `variables.tf` notably pins
  `environment = "staging"` (not `"develop"`) as the default, because dev's own Terraform
  config ([`library.virginia.edu/develop/variables.tf`](https://github.com/uvalib/terraform-infrastructure))
  does the same — dev deliberately shares staging's network/security/bastion remote state.

## Open questions for whoever picks this back up

1. Finish the IAM decision above (reuse vs. new role, and whether cross-project role reuse is
   even the right shape here).
2. Write `projects.tf` (the actual `aws_codebuild_project` resource — source repo
   `uvalib/drupal-library`, buildspec `pipeline/deployspec-develop.yml`, `vpc_config` matching
   the `network`/`security` remote state, `TF_VAR_access_key`/`TF_VAR_secret_key` from Secrets
   Manager the same way the existing staging project gets them — see
   [Manual dev deploy procedure](../operations/deployment.md#manual-dev-deploy-procedure-until-the-pipeline-exists)
   for why those are needed and how they're normally sourced).
3. Decide whether a `cloud-watch.tf` (log group + success/failure SNS notifications, matching
   every other pipeline here) is worth it for an on-demand-only project.
4. `terraform plan`/`apply` in `aws_cicd/pipelines/drupal-library-develop-deploy/` to actually
   create the project, then a real `aws codebuild start-build` test run.
