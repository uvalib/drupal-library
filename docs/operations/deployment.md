# Deployment

## Branch strategy

`main` → `release` (via PR). Merging `release` triggers CI.

## Build — `pipeline/buildspec.yml`

Runs on AWS CodeBuild. Builds `package/Dockerfile`, pushes the image to AWS ECR tagged
with a build timestamp and the git SHA. The latest build tag is stored in AWS SSM
Parameter Store at `/containers/$CONTAINER_IMAGE/latest`.

## Deploy — `pipeline/deployspec.yml`

Runs on AWS CodeBuild:

1. Clones `uvalib/terraform-infrastructure`
   (local checkout: `/Users/ys2n/Code/uvalib/terraform-infrastructure`).
2. Decrypts keys with `ccrypt`.
3. Runs Terraform in `library.virginia.edu/staging/`.
4. Runs Ansible playbooks (`deploy_netbadge.yml`, `deploy_backend.yml`) from
   `library.virginia.edu/staging/ansible/`.

!!! warning "Scope restriction in the terraform repo"
    The `terraform-infrastructure` repo contains many projects sharing resources. Edits
    there are restricted to the `library.virginia.edu/` subdirectory.

## Release tracking

Releases are tracked by **ECR image tags**, not git tags.

## Themes deploy on a separate track

The active theme is **not** promoted by image tag — it's pinned to a git tag at
deploy time via an SSM parameter, independently of the container. See
[Theme deployment](theme-deployment.md) for the full mechanism and runbook.

## Dev has no automated deploy pipeline

Unlike staging (auto-deploys on push to `main`) and production (auto-deploys on push to
`release`), **`dev` has no CI trigger at all.** `pipeline/deployspec.yml` is hardcoded to
`library.virginia.edu/staging` — there's no parameter or branch condition that redirects it
to `develop`, and no separate CodeBuild project exists for dev (confirmed against
`aws codebuild list-projects`: only `uva-drupal-library-project-{builder,deploy}` (staging),
`uva-drupal-library-release-project-{builder,deploy}` (production), and
`uva-drupal-library-production-deploy-project-deploy` exist for this app).

Practically, this means **dev's container image only updates when someone manually runs the
deploy** — and it's easy for that to go weeks without happening. This was diagnosed as the
root cause of a real bug report (Layout Builder's "Add Section" off-canvas modal missing on
dev but working on staging/prod): dev was running a build over 6 weeks older than staging.

!!! warning "A dedicated on-demand CodeBuild project for dev is proposed but paused"
    See [Proposal: develop deploy pipeline](../proposals/develop-deploy-pipeline.md) — WIP
    committed to a `wip/develop-deploy-pipeline` branch in both `drupal-library` and
    `terraform-infrastructure`, not merged, pending a decision on IAM role reuse vs. a new
    role (the auto-mode permission classifier blocks writing new IAM policy Terraform).

### Manual dev deploy procedure (until the pipeline exists)

Mirrors what `deployspec.yml` does for staging, run locally instead of in CodeBuild:

```bash
cd terraform-infrastructure/library.virginia.edu/develop
aws-vault exec staging --prompt=osascript -- terraform init -reconfigure   # if backend cache is stale
aws-vault exec staging --prompt=osascript -- terraform init -upgrade      # if state was written by a newer provider
AMI=$(aws-vault exec staging --prompt=osascript -- terraform output instance_ami | grep ami | head -1 | tr -d ', "')

# This project's provider block wants raw access_key/secret_key/region (no session-token
# support), unlike most other work in this repo — map aws-vault's session into TF_VAR_*:
aws-vault exec staging --no-session --prompt=osascript -- sh -c \
  'TF_VAR_access_key="$AWS_ACCESS_KEY_ID" TF_VAR_secret_key="$AWS_SECRET_ACCESS_KEY" TF_VAR_region="us-east-1" \
   terraform apply -auto-approve -no-color -var instance_ami="'"$AMI"'" \
   --target=local_file.inventory --target=local_file.tfvars'

cd ansible
aws-vault exec staging --no-session --prompt=osascript -- ansible-playbook deploy_netbadge.yml
aws-vault exec staging --no-session --prompt=osascript -- ansible-playbook deploy_backend.yml
```

Gotchas hit doing this the first time (2026-08-11):

- **`Backend configuration changed`** on `terraform init` — a stale local `.terraform` cache
  from a previous directory's backend settings (e.g. `use_lockfile`). `.terraform.lock.hcl`
  isn't git-tracked and `versions.tf` pins no provider version, so `-reconfigure` (and
  later `-upgrade`) are safe — nothing shared is affected.
- **`Resource instance managed by newer provider version`** — the remote state had been
  written by a newer `hashicorp/aws` than was locally cached. `terraform init -upgrade`
  fixed it (again, safe: no committed lockfile, no version pin).
- **`No value for required variable: access_key/secret_key/region`** — this project's
  `provider "aws" {}` block (`main.tf`) takes these explicitly rather than relying on the
  standard credential chain, and has no `token` argument — so it can't accept an aws-vault
  *session* (STS temp credentials require a paired session token). `aws-vault ... --no-session`
  passes through the underlying long-term credentials instead, which this provider block
  can use directly.
- The latest available build tag lives in SSM at `/containers/uvalib/drupal-library/latest`
  (`aws ssm get-parameter --name /containers/uvalib/drupal-library/latest`) — this is what
  staging is always running (it deploys on every push), so it's the right target to bring
  dev up to as well.

## Where smoke tests fit

Post-deploy smoke tests (homepage, [search/Solr](../architecture/search-solr.md), a
JSON:API endpoint, the NetBadge redirect) belong against the staging URL after the
deploy step — the highest-ROI automated check for this site. See
[Maintenance](../maintenance/README.md).
