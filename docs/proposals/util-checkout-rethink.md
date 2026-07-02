# Proposal (stub): rethink the baked-in util checkout

**Status:** Proposal / discussion — nothing adopted. Captured 2026-07-02; recorded so it
persists.
**Related:** [Config-sync mechanism review](../maintenance/config-sync-mechanism-review.md)
(the misconfigured `safe.directory` points into this same util path),
[Container & build](../architecture/container-and-build.md).

## The finding

The production image `git clone`s the **entire `drupal-library` repo** into
`/opt/drupal/util/drupal-library` — **166 MB, of which `.git` alone is 90 MB** — purely to
provide four symlinks:

| Symlink | Used at | Notes |
|---|---|---|
| `config` → `util/.../config` | runtime | *Shadowed* by the host bind-mount of the real config-sync checkout; the util copy is a placeholder |
| `patches` → `util/local/ddev/patches` | **build time** | `composer install` patch paths |
| `vendor-archive` → `util/.../vendor-archive` | **build time** | `composer` path repo (`smartmenus-1.1.1.zip`) |
| `devops_docs` → `util/.../devops_docs` | runtime | the served module + `static/` artifacts |

Beyond those, the clone drags in `.git` (90 MB), `docs/` + `mkdocs/` source, `scripts/`,
`pipeline/`, `CLAUDE.md`, etc. — none of it needed at runtime. The util checkout is referenced
**only in `package/Dockerfile`** (nothing in `settings.php`, scripts, or Ansible depends on
it, aside from a *commented-out* `util` bind-mount).

Latent quirk: the `git clone` pulls the repo's **`main` HEAD at build time**, *not the commit
being built* — so the util contents can differ from the committed code in the image.

## The direction

The build context **is** the repo (`docker build … .`), so the clone can be replaced by
`COPY`ing just the paths that are actually used:

```dockerfile
COPY local/ddev/patches                                     /opt/drupal/patches
COPY package/data/opt/drupal/vendor-archive                 /opt/drupal/vendor-archive
COPY package/data/opt/drupal/config                         /opt/drupal/config
COPY package/data/opt/drupal/web/modules/custom/devops_docs /opt/drupal/web/modules/custom/devops_docs
```

This ships **only the artifacts** (no `.git`, no docs/mkdocs source), drops ~90 MB+, and fixes
the "clones `main`, not the built commit" quirk (COPY reflects the built commit).

## The one real tradeoff

The util checkout doubles as a **"live repo on the box"** convenience — editing / `git pull`ing
it to run ad-hoc `composer` on dev/staging. That convenience shouldn't be *baked into the
image*; it's exactly what the **commented-out `util` bind-mount** in the Ansible playbook is
for. Clean split: image ships only artifacts (`COPY`); dev/staging *optionally* bind-mount a
host checkout at `/opt/drupal/util` when ad-hoc composer work is wanted; prod never does.

## Open questions / dependencies

- Confirm nothing else quietly relies on the util `.git` (the config-sync auto-commit cron uses
  a *different*, bind-mounted checkout — verify).
- Sequencing: dovetails with the **config-sync mechanism redesign** (the misconfigured
  `safe.directory` references this util path). Best tackled together.
- Whether to keep `config` as a real placeholder dir or drop it (it's shadowed by the host
  mount at runtime anyway).
