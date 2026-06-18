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

## Where smoke tests fit

Post-deploy smoke tests (homepage, [search/Solr](../architecture/search-solr.md), a
JSON:API endpoint, the NetBadge redirect) belong against the staging URL after the
deploy step — the highest-ROI automated check for this site. See
[Maintenance](../maintenance/README.md).
