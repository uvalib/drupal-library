---
status: needs-review
opened: 2026-10-22
jira: null
---

# Is the `release` branch still part of the flow?

!!! warning "Status: bookmarked for later review (2026-10-22) — nothing decided"
    The documented flow is `main` → `release` via PR, with CI triggering on a `release` merge.
    That is not what happens today. This note records the evidence and the open decision so it
    isn't lost; no change has been made.

## What the docs say

- Repo `CLAUDE.md`: "**Branch strategy:** `main` → `release` (via PR). Merging `release` triggers CI."
- `docs/operations/deployment.md` (release tracking) says releases are tracked by ECR image
  tags, not git tags (also out of date; see the git/docker tag pairing in the
  [production runbook](../operations/production-deploy-runbook.md)).

## What actually happens (verified 2026-10-22)

- Image builds come from pushes to **`main`**: production's `build-20261022134259` started 12
  seconds after PR #14 merged to `main`, and `build-20261022153610` started 58 seconds after PR #16.
  The pipeline definition is `terraform-infrastructure/aws_cicd/pipelines/drupal-library`
  (`source_branch = main`, now with `trigger_paths = [package/**, pipeline/**]`).
- `origin/release` is stale at `4079ce3` ("fix: DLS-67 set container TZ to America/New_York",
  July 2026). Nothing recent has gone through it.
- Related leftovers still exist: a separate pipeline `aws_cicd/pipelines/drupal-library-release`
  (watches `release`, has build and deploy phases), the `uva-drupal-library-release-project-*`
  CodeBuild projects, and commented-out `release` tag/SSM lines in `pipeline/buildspec.yml`.
- Note the **theme** repo (`uvalib-drupal-theme`) *does* use a `release` branch, and its tags are
  cut there. That is a different repo; see
  [theme repo: `main` vs `release`](theme-repo-main-release-divergence.md).

## Open questions

1. Is the `release` branch (and its pipeline/CodeBuild projects) used by anyone, or was it
   superseded when builds moved to `main`? Check the last execution of the release pipeline
   (`aws codepipeline list-pipeline-executions`, pipeline name to be confirmed with
   `list-pipelines`) before deleting anything.
2. If it is retired: delete or archive the branch, remove the `drupal-library-release` pipeline
   from Terraform, and correct `CLAUDE.md`, `deployment.md` and the old PR #6 reference.
3. If it is revived (for example a promotion gate so a merge to `main` stops auto-deploying
   staging): decide what `release` should mean and fix the pipeline source accordingly.

## Related

- [Production deploy runbook](../operations/production-deploy-runbook.md)
- [Session log 2026-10-22](../session-logs/2026-10-22-logout-url-port-fix-and-release-tagging.md)
