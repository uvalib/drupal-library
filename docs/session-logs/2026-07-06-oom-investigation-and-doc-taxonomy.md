# Session Log: PHP OOM Investigation, scan-alerts Tool, and Doc Taxonomy Rework

**Date:** 2026-07-06
**Participants:** Yuji Shinozaki, Claude (Opus 4.8 / Sonnet 5)
**Branch:** `main`
**Outcome:** Investigated recurring PHP OOM fatals on production (root cause still open);
built a general-purpose CloudWatch alarm→log investigation tool and relocated it to the
personal `ys2n/scripts` repo where it belongs; used it to also check Mandala production;
documented the OOM findings in mkdocs, which surfaced real gaps in the doc site's left-nav
auto-update and its Maintenance/Troubleshooting/Incidents/Proposals taxonomy — reworked all
four; added lightweight frontmatter to prepare for a future Jira integration.

---

## 1. AWS alert investigation — production PHP OOM fatals

Investigated live CloudWatch alarms for `drupal-library-production`. All 25 alarms were
`OK` (they self-clear in minutes), but alarm **history** showed `php-0-errors` /
`php-1-errors` repeatedly flipping to `ALARM` — a `LogMetrics` filter on `php:error` in each
node's container log. Root cause of each individual event: **PHP fatal, 1 GB memory limit
exhausted, in Twig's exception constructor** — the real allocation hotspot is further up the
call stack and wasn't isolated this session.

A 30-day scan (via CloudWatch Logs Insights — `filter-log-events` reliably times out over
that range) found **113 OOM fatals** across both nodes, two distinct signatures (Twig
page-render, ~101 events; KCFinder upload, 7 events), heavily clustered **Jun 19–30** (peaks
of 24/day) then back to background rate. The alarm threshold was found to have already been
raised from 1 to 10 occurrences/5min, presumably to cut noise from the low background rate.

Also ran the same investigation against `uva-mandala-drupal-production` (a different site) —
zero PHP fatals in the last 24h, but found 22 `HTTP 400`s from the Solr proxy
(`Searcher.php:183`, referer `thlib.org`), a distinct issue on a distinct subsystem.

## 2. Built scan-alerts, then moved it out of this repo

Wrote a bash tool (`alarm name prefix` → alarm history → log-metric-filter chain → CloudWatch
Logs Insights scan, aggregated by day/referer/failing-line) to make this repeatable. Hit two
real bugs during development:

- **bash 3.2 (macOS default) incompatibility** — no `mapfile`/`declare -A`; also, an *empty*
  array under `set -u` throws "unbound variable." Fixed with plain indexed arrays / parallel
  arrays and string-based option passing instead of array expansion.
- **A credential gotcha that looked like a script bug** — `aws` was aliased in the
  interactive shell to `aws-vault exec staging -- aws`; that alias doesn't propagate into
  the script's own subprocess, so a bare `aws` call inside the script silently picked up
  different (expired) credentials than the shell that invoked it — manifesting as "session
  expired" even though the same command worked fine typed directly.

Once it worked against a second, unrelated site (Mandala) with zero code changes beyond the
alarm prefix argument, it was clearly general-purpose, not library-specific. Moved it to the
personal `ys2n/scripts` repo (`uvalib/aws/scan-alerts`), matching that repo's existing
`aws_run` credential-fallback convention (used by `alb-state`/`mandala-logs`) instead of the
one-off `AWS_CMD` escape hatch it started with. Added `uvalib/aws/README.md` there recording
the design rationale and both gotchas. Committed and pushed to `ys2n/scripts` (a separate
repo from this one).

## 3. Documented the OOM findings — which surfaced doc-site gaps

Added a maintenance doc for the OOM findings, which led to previewing the docs locally
(`scripts/build-docs.sh` / `mkdocs serve`) before pushing — since a push to `main` here
auto-deploys to staging. That surfaced two real problems:

- **The left nav didn't auto-update.** `awesome-pages` `.pages` files use explicit lists, not
  auto-discovery, so a new page silently doesn't appear in nav unless you remember to add it.
  Fixed the immediate miss, then added the `...` auto-append wildcard (already used in
  `session-logs/.pages`) to **every** `.pages` file site-wide so this class of bug can't
  recur.
- **The doc taxonomy itself was inconsistent.** Working through where the OOM doc actually
  belonged surfaced that `config-sync-mechanism-review.md` and `redis-cache-backend.md` were
  filed in Maintenance while literally self-describing as "not yet decided" / "proposed" —
  the exact definition of the *Proposals* section.

## 4. Doc taxonomy reworked

Through discussion, arrived at:

- **Troubleshooting** = active issues needing troubleshooting, resolved or not (not just
  "known issues with fixes" as originally written). Rebuilt its index as a **recency-sorted
  log with Opened/Status columns** (✅ resolved / 🟡 open) so it doubles as "everything
  recently troubleshot" at a glance, not just a symptom lookup table. Added explicit
  `**Status:**` lines to the three previously-implicit docs.
- **Incidents** = the facts of what happened — loosened from "what went wrong *once*" to also
  cover a recurring issue's factual log. Split the OOM doc: `php-oom-fatals.md` moved (`git
  mv`) to Troubleshooting (active investigation, next steps), and a new
  `2026-07-06-php-oom-fatal-occurrences.md` in Incidents holds the factual scan data
  (counts, day-clustering, signatures), meant to be appended to on future re-scans rather
  than duplicated across both docs.
- **Maintenance** = two-fold: regular recurring upkeep (upgrades, patches, module
  churn) **and** planned risk-mitigation/gradual-improvement work that isn't a new feature
  (e.g. the Redis cache backend, NetBadge refactoring). Rewrote its README to this effect.
- **Proposals**, cross-listing: rather than moving `config-sync-mechanism-review.md` and
  `redis-cache-backend.md` out of Maintenance, marked both **(proposal)** in Maintenance's
  table (their permanent home doesn't change once decided, unlike a "pure" proposal) and
  added a "Proposals that already have a home" section in Proposals' README pointing back to
  them — dual-listed, clearly labeled, single source of truth.

## 5. Future Jira integration — planned for, not built

The user flagged that this documentation will eventually integrate with Atlassian Jira.
Risk identified: status was being tracked only as free-text (`**Status:** open`) plus
hand-maintained README tables — fine now, but two/three places that could silently drift once
Jira becomes an actual source of truth, and no stable field for a future ticket key.

Added lightweight YAML frontmatter (`status`, `opened`, `jira: null`, `priority` where already
stated) to all 9 status-bearing docs, sourcing `opened` dates from git history rather than
guessing. Purely additive — mkdocs-material ignores unknown frontmatter keys, verified no
leakage into rendered output. The prose Status lines stay as the human-readable version;
frontmatter is what a future sync job would read. Nothing else was built for the Jira
integration — deliberately deferred until that work actually starts.

---

## Open items (carried forward)

- **PHP OOM root cause** — not identified. Next: isolate the template/view behind the
  no-referer / `/teams/<uuid>` renders; confirm or rule out the Jun 25/29 deploys as the
  Jun 19–30 storm's trigger; separately investigate the KCFinder upload path.
- **`scan-alerts`** lives in `ys2n/scripts` now — re-run periodically
  (`scan-alerts -d 30 -q 'Allowed memory size' uva-library-drupal-production`) to check
  whether the OOM rate is climbing again; append results to the Incidents occurrence log.
- **Mandala Solr 400s** (`thlib.org` / `kmassets` faceted query) — flagged, not investigated
  further; may be a client-side query-shape issue against Solr rather than a Mandala bug.
- **Redis cache backend** and **config-sync mechanism redesign** — both still open proposals,
  now clearly marked as such in Maintenance + cross-listed in Proposals.
- **Operations vs. Maintenance README overlap** — noted but not addressed this session
  (both describe "running/maintaining the site"); flagged for a future pass if it becomes
  confusing in practice.
