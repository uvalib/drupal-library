# Prod: persist the config-sync push key

Status: **prepared, not applied.** Nothing in this document has been run on prod. Apply it before the production
release (see [release plan](prod-release-plan-2026-11.md), section 3a and Phase 1 step 11).

## Problem

Prod node 0 exports config every 2 hours (`drush cex --commit` at :00 UTC) and pushes at :15 UTC
(`git push -q` in `/opt/drupal/config/sync`) to `uvalib/drupal-library-config-sync`, branch `production`.
The push authenticates with `/root/.ssh/id_ed25519`, which is **a personal key copied in on 2026-11-16** as a
stopgap. `/root/.ssh` lives in the container's writable layer, so the release (which recreates the container) wipes
it, and from then on pushes fail silently (155 commits piled up this way before 2026-11-16).

## What was checked on prod node 0 (read-only, 2026-11-20)

| Item | Finding | Consequence |
|---|---|---|
| Push key | `/root/.ssh/id_ed25519` is `ys2n@virginia.edu`, mtime 2024, uid 1110 (copied in); `known_hosts` created 2026-11-16 | Both are wiped by a recreate. |
| Git identity | `/etc/gitconfig` **in the image** sets `user.name`/`user.email` and `safe.directory` | Commits keep working after a recreate. Only the push breaks. |
| Repo location | `/opt/drupal/config` is a symlink to `/opt/drupal/util/drupal-library/package/data/opt/drupal/config`, and the bind mount `/mnt/data/drupal-0/config` covers it | `config/sync/.git` (including `.git/config`) is on the shared NFS mount and survives a recreate. |
| `.git/config` | No `core.sshCommand`, no `GIT_SSH_COMMAND` in the container env | The key path is implicit (`~/.ssh`). |
| Mount | `10.130.113.73:/` NFS4 on both nodes; files created by root stay root-owned (no root squash) | A `0600` root-owned key file works. Node 1 can read the same path but has no crontab, so it never pushes. |
| Cron | Host root crontab: `docker exec ... drush cex --commit` and `docker exec --workdir=/opt/drupal/config/sync drupal-0 git push -q` | The push runs as root in `config/sync`, so it reads the repo-local `.git/config`. |

## Design

Keep everything the push needs inside the directory that is already bind-mounted, and point git at it from the repo's
own config. No image, Ansible or crontab change.

```
/mnt/data/drupal-0/config/            (host)  =  /opt/drupal/config   (container)
  sync/                               the git checkout (do not put the key here)
    .git/config                       gets core.sshCommand  (persists)
  .push-key/                          new, root:root 0700
    id_ed25519                        dedicated deploy key, 0600
    id_ed25519.pub
    known_hosts                       GitHub host keys from api.github.com/meta
```

`core.sshCommand` is set in `config/sync/.git/config`:

```
ssh -i /opt/drupal/config/.push-key/id_ed25519 -o IdentitiesOnly=yes \
    -o UserKnownHostsFile=/opt/drupal/config/.push-key/known_hosts -o StrictHostKeyChecking=yes
```

With `IdentitiesOnly`, the personal key is never offered, so the stopgap key can be removed without a behavior change.

Why a **dedicated deploy key** and not the personal key: the personal key carries that person's access to every
repository they can reach, and it would sit on shared NFS. A deploy key is limited to this one repository.

## Steps

Needs: UVA VPN, SSH to prod node 0, and **admin on `uvalib/drupal-library-config-sync`** for step 2 (adding a deploy
key is a GitHub-side change that only a repo admin can make).

All of this is on **node 0 only**. Node 1 shares the directory through NFS. It needs no change and has no crontab.

1. **Generate the key in the persisted directory.**

   ```bash
   ssh library-drupal-0.internal.lib.virginia.edu 'sudo docker exec drupal-0 sh -c "
     umask 077
     mkdir -p /opt/drupal/config/.push-key
     ssh-keygen -t ed25519 -N \"\" -C \"drupal-library prod config-sync push\" -f /opt/drupal/config/.push-key/id_ed25519
     cat /opt/drupal/config/.push-key/id_ed25519.pub"'
   ```

   Only the `.pub` line is printed. The private key never leaves the host.

2. **Register the public key** as a **deploy key with "Allow write access"** on
   `uvalib/drupal-library-config-sync` (repo Settings, Deploy keys). Title it, for example,
   `prod config-sync push (library-drupal-0)`. A repo admin does this.

3. **Pin GitHub's host keys** from GitHub's published list, not `ssh-keyscan` (which trusts whatever answers first):

   ```bash
   gh api meta --jq '.ssh_keys[] | "github.com " + .' \
     | ssh library-drupal-0.internal.lib.virginia.edu \
         'sudo docker exec -i drupal-0 sh -c "cat > /opt/drupal/config/.push-key/known_hosts && chmod 600 /opt/drupal/config/.push-key/known_hosts"'
   ```

4. **Point the repo at it** (writes to the persisted `.git/config`):

   ```bash
   ssh library-drupal-0.internal.lib.virginia.edu 'sudo docker exec --workdir=/opt/drupal/config/sync drupal-0 \
     git config core.sshCommand "ssh -i /opt/drupal/config/.push-key/id_ed25519 -o IdentitiesOnly=yes -o UserKnownHostsFile=/opt/drupal/config/.push-key/known_hosts -o StrictHostKeyChecking=yes"'
   ```

5. **Test read, then write.**

   ```bash
   # read: must list the production branch
   ssh library-drupal-0.internal.lib.virginia.edu 'sudo docker exec --workdir=/opt/drupal/config/sync drupal-0 git ls-remote origin production'
   # write: push and delete a throwaway branch (a dry run does not prove write access)
   ssh library-drupal-0.internal.lib.virginia.edu 'sudo docker exec --workdir=/opt/drupal/config/sync drupal-0 sh -c "
     git push origin HEAD:refs/heads/push-key-test && git push origin --delete push-key-test"'
   ```

   The write test creates and removes one branch on the remote. Get the repo owner's OK first.

6. **Retire the stopgap.** After step 5 passes, remove the personal key and known_hosts from the container:
   `sudo docker exec drupal-0 rm -f /root/.ssh/id_ed25519 /root/.ssh/id_ed25519.pub /root/.ssh/known_hosts`. Then
   confirm the next scheduled push (:15 UTC on an even hour) still lands: the `Automatic Commit` appears on
   `origin/production` and `git status -sb` is not "ahead".

## Verify after the release

The release recreates the container, so repeat the checks on node 0:

- `sudo docker exec drupal-0 ls -la /opt/drupal/config/.push-key` shows the three files (`id_ed25519` is `-rw-------`).
- `sudo docker exec --workdir=/opt/drupal/config/sync drupal-0 git config core.sshCommand` prints the command above.
- `git ls-remote origin production` works from inside the container with an **empty `/root/.ssh`**.
- After the first even-hour export plus the :15 push, `git status -sb` shows `## production...origin/production` with
  no "ahead", and the new `Automatic Commit` is on GitHub.

If a recreate ever does lose it, only steps 3 and 4 need repeating if `.push-key` is gone; the key is reusable if the
files remain.

## Rollback

`git config --unset core.sshCommand` in `/opt/drupal/config/sync`, restore a personal key to `/root/.ssh`, and delete
the deploy key on GitHub. Nothing else changes.

## Risks and notes

- **Shared NFS.** Any host that mounts the export as root can read the private key. The mount is `sec=sys`; root on
  node 1 sees it. Both nodes are ours, but treat the key as shared by both.
- **Same directory, other mount.** `/mnt/data/drupal-0/config` is also bind-mounted at `/var/simplesamlphp/config`
  in the same container (SimpleSAMLphp config). `.push-key` shows up there too. That path is outside Apache's
  `DocumentRoot` (`/var/www/html`) and the vhost has no `Alias` to it (`/simplesaml/` is proxied to the separate
  `sp` container, which mounts `/mnt/data/netbadge-0`), so it is not web-served. It is still one more reason to keep
  the mode at `0700`/`0600`.
- **Scope.** A write deploy key can push to any branch of that one repository, including `production`. That is what
  the automation does today. Branch protection on `production` would need to allow this key.
- **Silent failure remains.** The push runs with `-q`; a failure only shows up as cron mail to the `MAILTO` address.
  Consider a check that alerts when `git status -sb` has been "ahead" for more than a few hours (not done).
- **Personal identity in the image.** `/etc/gitconfig` bakes `ys2n` in as the committer. It works, but a service
  identity would be cleaner. Not needed for the release.
