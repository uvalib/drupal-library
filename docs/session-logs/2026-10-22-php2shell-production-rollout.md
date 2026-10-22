# Session Log: PHP2Shell / core 10.6.19 — rollout to develop, staging and production

**Date:** 2026-10-22 (about 09:15–10:40 ET)
**Participants:** Yuji Shinozaki, Claude (Sonnet 5.5), working from the `drupal-config-scan` repo
**Outcome:** Library develop, staging and **both production nodes** now run
`drupal-library:build-20261022134259` (**PHP 8.4.27**, **Drupal core 10.6.19**) and
`drupal-netbadge:build-20261022034941` (PHP 8.4.27). Merged as #14; #12 closed as superseded.
The `drush cr` was deliberately skipped. A NetBadge login on production has **not** been tested.

Continues the 2026-10-21 PHP2Shell handoff (`docs/session-logs/2026-10-21-php2shell-response.md`,
on the `docs/session-log-2026-10-21-php2shell` branch; link it here once that branch is merged).

---

## 1. Why #12 was replaced by #14

#12 built on `php:8.4.27-apache-bookworm` and reproduced the official image's steps, because the
official image was thought to be still on PHP 8.4.25. Reading the image configs straight from
Docker Hub (`docker buildx imagetools inspect docker.io/library/drupal:<tag> --format '{{json .Image}}'`,
no pull needed) showed otherwise:

| Docker Hub tag | PHP | Drupal | Built (UTC) |
|---|---|---|---|
| `drupal:10.6-php8.4-apache` | 8.4.27 | 10.6.20 | 2026-10-22 00:21 |
| `drupal:10.6.19-php8.4-apache` | 8.4.27 | 10.6.19 | 2026-10-21 17:07 |
| `drupal:10.6.10-php8.4-apache` (old base) | 8.4.22 | 10.6.10 | 2026-06-17 |

**The ECR public mirror lags Docker Hub**, and `scripts/check-php-base-image.sh` reads the mirror,
so it can report "NOT YET" while Docker Hub is already patched. The mirror had neither
`drupal:10.6.19` nor a PHP 8.4.27 image that morning.

Second problem with #12: **core comes from `composer.lock`, not from the base image** (the
Dockerfile runs `composer install --no-dev` from the lock), and #12 left core at 10.6.10, so it
would have fixed PHP but not SA-CORE-2026-014.

**#14** (`bc73b10`): `FROM docker.io/library/drupal:10.6.19-php8.4-apache`; `composer.json` pins for
`core-recommended`, `core-composer-scaffold`, `core-project-message` 10.6.10 → 10.6.19; lock updated
for `core` and its dependency set only (43 packages: symfony, twig, guzzle…). No contrib module
versions changed. The only composer patch is on a contrib module (`save_all_nodes`), not core.
**Switch back to the ECR mirror** once it has the tag.

Tested locally (linux/amd64) against the image then deployed (`build-20260820134155`): identical
`php -m` and Apache modules; packages differ only by those 39 core-set updates plus one new
`symfony/polyfill-php80`; throwaway site-install gave identical results (200 on `/`, `/user/login`,
`/robots.txt`, `drupal.js`; 404 on a missing page; no PHP errors; no pending updates).

## 2. Rollout

| Time (ET) | Step | Result |
|---|---|---|
| ~09:25 | netbadge `build-20261022034941` to develop (`deploy_netbadge.yml`, pinned tag) | ok |
| 09:42 | #14 merged; CodeBuild `bc73b10`, image `build-20261022134259` ready 09:45 | ok |
| 09:46–09:51 | staging auto-deployed by the pipeline (also took the new netbadge) | `php -v` 8.4.27, Drupal 10.6.19, 200s |
| ~09:55 | develop, `deploy_backend.yml -e deploy_tag=build-20261022134259` | 8.4.27 / 10.6.19, 200s, `updatedb:status` clean |
| 10:00 | production pre-flight (read-only) | see §3 |
| ~10:13 | node 1 drained | `unused / NotRegistered` |
| ~10:17–10:19 | node 1 backend + netbadge deployed | `failed=0` |
| ~10:19 | **maintenance mode found on** (set by the team); node 0 backend deployed **in place** at ~10:21 (not drained, see §4) | `failed=0` |
| ~10:23 | maintenance mode turned off on request | `maintenance_mode=0`, `/` 200 on both nodes |
| ~10:24 | node 1 added back | healthy |
| 10:25–10:33 | node 0 drained, netbadge deployed, added back | healthy |

Final state, checked directly on each node: `drupal-0` = `drupal-library:build-20261022134259`,
`netbadge-0` = `drupal-netbadge:build-20261022034941`, PHP 8.4.27, Drupal 10.6.19, theme
`prod-theme-20260519-netbadge-login`, no pending database updates, no PHP fatals, watchdog clean,
no 5xx in the access log, traffic split about 50/50 (≈2,000 non-health-check requests per node per
5 minutes). ALB target-group matchers were never changed (`200-299`).

**Rollback anchors** (what production ran before): `drupal-library:build-20260820134155`,
`drupal-netbadge:build-20260409195438`.

## 3. Findings worth keeping

- **The two production nodes ran different themes.** Node 1 was on the SSM-pinned tag
  `prod-theme-20260519-netbadge-login`; node 0 was on `adc08b4c` ("Yuji on
  uva-library-drupal-staging: Revert 'another round of updates (list config)'", 2025-11-11), a
  commit in no remote branch, 10 commits off the pin. `deploy_backend.yml` checks out the SSM pin,
  so the rollout converged node 0 onto the pin (confirmed as intended). Compare themes in the
  baseline; the runbook now says so.
- **The ALB will not accept 503 as a healthy code.** `modify-target-group --matcher
  '{"HttpCode":"200-299,503"}'` returns `ValidationError: … '503' must be within '200-499'
  inclusive`. The runbook's and the CKEditor runbook's "widen the matcher for the window" step is
  impossible, and so is approach 1 in the health-check proposal. (The unquoted `HttpCode=200-299,503`
  form fails earlier on CLI list parsing.) Nothing was changed on the target groups.
- **Maintenance mode empties the pool in about 4 minutes** (three failed checks at 120 s). It was
  found on at ~10:19 with node 0 still `healthy`. Turning it off restored `200` on both nodes.
- **`alb-state` needs a typed `yes`** and has no non-interactive flag; with no terminal it exits 1
  having changed nothing.
- **The aws-vault MFA session expired mid-rollout.** Commands began failing with
  `AccessDenied: MultiFactorAuthentication failed`. `--prompt=terminal` cannot work from a
  non-interactive shell; refresh in a separate terminal window.
- **The production playbook never runs `drush cr` or `cim`** (the `cr` task is commented out);
  develop's does, staging's pipeline does.
- **Staging is stopped nightly (~23:00–06:00 ET)**; a deploy in that window fails at the SSH step.
  Already noted in the 2026-10-21 log; confirmed by the instances being unreachable at midnight.

## 4. Decisions

- **Maintenance-mode path vs rolling:** the runbook prescribes maintenance mode for core updates,
  but that needs a matcher that cannot be set. The plan became: roll both nodes with the site live
  and no `drush cr`; then, optionally, one short (≈2 min) maintenance burst. Drupal's compiled
  container cache key includes the core version, so two nodes on 10.6.10 and 10.6.19 should not
  collide in the shared cache for a patch release; this was reasoned, not proved.
- **`drush cr` skipped** at the user's call. The shared database cache therefore still holds
  pre-update entries; the code is new. Read-only checks afterwards found no errors on either node.
  A bounded, trap-protected script exists (`drupal-config-scan/scripts/library-prod-cache-rebuild.sh`,
  dry-run by default; its `--run` path is untested) if a rebuild is wanted in a quiet window.
- **Node 0 deployed in place** because maintenance mode was already on (set by the team) and
  `alb-state` refuses to empty the pool.

## 5. Not done / follow-ups

1. **NetBadge login on production** and staging: not tested (needs a person). Only the SP metadata
   (HTTP 200) and container health were checked.
2. **Fix `scripts/check-php-base-image.sh`** to read Docker Hub (or take a registry argument), or
   move the Dockerfile back to the mirror once it catches up.
3. **Rebuild the devops-docs static site** (`package/data/.../devops_docs/static`); this change
   edits the markdown only.
4. **Health-check endpoint** ([proposal](../proposals/health-check-endpoint.md)): now the only
   way to keep nodes in rotation during maintenance.
5. **Production deploy hygiene:** capture and compare theme revisions per node before every deploy.
6. The 2026-10-21 handoff's items for **WAF/ALB mitigation** and checking other pipelines for the
   unpinned `python: 3.x` + `pip3 install ansible` pattern are unchanged.
