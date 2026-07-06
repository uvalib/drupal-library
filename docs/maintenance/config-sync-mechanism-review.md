---
status: proposed
opened: 2026-06-18
jira: null
---

# Config-Sync Mechanism — Review & Redesign Brief

!!! warning "Status: needs review / redesign — not yet decided (flagged 2026-06-18)"
    The automated export of Drupal config to the `drupal-library-config-sync` repo
    **works today but is an un-codified "hack-to-fix-later."** This brief documents how
    it actually works, where the pieces come from, why it needs rethinking, and the open
    decisions. No redesign has been chosen yet — when one is, it should be recorded as an
    [ADR](../adr/README.md).

## How it works today

- The config-sync git checkout lives at **`/opt/drupal/config/sync`** in the container
  = **`/mnt/data/drupal-0/config/sync`** on the host (bind-mounted, persistent).
  `origin = git@github.com:uvalib/drupal-library-config-sync.git`, checked out on the
  **`production`** branch.
- The automation is **two entries in the host root crontab** (prod):

  ```cron
  0  */2 * * *  docker exec drupal-0 drush cex --commit --message="Automatic Commit `date`"
  15 */2 * * *  docker exec --workdir=/opt/drupal/config/sync drupal-0 git push -q
  ```

  Every 2 hours: export + git-commit *inside* the container; 15 minutes later: push to
  `origin/production`.
- Commit identity = the container's git user (`ys2n`). Push auth = an SSH deploy key
  inside the container.
- The repo has **per-environment branches**: `development`, `staging`, `production`.
  `production` is the live baseline; **`main` is stale (Nov 2024) and is not deployed.**

## Where the pieces come from (provenance)

| Piece | Set up by |
|-------|-----------|
| `/opt/drupal/util/drupal-library` clone + the `config`/`patches`/`vendor-archive` symlinks | **CodeBuild → `package/Dockerfile`** (lines 42–45) |
| Host dir `/mnt/data/drupal-0/config`, SAML config, bind-mount into the container | **Ansible → `deploy_backend.yml`** |
| The `drupal-library-config-sync` checkout (clone, remote, branch), the SSH deploy key, and the **cron jobs** | **Neither — manual host state in `/mnt/data`** |

The image's `/opt/drupal/config` is a *symlink* into the in-image clone (whose
`config/sync` is just the `read.me` placeholder); at run time the Ansible bind-mount
shadows it with the persistent host directory that holds the real git checkout.

## Why it needs a redesign (not just a repair)

1. **Not in version control.** The checkout + cron are manual host state in `/mnt/data`.
   They survive container redeploys but a **host rebuild with fresh `/mnt/data` loses the
   entire mechanism**, with nothing in code to recreate it. This is the deepest fragility.
2. **Blind periodic auto-commit.** `drush cex --commit` every 2h snapshots whatever prod's
   config currently is — no review — so any admin's UI drift gets committed automatically.
3. **`main` rots.** Only `production` is pushed; the default branch is never updated, so
   anyone cloning the default branch gets badly stale config (this caused real confusion).
4. **Split cron.** `cex` and `push` are separate crontab lines 15 min apart — an
   ordering/race assumption rather than one atomic operation.
5. **Misconfigured `safe.directory`.** Git's global `safe.directory` points at the
   vestigial `util/` checkout path, not the real repo path — a prime suspect for the
   "dubious ownership" breakage when cron runs git.
6. **Two git identities.** Host root (`Yuji on library prod`) vs container (`ys2n`).
7. **Conflated directory.** The config dir mixes the git-tracked `sync/` (Drupal config)
   with SimpleSAMLphp config in the parent dir, plus stale `sync-2022-*` leftover copies.
   (SAML config lives in the parent, not in `sync/`, so SAML secrets are *not* committed —
   but the layout is messy.)

## Interim fix applied 2026-06-18 (stopgap, not a solution)

Setting git config + adjusting `safe.directory` (see `~ys2n/fix-environment.sh` on the
host) got `production` committing again. This only treats the symptom.

## Redesign — open decisions

1. **Where it should live** — codify the checkout + scheduled export (and git identity,
   `safe.directory`, deploy key) into **Ansible** (or a separate scheduled/CI job) so a
   host rebuild recreates it from code.
2. **Which way config flows:**
    - **(a) Keep prod→repo auto-snapshot**, but codified + reviewed (export to an env
      branch with a reconcile/PR step). Keeps the current editor workflow.
    - **(b) Repo as source of truth (GitOps)** — config changes go through the repo +
      deploy (`drush cim`); prod→repo becomes drift *detection/alerting* only.
3. **Key dependency:** do site editors routinely change configuration via the **prod UI**?
   If yes, pure GitOps (b) is impractical without a workflow change; if changes are rare
   and admin-only, (b) becomes feasible.

## Next steps

- Decide direction (above). Capture the decision as an [ADR](../adr/README.md).
- Implement (likely in `terraform-infrastructure/library.virginia.edu/.../ansible`), and
  remove the dependence on manual host state.
- This is also the prerequisite for trustworthy `drush config:status` drift detection in
  any [validation framework](README.md).
