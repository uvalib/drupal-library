---
status: needs-review
opened: 2026-10-22
jira: null
---

# Is the `drupal-library-production-deploy` pipeline active?

!!! warning "Status: bookmarked for later review (2026-10-22) — not investigated"
    A third drupal-library pipeline exists in Terraform and nobody has confirmed what it does
    today. The production runbook says production is deployed **manually** with Ansible, so this
    pipeline may be dormant, but that was not checked.

## What is known

- Definition: `terraform-infrastructure/aws_cicd/pipelines/drupal-library-production-deploy/`.
  It has **only a deploy phase** (`deploy_phase = true`, no build phase) and `source_branch`
  defaults to **`main`**. It sets no `trigger_paths`, unlike the main drupal-library pipeline,
  which now filters to `package/**` and `pipeline/**`.
- A CodeBuild project `uva-drupal-library-production-deploy-project-deploy` is recorded in the
  earlier deploy-timings notes.
- Indirect evidence it is not deploying production on every push: production stayed on
  `build-20261022134259` after later merges to `main` (PR #15 and PR #16) until it was rolled
  by hand. This is not proof; the pipeline could be failing or manually triggered.

## Open questions

1. Does it have recent executions, and what triggers them (webhook on `main`, manual start)?
   (`aws codepipeline list-pipelines`, then `list-pipeline-executions` on the production one.)
2. Is it meant to be the way production is deployed, or is it a leftover? If leftover, retire it
   in Terraform.
3. If it can start on a push to `main`, it should at least get the same `trigger_paths` filter,
   and the team should decide whether an automatic production deploy is acceptable at all given
   the one-node-at-a-time, no-`drush cr` rules in the
   [runbook](../operations/production-deploy-runbook.md).

## Related

- [Release branch review](release-branch-review.md)
- [Session log 2026-10-22](../session-logs/2026-10-22-logout-url-port-fix-and-release-tagging.md)
