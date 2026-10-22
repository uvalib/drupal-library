# Session Log: PHP2Shell (CVE-2026-109618 / 109619) response

**Date:** 2026-10-21 to 2026-10-22
**Participants:** Yuji Shinozaki, Claude (Sonnet 5.5)
> **UPDATE 2026-10-22: PR #12 is SUPERSEDED by PR #14 — do not merge #12.** The official image on
> Docker Hub was already patched: `docker.io/library/drupal:10.6.19-php8.4-apache` and
> `10.6-php8.4-apache` both carry PHP 8.4.27 (verified by pulling from docker.io). Our earlier
> "no official 8.4.27 image yet" finding was wrong: we pulled from the **ECR public mirror**
> (`public.ecr.aws/docker/library/...`), which lags Docker Hub, and `scripts/check-php-base-image.sh`
> reads that mirror, so it can say NOT YET while Docker Hub is fine. #14
> (`fix/php-8.4.27-drupal-10.6.19`, from the drupal-config-scan session) uses the docker.io image and
> also moves core 10.6.10 -> 10.6.19 (SA-CORE-2026-014) in composer.json/lock, which #12 would have
> left on 10.6.10 (core comes from the lock, not the base image). Not merged, nothing deployed.
> Next steps 2 and 3 below are replaced by #14; the check script's registry should be fixed.

**Outcome:** Assessed exposure (all environments vulnerable); merged interim hardening (#11) and a
pipeline fix (#13); prepared the real fix (#12, own PHP 8.4.27 base) but **not merged and nothing
fixed is running anywhere yet**. Handoff below.

## Exposure assessment

- Advisory: PHP2Shell, critical unauthenticated RCE in PHP core, fixed in **8.4.27** (8.3.36, 8.2.35).
  Source: `drupal-config-scan/advisories.toml`.
- Staging, dev, prod-0, prod-1 all ran `build-20260820134155` on **PHP 8.4.22** (checked with
  `docker exec drupal-0 php -r`). Base was pinned `drupal:10.6.10` (built 2026-06-17).
- The official `drupal:10.6-php8.4-apache` was last rebuilt 2026-10-21 17:44 UTC and is still on
  **PHP 8.4.25**; the `drupal` Dockerfile on GitHub master already says Drupal 10.6.20 but the
  images had not been rebuilt (docker-library queue lag, no ETA). `php:8.4.27-apache-bookworm`
  was published 2026-10-21 22:09 UTC.

## Changes

| PR | State | What |
|----|-------|------|
| #11 | merged `bca4701` | `session.upload_progress.enabled = Off` in `php.ini-production` (interim, unverified mitigation; upload limits unchanged at 25M/20M); `RUN php -v \| head -1` in the Dockerfile; `scripts/check-php-base-image.sh` (defaults: `drupal:10.6-php8.4-apache`, min 8.4.27) |
| #13 | merged `29d1aee` | `deployspec.yml`: `python: 3.14`, `pip3 install ansible==14.3.1 ansible-core==2.21.3` |
| #12 | **open, not merged** | `FROM php:8.4.27-apache-bookworm` with the official drupal image's steps reproduced (gd/pdo_mysql/pdo_pgsql/zip, opcache + output_buffering ini, composer, `create-project` at 10.6.10 to match `composer.lock`). Rebased onto #11. Revert to `FROM drupal:10.6-php8.4-apache` once the check script exits 0 |

Local verification of #12's image: PHP 8.4.27; `php -m` identical (44 modules) to the current
image; opcache/output_buffering match; Drush 12.5.3, core 10.6.10; Apache serves a static file
(200). **Not tested:** a Drupal page, the database, SAML.

## Deploy failures after merging #11

1. **ansible/Python (fixed by #13).** Staging deploy of `bca4701` failed in
   `ansible-playbook deploy_netbadge.yml` with `AttributeError: module 'dataclasses' has no
   attribute '_is_type'`. Cause (from the CodeBuild log, not reproduced): `runtime-versions:
   python: 3.x` resolved to Python 3.15.0 and unpinned pip pulled ansible-core 2.21.5. Last good
   deploy (2026-08-20) used Python 3.14.3 / ansible 14.3.1 / ansible-core 2.21.3. Other repos'
   pipelines with `python: 3.x` + unpinned ansible may break the same way (not checked).
2. **Staging instance stopped (open).** With #13 the deploy got through 19 tasks, then
   `uva-library-drupal-staging-0` (10.130.109.122, `i-014f04e29e08ab37f`) was unreachable: EC2
   state `stopped`, "User initiated shutdown" at 2026-10-22 03:00:55 GMT. It was running again
   (LaunchTime 10:01:06 UTC), so it looks like a **nightly stop ~03:00 UTC / start ~10:00 UTC**
   (~23:00–06:00 ET). Unconfirmed: nobody has identified the schedule. Deploys triggered in that
   window will fail at the SSH step.

## State at handoff (2026-10-22 ~13:20 UTC)

- `main` = `29d1aee`. Latest staging deploy (`29d1aee`) **FAILED** (host was down). Staging is
  still on `build-20260820134155` / PHP 8.4.22, with neither #11's ini change nor anything newer.
  The #11/#13 images are built and in ECR.
- Dev, prod-0, prod-1: not touched, still PHP 8.4.22. Prod deploys via the release branch.
- My SSH to the hosts timed out (possibly VPN on my side); host state was read via AWS APIs.
- The local working tree had unrelated uncommitted docs changes (the user's); not touched.

## Next steps

1. Re-run `uva-drupal-library-project-deploy` for `29d1aee` now that staging is up (or push-triggered
   rebuild). Verify on `library-drupal-staging-0`: `php -v`, `php -r 'var_dump(ini_get("session.upload_progress.enabled"));'`
   (expect empty/off), and a page load. Known: staging may WSOD at `/admin/modules` (CKEditor ghost
   module on the prod-snapshot DB; see memory/staging main-push note).
2. Decide on and merge #12 (own 8.4.27 base). It is the actual fix. Staging is the proof; then promote
   to prod via `release`, check **both** prod nodes, and dev manually (dev has no auto-deploy).
3. When `./scripts/check-php-base-image.sh --verify` exits 0, switch back to the official `drupal` image
   and remove the `php.ini` upload-progress override.
4. Ask whoever owns infra about WAF/ALB mitigation for prod in the meantime, and confirm the nightly
   staging stop schedule.
5. Check other pipelines (this org) for the unpinned `python: 3.x` + `pip3 install ansible` pattern.
