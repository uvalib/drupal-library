# Session Log: DLS-68 dev content loss, recovery, and the dev deploy pipeline gap

**Date:** 2026-08-10 to 2026-08-11
**Participants:** Yuji Shinozaki, Claude (Sonnet 5)
**Branches:** `main` (drupal-library); `master` (terraform-infrastructure) — both ended clean,
with unrelated WIP parked on unpushed `wip/develop-deploy-pipeline` branches in each repo
**Outcome:** Root-caused and recovered Ruobing's lost dev content (DLS-68); wrote up the
previously-undocumented TeamCity DB clone mechanism and its ID-collision failure mode;
separately found and fixed dev's container being 6+ weeks stale (no deploy pipeline exists
for dev); started, then paused, a proposal to build one

---

## 1. The investigation (full detail in the incident doc)

Started as "did dev's DB get reset around 7/30?" — it didn't, and chasing that hypothesis to
ground was itself useful (ruled out via TeamCity's actual build history, and via
`information_schema.CREATE_TIME` turning out to be misleading evidence, confirmed by checking
the same pattern on staging).

The real mechanism, found by working backward from a TeamCity access token: a weekly
`production -> develop0` DB clone silently overwrites dev every Monday, and dev/prod's
auto-increment counters realign on every sync — so content created independently on both
sides between syncs can collide on the same entity ID, and the loser (always dev, since prod
always wins the next sync) vanishes with no error and no log trace. This bit Ruobing twice on
the same page: once for the node, once for an Asset Injector CSS rule.

Recovery pulled the pre-sync S3 backup (`s3://library-drupal-content-global/db/`, a byte-exact
mysqldump), verified extraction through a throwaway local MySQL container rather than trusting
hand-rolled parsing (which had silently truncated data on the first attempt), and restored the
CSS via Drupal's config API and the page as a new node (not an overwrite — the original node ID
now holds unrelated real content). Hit and fixed one more gotcha along the way: a `block_content`
restored by raw SQL rendered fine but broke on edit, because Drupal reads base fields from
`block_content_field_revision`, not the more obviously-named `block_content_field_data`.

**Full writeup:** [Incident: DLS-68](../incidents/2026-08-10-dls-68-dev-content-loss-and-recovery.md)
**Mechanism doc:** [Syncing data → Automated environment sync](../operations/syncing-data.md#automated-environment-sync-teamcity-distinct-from-the-above)
**Restoration gotcha:** [Troubleshooting → Layout Builder entity restoration](../troubleshooting/layout-builder-entity-restoration.md)

## 2. A second, unrelated bug surfaced the same mechanism's blind spot

Separately reported: Layout Builder's "Add Section" off-canvas modal missing on dev but
working on staging/prod. Root cause: dev's container was running a build from **June 25**
— over six weeks behind staging — because **dev has no automated deploy pipeline at all**.
`pipeline/deployspec.yml` is hardcoded to staging; only staging (push to `main`) and
production (push to `release`) auto-deploy. Nothing was silently failing — nothing was
running.

Fixed immediately by manually running the Terraform + Ansible deploy locally against
`library.virginia.edu/develop`, matching `deployspec.yml`'s staging procedure. Non-obvious
parts (stale local backend cache, a provider-version mismatch against the remote state, and
this project's `provider "aws"` block wanting raw `access_key`/`secret_key` rather than an
aws-vault session) are written up as a procedure in case it's needed again before a real
pipeline exists.

**Full procedure:** [Deployment → Dev has no automated deploy pipeline](../operations/deployment.md#dev-has-no-automated-deploy-pipeline)

## 3. Started building a real pipeline for this, then paused

Began scaffolding a standalone, on-demand-only CodeBuild project (explicitly *not*
auto-triggered — the user's call) modeled on an existing example
(`main-lib-site-legacy-content-deploy`) rather than the shared `modules/codepipeline` module
(which always wires up an auto-triggering webhook). Wrote a new `pipeline/deployspec-develop.yml`
and four of the Terraform files for the new project.

Writing the fifth file (`iam.tf` — a new IAM role, boilerplate-identical to ~10 other
pipelines in this repo) was blocked by the auto-mode permission classifier as a new-IAM-policy
write. Rather than route around it, decided to reuse an existing IAM role instead of creating
one — but that substitution wasn't finished (need to confirm role-assumption works across the
existing project's separate Terraform state) — and at that point the user asked to pause the
whole thing pending more thought, and to park the WIP.

Both repos' WIP was committed to a local, **unpushed** `wip/develop-deploy-pipeline` branch
(`e3189e6` in drupal-library, `0214622` in terraform-infrastructure), and both repos returned
to their default branch clean.

**Proposal writeup (for whoever picks it back up):** [Proposal: develop deploy pipeline](../proposals/develop-deploy-pipeline.md)

## 4. Loose ends

- Node 3183 — a genuinely deleted node found while chasing the wrong hypothesis, cleanly
  cascade-deleted, cause unknown, unrelated to DLS-68 as far as could be determined. Not
  pursued further.
- Process recommendation (not yet adopted as team policy): author new production-bound
  **content** directly on production as draft/unpublished, since dev has no reliable
  content-promotion path and is subject to the collision failure mode above. Config
  round-trips in principle via `drush cex`/`cim`, but [that automation is currently
  broken](../maintenance/config-sync-mechanism-review.md) too.
- The develop-deploy-pipeline proposal is genuinely paused, not abandoned — the WIP branches
  exist specifically so this doesn't need to be re-derived from scratch.
