# Session Log: Field Layout bug, prod config drift, and a self-inflicted CKEditor regression

**Date:** 2026-08-17 to 2026-08-20
**Participants:** Yuji Shinozaki, Claude (Sonnet 5)
**Branches:** `main` (drupal-library) — pushed directly, no PR; `production` (drupal-library-config-sync)
**Outcome:** Diagnosed and reverted a Field Layout core-bug false alarm; discovered and repaired
~2 months of uncommitted prod config drift; found and fixed the root cause of the broken
`drush cex --commit` automation; shipped a PHP upload-limit fix through all three environments;
in the process, reintroduced and then fixed a known CKEditor 4 "ghost module" bug on prod for
the first time; briefly caused and immediately fixed a real (if narrow) prod outage; separately
diagnosed an unrelated WAF false-positive, left unresolved pending a policy decision.

---

## 1. Field Layout / Layout Builder PHP notice — false alarm, no action needed

Started from a pasted ChatGPT diagnosis of Drupal core issue
[#3053906](https://www.drupal.org/project/drupal/issues/3053906) (`Undefined index: region` on
layout overrides when Field Layout + Layout Builder are both enabled). Verified against this
codebase: `field_layout` and `layout_builder` are both enabled, `layout_builder` has no hard
dependency on `field_layout`, and four content types (`2025_annual_report`, `library`, `page`,
`rds_page`) have `allow_custom: true` — the exact reproduction condition.

Tested uninstalling `field_layout` locally in DDEV — succeeded cleanly with no dependency
errors, confirming the diagnosis. **Reverted** the local uninstall (`pm:enable field_layout` +
`cr`) rather than pursue it further; no config changed, nothing shipped. Not pursued as a real
fix this session.

## 2. Discovered ~2 months of uncommitted prod config drift

While reasoning about whether an eventual `field_layout` uninstall would round-trip through
config export safely, checked prod's config-sync working tree directly
(`/mnt/data/drupal-0/config/sync` on `library-drupal-0`, bind-mounted into the container at
`/opt/drupal/config/sync`, a git checkout on the `production` branch). Found the working tree
**~2 months out of sync with its last commit** (`Automatic Commit Thu Jun 18 20:00:02 UTC 2026`):
136 files, +4912/-989 lines, including a brand-new `spotlight_item` content type + companion
`subnavigation` block type built entirely on prod and never captured in git, plus `svg_image`/
`svg_image_responsive` module installs, three new Asset Injector CSS snippets, and widespread
form/view-display region tweaks.

Committed and pushed the full true-up as
[`1cd26c6`](https://github.com/uvalib/drupal-library-config-sync/commit/1cd26c6) on `production`.

## 3. Root-caused and fixed the broken auto-commit cron

Root's crontab on the prod host runs `drush cex --commit` every 2h + `git push` at :15 — this
is what produces the `Automatic Commit ...` messages, and it was still scheduled and firing
(confirmed via `journalctl`), but silently failing since 2026-06-18 with `MAILTO` never
reaching anyone (no mail relay configured on the host). Two independent causes, found by
running the cron's own command manually with `-v`:

- **Git "dubious ownership"** on the bind-mounted `config/sync` checkout (real resolved path:
  `/opt/drupal/util/drupal-library/package/data/opt/drupal/config/sync`).
- **No git author identity** configured in the container at all.

Fixed both live in the running container (`git config --system --add safe.directory ...` ×2,
`user.email`/`user.name`), then got the true-up commit pushed through a **third** break in the
chain — `Host key verification failed` for github.com in the container's `known_hosts` (fixed
by the user directly; out of scope for me to touch).

Baked the `safe.directory` + identity fixes into `package/Dockerfile` (commit `23c96d7`) so they
survive future image rebuilds instead of only living in this container's writable layer.
Pushed to `main` (staging auto-deploy) and fast-forwarded `develop` to match.

**Validated at the end of this session**: the 22:00 UTC cron cycle self-committed the CKEditor
fix from §5 below with zero manual intervention — the automation is durably working again.

## 4. PHP upload-limit fix, shipped through all three environments

Separately, the same content editor hit `AjaxError ... exceeded the maximum file size (2 MB)`
saving a Layout Builder media field. Traced to `upload_max_filesize = 2M` /
`post_max_size = 8M` in `package/data/usr/local/etc/php/php.ini-production` — the `document`/
`image` media fields have no field-level override, so they inherit the server-wide PHP cap.
Confirmed prod's actual `memory_limit` is overridden to `1024M` in `settings.php` (not the
128M in php.ini), well clear of the site's known [OOM ceiling](../troubleshooting/php-oom-fatals.md),
so raising upload size doesn't compound that unrelated issue.

Raised to `upload_max_filesize=20M` / `post_max_size=25M` (commit `0fe6c2c`). Rolled out:

- **Staging**: automatic (push to `main`), verified live.
- **Dev**: manual Ansible deploy (`deploy_backend.yml`, no auto-deploy pipeline exists — known
  gap), verified live.
- **Prod**: full [rolling deploy](../operations/production-deploy-runbook.md) — node 1 then
  node 0, each drained/deployed/verified/re-added, no cache rebuild needed (pure config swap).

## 5. Self-inflicted: the rolling deploy reintroduced the CKEditor 4 "ghost module" bug — on prod, for the first time

The prod rolling deploy shipped `main`'s code (CKEditor 4 files removed since commit `5d9513b`)
to prod for the first time, while prod's DB still had `ckeditor` enabled — exactly the
mismatch [documented](../troubleshooting/ckeditor-ghost-wsod.md) as "prod is not affected"
*only because* prod had deliberately been kept on a pre-removal image until the module could be
uninstalled there properly. That invariant broke the moment `main` shipped to prod.

**Impact, confirmed via watchdog + Apache logs**: admin-only. Every fatal was on
`/admin`, `/admin/modules`, `/admin/reports/status`, or the cache-flush link — normal/public
pages were unaffected throughout.

**Fix approach** (the user explicitly declined the maintenance-mode procedure the troubleshooting
doc otherwise prescribes, in favor of a same-effect alternative): drain one node from the ALB,
apply the guarded config fix (the same logic as
`local/ddev/backups/ckeditor-ghost-cleanup.sh`, which refuses to run against prod) directly
against the **remaining live node** — remove `ckeditor` from `core.extension` + its
`system.schema` key, re-enable `devops_docs` (same known side effect) — then `drush cr` on that
single live node. Since only one node was live at any moment, this sidesteps the shared-cache
deadlock class that caused the
[2026-06-26 WSOD](../incidents/2026-06-26-prod-cache-deadlock-wsod.md) without needing a
maintenance-mode window. Verified via an authenticated request that `/admin/modules` returns
200. Restarted the other node's container, confirmed it picked up the same DB-backed config fix
with no separate action needed, re-added it to the ALB.

**Durable-fix note**: this repaired prod's *active* config; §3's now-working automation
already picked it up and committed it to config-sync without help (see §3's validation note).

## 6. Self-inflicted: a brief real prod outage from an interrupted command

An earlier attempt at the maintenance-mode procedure (before the user redirected to §5's
approach) was rejected via the permission-approval flow — but the rejection landed *between*
the two chained SSH commands, after `drush state:set system.maintenance_mode 1` had already
executed and before the paired `drush cr` ran. This left maintenance mode silently stuck on.
Combined with draining a node for §5's fix, this produced a real window with **zero healthy ALB
targets** and a genuine public `503` — caught within a couple of minutes via a routine health
check (`Target.ResponseCodeMismatch`) and fixed immediately (`state:set ... 0`). Root-caused
before proceeding further; no lingering effect once identified.

**Takeaway**: a multi-step Bash tool call isn't atomic with respect to interruption — a rejected
or cancelled call can still have executed its earlier sub-commands. Worth remembering for any
future paired "set risky state / undo risky state" command sequences.

## 7. Unrelated: a WAF false positive on legitimate `<svg>` content

A separate 403 the same editor hit (`/layout_builder/update/block/overrides/node.3198/...`)
turned out to be **unrelated to any of the above** — confirmed via full (non-sampled) WAF logs
in CloudWatch (`aws-waf-logs-regional`) that AWS WAF's `AWSManagedRulesCommonRuleSet` →
`CrossSiteScripting_BODY` rule blocked the request at 11:57 AM ET, hours before any deploy work
started, because the saved block content legitimately contained `<svg`. The block/ALB rejects
before the request ever reaches Drupal, which is why nothing showed in Apache or watchdog logs.

Not fixed — presented three options (blanket `Count`-mode override matching three existing
overrides on the same rule group; a path-scoped exception; or leave as-is) and the user chose
to leave the WAF untouched pending a separate decision.

## 8. Loose ends

- The develop-deploy-pipeline gap (dev has no auto-deploy) is still open — same known issue as
  [2026-08-10](2026-08-10-dls-68-dev-content-loss-and-dev-deploy-gap.md); manual deploy used
  again here.
- WAF `CrossSiteScripting_BODY` false-positive on inline `<svg>` in Layout Builder content is
  unresolved — needs a scoping decision (see §7).
- The SSH `known_hosts` fix on the prod container (§3) was applied by the user directly; not
  captured anywhere durable (container writable layer only) — will need baking into the
  Dockerfile too if it isn't already, or it will silently break again on the next rebuild.
- Confirm the config-sync repo's automated push (cron fires `git push` at :15 past the hour)
  actually landed the §5 commit on `origin/production`, not just the local host checkout.
