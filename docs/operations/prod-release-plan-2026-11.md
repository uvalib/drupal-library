---
status: draft
opened: 2026-11-19
---

# Production release plan: security updates and the block guard (draft, Nov 2026)

Release-specific plan. The generic procedure, tools and hazards are in the
[Production Deploy Runbook](production-deploy-runbook.md); this page says what to ship, what to test first, and
which decisions are still open. Nothing here has been run on production.

## 1. Why release now

Prod runs the 2026-10-22 release (`build-20261022153610`, commit `417b835`, tag `production_2026_10_22`, core
10.6.19, PHP 8.4.27, theme `prod-theme-20261022-login-form-fixes`, same on both nodes). `main` has 26 commits
beyond it. The ones that matter:

| Module (enabled on prod) | Prod | `main` | Why it matters |
|---|---|---|---|
| `geolocation` (+ Google Maps, Places) | 3.14 | 3.15.0 | Critical SQL injection, SA-CONTRIB-2026-062 |
| `mathjax` | 4.1.0 | 4.1.2 | Critical cross-site scripting |
| `externalauth` | 2.0.11 | 2.0.15 | Access bypass on the NetBadge login path |
| `simplesamlphp` + `saml2` libraries | v2.4.5 | 2.4.11 | Advisories cleared (same major line, session format unchanged per the commit) |
| `webform` family | 6.2.10 | 6.3.1 | Advisories cleared |
| `scheduler` | 2.2.2 | 2.3.1 | Update |
| Drupal core | 10.6.19 | 10.6.21 | Core security release |

Per the commits, `composer audit` went from 71 advisories to 0 (also 0 with `--no-dev`); that was not re-run for
this plan.

Also in the release:

- Pre-Drupal 11 bumps: `scheduler_content_moderation_integration` 2.0.0 to 3.x (major), `exclude_node_title`
  1.4 to 2.0.1 (major; not found in prod's enabled list, so confirm it is used at all), `cache_utility`,
  `w3css_paragraphs`, `d8w3css`; `paragraphs` and `entity_reference_revisions` arrive as dependencies; unused
  packages and `uvalib/dml` removed; `custom_search` pinned to a `1.x-dev` commit with a one-line patch.
- `uvalib_block_guard` (ships disabled; enabling is a separate step in this plan).
- Docs and the `/devops-docs` static site.

Drupal 10 deadline context: the D11 plan has a 2026-12-09 target, so the release also clears the dependency
ground for that work.

## 2. Decisions needed before scheduling

1. **Go / no-go and window.** Date, time, who runs it, who tests NetBadge, who signs off.
2. **Cache rebuild method.** Core and module major bumps need a rebuild. Options in the runbook: roll the nodes
   without `drush cr`, then one `drush cr` at the end in a low-traffic window or as a short maintenance burst
   (see section 5). The burst must fit inside about 4 minutes, because maintenance mode returns 503 on `/` and
   the ALB health check cannot be widened to accept it.
3. **Enable the guard in this window?** User preference is to deploy the guard with this release. `drush en`
   itself rebuilds caches, so it must happen inside the same single rebuild window, never per node.
4. **View fix on prod.** Independent of the release: do it first (see
   [prod change](prod-change-home-news-empty-link-guard.md)) so the homepage is protected regardless of
   how the release goes.

## 3. Phase 0: staging QA (before tagging anything)

Staging runs core 10.6.21 and the `main` build, but its DB is a snapshot and these checks have not been done
for this release. Check each; record results in the PR or a session log.

| Area | Check |
|---|---|
| Pending updates | **Done 2026-11-19:** 16 pending on a prod-data staging (12 webform, `externalauth_update_8104`, two MathJax, `scheduler_content_moderation_integration_update_10301`); all ran clean in 28 s. See [section 3a](#3a-rehearsal-results-staging-2026-11-19). |
| Scheduler | Schedule a publish and an unpublish with content moderation, in Eastern time, and confirm they fire at the right time (scheduler 2.3.1 + `scheduler_content_moderation_integration` 3.x; this is the area of DLS-67 and the 11-18 incident). |
| Search | `/search/node` and `/website-search-results` return sensible results (`custom_search` dev pin + patch). |
| NetBadge | Real login and logout on staging (`externalauth` 2.0.15, SimpleSAMLphp 2.4.11). Logout bug history: 2026-10-22. |
| Title exclusion | If `exclude_node_title` is actually enabled anywhere, confirm node titles still hide/show correctly. |
| Webforms | Open and submit a representative webform; check the webform admin pages. |
| Maps | A page that uses geolocation / Google Maps renders (geolocation 3.15.0). |
| Homepage | Loads clean; nothing in watchdog beyond baseline. Includes the empty-link view fix already on staging. |
| Guard | Optional: enable on staging, confirm the homepage is unchanged, then decide whether to leave it on. |

Go criterion: all rows pass, or a failing row has an agreed workaround. A failing scheduler or NetBadge row blocks
the release.

## 3a. Rehearsal results (staging, 2026-11-19)

Staging was re-synced from prod with the TeamCity DB clone (DB state matched prod's pre-upgrade schema; the
dump was about a day old). Then: prod's current image (`build-20261022153610`) as a baseline, then the candidate
(`build-20261119144810`, commit `5a3e21a`) with the staging `deploy_backend.yml` and `drupal_cache_clear=false`,
then the manual steps below. Staging mail was diverted to core's test collector for the test.

| Step | Result | Time |
|---|---|---|
| Baseline deploy (prod image over prod data) | `failed=0`, homepage 200, core 10.6.19 | ~1.5 min |
| Uninstall `webform_toggles`, `webform_location_geocomplete` | success | 20 s |
| Candidate deploy, no `cr` | `failed=0`, homepage 200 before any rebuild | 80 s |
| `drush updb` (16 updates) | all ran, no errors | 28 s |
| `drush en uvalib_block_guard` | success | 16 s |
| `drush cr` | success; first homepage load 12.7 s, then 0.03 s | 21 s |

Checks after the rebuild: core 10.6.21, webform 6.3.1, scheduler 2.3.1, `scheduler_content_moderation_integration`
3.0.5; no pending updates; 13 admin pages rendered as uid 1 through the kernel all returned 200 with no error
messages (`/admin/modules`, the uninstall page, webform list and the webform using geocomplete, status report,
content, both homepage views, scheduler, search API, people); `/search/node` returns 200 with a body within 1 byte
of prod's; a scheduled test story published via `drush scheduler:cron` with 0 mails collected; no errors in watchdog.

### Required pre-release step: two modules lose their files in webform 6.3.1

`webform_toggles` and `webform_location_geocomplete` are **enabled on prod**, but the webform 6.3.1 tree has only a
leftover `js/` folder for each, with no `.info.yml`. Left enabled, they would be enabled-but-missing after the
release, the same shape as the CKEditor ghost module that made `/admin/modules` fatal. `webform_toggles` is not
used by any webform; `webform_location_geocomplete` appears in one config, `webform.webform.template_user_profile`.
Not verified: whether webform 6.3 moved them to separate projects; the control case (deploy the candidate with them
still enabled) was not run.

**Run on prod before the release**, while the old image still has their files: `drush pmu webform_toggles
webform_location_geocomplete -y`. It rebuilds caches by itself, so on prod do it as a single controlled step, not
per node: after both nodes are healthy on the old image and in a low-traffic window, or fold it into the rebuild
window with the first release pass. Open question: whether `template_user_profile` still works if its geocomplete
element is used (it rendered 200 on staging after the uninstall).

### Optional: stale `system.schema` entries

Both prod and staging have the same 8 stale entries (`gesso_helper`, `jquery_ui_effects`, `restui`,
`rules_api_post`, `rules_http_client`, `search_api_solr_admin`, `search_api_solr_devel`, `typed_data`); they only
produce noise on `updatedb:status`. Deleting them from the `system.schema` key/value store was tested on staging
(no side effects seen). Do it on prod only if wanted, as a separate, low-priority step.

### Scope of the empty-link view fix on prod

A read-only scan of all 137 views on prod and staging found exactly the 3 fields in the
[prod change runbook](prod-change-home-news-empty-link-guard.md) with a leading token followed by text. The other 14
link rewrites use a bare token, which core handles safely (an empty path returns the unlinked text,
`FieldPluginBase.php`). Of the three, only the two with the external flag throw; the internal one
(`home_news_featured` `title`) silently links to `/?mtm_...` instead.

### Mail safety on staging and develop

Staging's container can reach the real relay, and a prod DB clone resets mail to `php_mail`. PR #26 adds a
`settings.php` override keyed on `UVALIB_MAIL_DIVERT=1`, set only in the staging and develop `container.env` files
(already pushed to `terraform-infrastructure`). Take effect after #26 is deployed there; production never sets it.

## 4. Phase 1: prepare

1. **Pick the image.** Latest build of `main` on staging; read its `gitcommit-<sha>` tag from ECR, do not infer
   from timing (the pipeline only builds on `package/**` or `pipeline/**` changes, so `latest` can lag `main`).
2. **Git tag.** Annotated `production_YYYY_MM_DD` on exactly that commit. Check for same-day collisions
   (`git ls-remote --tags origin 'production_YYYY_MM_DD*'`); use `_b`, `_c`, a letter and never a number, or a
   descriptive suffix like `_security_patch`. Message records: docker tag, `gitcommit-` tag, previous prod image
   and commit (`build-20261022153610`, `417b835`), and the delta above. Push only the tag. Tagging deploys nothing.
3. **Theme.** No theme change planned. Confirm the SSM pin `/themes/uvalib/drupal-library/release` is still
   `prod-theme-20261022-login-form-fixes` and both prod nodes match it with a clean tree (checked 2026-11-19: the
   pin and prod node 0 matched; node 1 should be rechecked in the baseline).
4. **Database safety.** Confirm a recent prod DB backup exists before any `updb`. (Method not verified in this
   plan: RDS snapshot or the dump workaround in the RDS/TLS note; decide and record which.)
5. **Session.** Fresh `aws-vault` session in a separate terminal; on the VPN.
6. **Baseline (runbook step 0).** `alb-state drupal-prod` shows both nodes healthy; record running image,
   theme tag and maintenance-mode state on each node. These values are the rollback.
7. **Hand-applied changes.** Check for anything applied by hand to the running containers (none are known; the
   `settings.php` logout fix is in the 10-22 image). A rollback recreates containers from the old image.
8. **Announce** the window to editors; a story without a link is no longer an outage but renders unlinked.
9. **Uninstall the two webform submodules** (see [3a](#required-pre-release-step-two-modules-lose-their-files-in-webform-631))
   on the old image, once, before the new image goes on either node.
10. **Apply the view fix** from the [prod change runbook](prod-change-home-news-empty-link-guard.md) (3 fields) if it
    has not already been applied.

## 5. Phase 2 and 3: roll, then rebuild once

**Roll (runbook steps 1 to 5), node 1 first, then node 0. No `drush cr`, no maintenance mode.**
For each node: drain with `alb-state --remove ... --watch` (about 5 minutes), `deploy_backend.yml -e
deploy_tag=<tag> --limit <node>`, verify directly on `:8080` (200), re-add and wait for healthy (up to about 4
minutes). Whole rollout is roughly 20 to 25 minutes. Node 0 will carry little traffic for about 10 minutes after
re-add; that is the ramp-up, not a fault.

Between the two nodes, code on disk is new on one and old on the other while the DB and cache are shared. With
module major bumps this is the riskiest stretch: watch the watchdog and the ALB 5xx on the live node, and stop
the rollout (leave node 0 on the old image) if errors appear.

**Single rebuild window (runbook step 6), after both nodes are on the new image:**

1. `drush updatedb:status`; if updates are pending, run `drush updb -y`.
2. If the guard is going in: `drush en uvalib_block_guard -y`. This rebuilds caches by itself, so do it in
   this window, not earlier.
3. One `drush cr`, from one node (the cache is shared). Option: short maintenance burst. The 4-minute limit on
   maintenance mode is real; the bounded script mentioned in the runbook is untested, so a low-traffic window
   without maintenance mode is the safer default unless the staging QA shows a rebuild takes long.
4. Do not run `drush cr` per node, ever, while the other node serves traffic (2026-06-26 deadlock).

## 6. Verification (after the rebuild)

- `alb-state drupal-prod`: both nodes healthy; `curl` the public URL and each node on `:8080`: 200.
- `drush pm:list` now reports the new versions (not reliable before the rebuild).
- Homepage, a news story, a scheduled-content check (publish/unpublish on a test node scheduled a few minutes
  ahead, then removed), `/search/node`, one webform, one map page.
- NetBadge login and logout on production, tested by a person.
- Guard (if enabled): `drush pm:list` shows it enabled; watchdog has no `uvalib_block_guard` entries (any entry
  means a block is already failing). Do **not** create broken content on prod to test it.
- Compare the two nodes (runbook "After the rollout"): image, `settings.php` hash, theme tag, composer.lock.
- Watch CloudWatch for 30 minutes: healthy host count 2, target 5xx near zero.

## 7. Rollback

| Layer | Rollback |
|---|---|
| Code | Roll back per node to `build-20261022153610` with `deploy_backend.yml -e deploy_tag=build-20261022153610`, same drain/verify/re-add procedure. |
| Theme | Pin unchanged (`prod-theme-20261022-login-form-fixes`), so nothing to do unless it was touched. |
| DB updates | Not reversible. This is why the backup is a precondition. Updates from minor bumps are usually additive, but treat a restore as the only real rollback. |
| Guard | Disable with `drush pmu uvalib_block_guard`; it rebuilds caches, so do it in a controlled window. The view fix is a plain config revert (remove the `{% if %}` wrapper). |

A rolled-back node is back on core 10.6.19 and the old modules; the core and module security fixes in section 1
are then absent again, so a rollback is a temporary position, not a safe resting one.

## 8. Open and unverified

- Still to test on staging by a person: NetBadge login and logout, and a story scheduled in the admin UI publishing
  at the right Eastern time (the DLS-67 area; the command-line test does not cover it). Also the guard's behavior on
  a story with an image and no link, which is not covered by the view fix on staging right now (cleared by the clone).
- The TeamCity files sync did not complete and is being re-run; the file-level state of staging is not yet confirmed.
- The staging rehearsal timings (section 3a) are for a single node with no traffic; the prod rebuild competes with
  live traffic. The 4-minute maintenance burst and its script remain untested. Staging's first homepage load after
  `cr` took 12.7 s.
- Whether `template_user_profile` actually needs the geocomplete element (see 3a).
- The prod DB backup method is not recorded here.
- Whether `exclude_node_title` is enabled on prod; the quick module list did not show it.
- Whether the prod ALB health check should be changed to a shallow endpoint first (see the
  [2026-11-18 incident](../incidents/2026-11-18-homepage-500-empty-news-link.md)); not required for this release.
- Second target group `alb-library-drupal-production-1` has no load balancer; confirm the rollout only touches
  `alb-library-drupal-production`.
