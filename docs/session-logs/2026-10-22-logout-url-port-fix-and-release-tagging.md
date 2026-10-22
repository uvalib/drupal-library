# Session Log: Logout redirect to `https://host:80/` — hot fix, pairing git and docker tags, and a path-filtered pipeline trigger

**Date:** 2026-10-22 (about 10:55 ET to just after noon; times approximate)
**Participants:** Yuji Shinozaki, Claude (Sonnet 5.5), working from the `drupal-library` repo
**Outcome:** Clicking **Log Out** no longer lands on `https://library.virginia.edu:80/`. The fix was
hand-applied to staging and **both production nodes** (logout confirmed working on staging and prod),
then merged as #16 (`417b835`) and built as `drupal-library:build-20261022153610`. Git tag
`production_2026_10_22` was cut on `417b835` and **agrees** with that image. **Production has not
been rolled to that image**; it still runs `build-20261022134259` plus the hand-edit.
Develop was not checked.

The staging pipeline's trigger was also changed so **docs-only merges to `main` no longer build
and deploy** (`trigger_paths`, applied to AWS; see section 7). That is verified in the live pipeline
definition but **not yet proven by a real docs-only merge**.

Session also began with "pull and update" (see section 5).

---

## 1. Cause

The bad URL was not the logout link (`/user/logout` redirects correctly) but the place
SimpleSAMLphp sends the user *after* logout.

- `logout_goto_url` is empty on staging, so `simplesamlphp_auth_user_logout()` calls
  `Auth\Simple::logout(null)`.
- With no return URL, SimpleSAMLphp builds one with `Utils\HTTP::getSelfURL()`, whose
  `getServerPort()` reads `$_SERVER['SERVER_PORT']` and appends `:<port>` unless it equals the
  default for the scheme.
- HTTPS terminates at the ALB, so `SERVER_PORT` is **80** inside the container, while the Apache
  vhost sets `HTTPS=on` from `X-Forwarded-Proto`. Scheme https + port 80 gives `https://host:80/`.

**Which container.** The user asked whether this was the NetBadge container or Drupal. The
SimpleSAMLphp library *code* runs in the **Drupal** container (`docker exec drupal-0` shows its own
`/var/simplesamlphp/config`); `netbadge-0` only receives the `ReturnTo` that Drupal already got wrong.
"NetBadge" is SimpleSAMLphp, so it is fair to call this a NetBadge-stack limitation, but redeploying
the `netbadge-0` image would not change it. I did not rule out that the `sp` container has its own
`:80` problem on its logout endpoint; staging and prod behaved correctly after the Drupal-side fix, so
it evidently does not matter.

Affects every environment behind the ALB. The user reported prod and staging; develop was not checked.

## 2. Options weighed

| Option | Verdict |
|---|---|
| `drush cset simplesamlphp_auth.settings logout_goto_url https://<host>/` | No redeploy, one command for both prod nodes (config is in the DB), trivially revertable. But hardcodes a URL per environment, and develop's weekly prod DB clone would carry prod's URL into develop. Not applied. |
| Fix in the NetBadge container | Rejected: the wrong value is made in Drupal's process, so the NetBadge side would be rewriting it. |
| Hand-edit `settings.php` in the container, then redeploy later | **Chosen** (the user wanted no redeploy right now). Environment-agnostic; the same code ships in the image. Risk is a syntax error taking the site down, mitigated by backup and `php -l`. |

## 3. The fix

`package/data/opt/drupal/web/sites/default/settings.php`, +13 lines after the `reverse_proxy_*`
settings:

```php
if (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
  && \Symfony\Component\HttpFoundation\IpUtils::checkIp($_SERVER['REMOTE_ADDR'] ?? '', $settings['reverse_proxy_addresses'])) {
  $_SERVER['SERVER_PORT'] = '443';
}
```

**Design point, from the user:** "I shouldn't use `$_SERVER['SERVER_PORT']` blindly." The first
version trusted `X-Forwarded-Proto` unconditionally. It was tightened to also require `REMOTE_ADDR` to be
inside `reverse_proxy_addresses` (the VPC CIDRs), so a direct hit on `:8080` from the VPN cannot spoof
it. Only generated URLs are affected either way, so the risk was low, but the check is cheap.

## 4. Rollout

Each node: verified the live `settings.php` matched the repo `HEAD` version (md5), copied the new file
in as `settings.php.new`, `php -l`, backed up as `settings.php.bak-logout-port-20261022`, `mv` into
place preserving ownership/mode. Opcache has `validate_timestamps=1`, `revalidate_freq=60`, so no
Apache restart. Staging first (user tested logout: works), then prod node 0 (health check), then node 1.
The user confirmed logout works on prod.

Shipped as PR #16 (merged by a teammate at 11:35 ET). The merge replaced the hand-edit on staging with
the identical file; the backup files disappeared with the recreated container.

## 5. Pull and update (start of the session)

`git pull --ff-only` took `main` to `d603eb9` (the PHP 8.4.27 / core 10.6.19 rollout). `ddev composer
install` then failed twice with `failed to CreateOrResumeMutagenSync`; `ddev mutagen reset` plus
`ddev start` fixed it, after which `composer install`, `drush updb` (nothing pending) and `drush cr`
succeeded. `core:requirements` still shows stale local entity-definition mismatches and cron not run
for months, which is normal for the idle local DB.

Gotchas: `drush updb -y` auto-answers "yes" to a failing requirements check, so read it rather than
trusting `-y`; `drush core:requirements --severity=2` crashes on a colour bug, use `--format=json`.

## 6. Tagging: git and docker did not agree

The user asked for "a new production tag". Findings:

- The newest git tag was `production_2026_06_04` (`a455c54`), **71 commits behind** prod. Git tagging
  had lapsed; releases were being tracked only by the ECR image tag.
- Prod ran `build-20261022134259`. ECR shows it carries `gitcommit-bc73b101…`, i.e. PR #14. The build
  started 12 s after #14 merged, which is how it was inferred before ECR was checked.
- The `release` branch is at `4079ce3` (July). **Images are built from `main`**, not from `release`, so
  `CLAUDE.md`'s "main -> release via PR" no longer matches reality. The user left it as an open question
  whether to use `release` at all. `CLAUDE.md` was **not** edited.
- `build-20261022153610` carries `gitcommit-417b8350…`, so the git tag went on `417b835`.

**Only functional diff between prod's running commit (`bc73b10`) and `417b835`:** the 13 lines in
`settings.php`. The other changed files are docs. Also confirmed live: `composer.json`, `composer.lock`,
`php.ini` and the Apache vhost on both prod nodes match the repo. (A first "mismatch" on
`php.ini-production` was my error: the Dockerfile copies that file to `php.ini`.)

Created and pushed annotated tag **`production_2026_10_22`** on `417b835`; the message records the
docker tag, the `gitcommit-` tag, the previous prod image and the delta.

**Standard decided by the user** (saved to memory): every production release gets both a git tag and a
docker tag that agree; read the commit from ECR's `gitcommit-<sha>` tag rather than inferring it; for a
second tag on the same day, add a **letter** suffix (`_b`, `_c`, …) rather than a number, so it cannot
be mistaken for a time. The first tag of the day stays unsuffixed.

AWS access: `aws-vault exec staging --prompt=osascript -- aws ecr describe-images …` (the user
authorised it; read-only calls only).

## 7. Stop deploying on docs-only merges (`trigger_paths`)

The user's point: the deploy trigger should treat document updates as exceptions, as was done for
`mandala-navina` (its pipeline got `trigger_paths` on 2026-07-16 and a docs-only merge then left the
pipeline idle). Before this, every push to `main`, including docs PRs like #15, rebuilt the image and
redeployed staging.

**Where it lives.** Not in this repo: `terraform-infrastructure/aws_cicd/pipelines/drupal-library/codepipeline.tf`
(the staging pipeline `uva-drupal-library-codepipeline`). It set no `trigger_paths`, so the module's
optional `trigger` block was never emitted. Two sibling pipelines exist: `drupal-library-release`
(watches `release`, now stale) and `drupal-library-production-deploy` (deploy phase only, `source_branch`
default `main`); neither was changed, and it was **not checked whether the production-deploy
pipeline has recent executions**.

**Change** (+15 lines incl. comment, commit `f49d1e5a8` pushed straight to `master`):

```hcl
source_artifact_format = "CODEBUILD_CLONE_REF"
trigger_paths = [
  "package/**",
  "pipeline/**",
]
```

`CODEBUILD_CLONE_REF` is required by the path trigger (same pattern as mandala-drupal, cs-proxy,
web-components, cdn-reporter). The paths are what feeds the image: `package/Dockerfile` copies only from
`package/`, and `pipeline/` holds the buildspec and deployspec. `docs/`, `scripts/`, `mkdocs/` and
`README.md` never reach the image.

**Gotcha.** The rebuilt devops-docs site lives under
`package/data/opt/drupal/web/modules/custom/devops_docs/static/`, and the Dockerfile `git clone`s this whole
repo at build time and symlinks that module. So regenerating the static site **still triggers** a deploy
(which is what publishes `/devops-docs`), while markdown-only `docs/` changes do not.

**Process.**
- `terraform-infrastructure` changes often and the user's checkout was parked on someone's feature branch
  (`dsf-production-enable-matomo`). So: fetch first, branch off `origin/master`, commit, push `HEAD:master`
  (the user said that repo does not use PRs for small changes), switch back and delete the temporary branch.
- The edit is outside the `library.virginia.edu/` subdirectory that was the previous edit scope; the user
  granted permission for this.
- Plan and apply ran from a temporary worktree at `origin/master`, with `aws-vault exec staging
  --prompt=osascript`. `main.tf` passes `access_key`/`secret_key` with no session token, so the aws-vault
  temporary credentials failed with `InvalidClientTokenId`; setting `TF_VAR_access_key=""` and
  `TF_VAR_secret_key=""` made the provider use the normal credential chain. State is S3
  (`uvalib-terraform-state`).
- First plan (read-only, `-lock=false`): 0 to add, 1 to change, 0 to destroy. The user then asked for
  `plan -out` plus apply; the script applied only if the new plan was exactly that single in-place change
  to `module.codepipeline.aws_codepipeline.codepipeline`. It was, and
  `Apply complete! Resources: 0 added, 1 changed, 0 destroyed.`

**Verified live** (`aws codepipeline get-pipeline`): trigger on branch `main`, file paths `package/**` and
`pipeline/**`, source `OutputArtifactFormat` is `CODEBUILD_CLONE_REF`. No extra execution fired from the
apply (newest remained the 11:35 ET run for `417b835`).

**Effect on tagging.** With docs merges no longer building, `main`'s head can be newer than the commit in
the latest image. The tagging standard already says to tag the commit in the image's `gitcommit-<sha>`, not
`main`'s head, so it holds, and it will now more often be that the two differ.

## 8. Open items

- **Prove the trigger filter:** merge a markdown-only docs PR to `main` and confirm
  `aws codepipeline list-pipeline-executions --pipeline-name uva-drupal-library-codepipeline` shows no new
  execution. Merging the PR for this session log is a candidate.
- **Check whether `drupal-library-production-deploy` is active** and whether it should be filtered too.
- **Roll prod to `build-20261022153610`** per `docs/operations/production-deploy-runbook.md` (code-only
  swap, so skip `drush cr`). The hand-edit is then replaced by the identical file from the image.
- **Check develop** for the same bug. It has no auto-deploy; needs a manual deploy.
- **Decide whether `release` is used at all**, then fix `CLAUDE.md` and the docs accordingly. `CLAUDE.md`
  also still describes deploys without mentioning the new path filter; not edited this session.
- Consider setting `logout_goto_url` explicitly as belt-and-braces: not needed now.
- Not tested: a NetBadge login on develop; the SAML logout against a user with an active IdP session
  beyond the simple staging/prod click-through.
