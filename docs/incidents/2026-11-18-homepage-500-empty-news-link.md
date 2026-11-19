---
status: mitigated
opened: 2026-11-18
---

# 2026-11-18 — Homepage 500s: a news story without a link took down the Layout Builder homepage

**Severity:** production degradation, about 26 minutes (12:32–12:58 ET); homepage failing, both nodes marked unhealthy
**Trigger:** the Scheduler module published a news story that had an image but no link
**Status:** mitigated by an editor at 12:55; **permanent fixes proposed, staging done, prod pending** (see [Resolution](#resolution))

## Summary

At 12:31:17 ET the Scheduler published node 3315 (type `library_news_`, has an image, no `field_link`). The
homepage's "featured news" view picked it up as the single featured story. That view builds its link from
`{{ field_link }}?mtm_campaign=...&mtm_kwd=...`. With an empty `field_link` the link becomes `http://?mtm_...`,
Drupal's link renderer throws `InvalidArgumentException`, and because Drupal core has no per-block exception
isolation, the exception aborted the whole Layout Builder homepage render: HTTP 500.

An editor added the missing link at 12:55:25 and the page recovered by about 12:58.

## Impact

- The homepage (`/`, node 2036) returned 500 for about 26 minutes.
- Target 5xx responses on the production target group: about 230 in the window (19, 57, 63, 44, 44 and 6 in
  consecutive 5-minute buckets from 12:30). That is roughly 2% of requests, which suggests other pages kept
  working. This is an inference from the totals; the 5xx were not broken down by URL.
- The ALB health check is `GET /`, so both prod nodes went unhealthy (see [Why one error took everything down](#why-one-error-took-everything-down)).

## Timeline (ET, 2026-11-18)

| Time | Event |
|---|---|
| 12:31:17 | Scheduler publishes node 3315 (image, no link). Newest featured story takes the view's single slot. |
| ~12:32 | First `InvalidArgumentException` / homepage 500s in prod watchdog. |
| 12:35 | CloudWatch: 0 healthy / 2 unhealthy hosts; ALB fails open, traffic keeps flowing. |
| 12:55:25 | Editor `akl3b` adds the link to node 3315. |
| 12:55 | CloudWatch: 1 healthy / 1 unhealthy. |
| ~12:58 | Homepage healthy again. Both hosts healthy by 13:00. |

## Root cause

Views fields `home_news_featured` (image and title) and `home_news` (title) use "Output this field as a link"
with the External server URL flag. The path is
`{{ field_link }}?mtm_campaign=home-news-featured&mtm_kwd={{ field_keyword_for_analytics }}`.

1. `field_link` empty, so the path is `?mtm_...`; with the external flag it becomes `http://?mtm_...`.
2. `FieldPluginBase::renderAsLink()` calls `parse_url()`, which returns false, then falls through to
   `Url::fromUserInput('/http://?...')`.
3. That throws `InvalidArgumentException: The internal path component 'http://' is external...`.

Only fields with text after the token are affected (a bare `{{ field_link }}` degrades quietly). The link is only
built when the field has content, so the story needed an image to trigger it. The view uses pager=1, sorted
newest first, filtered to featured, so any newly published featured story immediately takes the slot.

## Why one error took everything down

- **No block isolation.** Drupal core does not catch exceptions per block (open core issue #3293860). The exception
  from `ViewsBlock::build()` runs eagerly inside Layout Builder's render and aborts the page.
- **Failed renders cannot be cached.** Every request rebuilt and re-failed the homepage.
- **Health check on the homepage.** The ALB target group checks `GET /` on port 8080, expecting 200–299, 3 failures
  at 120 s. The check does reach Drupal (one catch-all vhost, any Host header returns the homepage; verified on
  prod node 0). Both nodes therefore failed the check.
- **Fail-open masked it.** With every target unhealthy the ALB kept sending traffic anyway, so visitors still got
  other pages, but the node was never usefully taken out of rotation.

## Detection

No alert fired. The outage was reported by people, and the watchdog (about 4.5 hours of retention on prod) was
read after the fact.

## Resolution

Immediate: editor added the link (12:55).

Permanent, in order of leverage:

| # | Fix | Status |
|---|---|---|
| 1 | Guard the link path: `{% if field_link %}{{ field_link }}?mtm_...{% endif %}` in the 3 fields | Applied and verified on **staging**; **not on prod** (runbook: [prod change](../operations/prod-change-home-news-empty-link-guard.md)) |
| 2 | `uvalib_block_guard`: a failing block is logged and replaced by an inline message; the rest of the page renders. Visitors see "This content is temporarily unavailable."; users with `access site reports` see the block ID and exception. Degraded output is cached 60 s. | Merged (PR #22); module ships **disabled**, not enabled on any environment |
| 3 | Shallow `/health` endpoint for the ALB check, so a homepage failure does not pull nodes | Not built |
| 4 | Alert on `uvalib_block_guard` log entries and on 5xx / unhealthy-host counts | Not built |
| 5 | Editor-side: warn or validate when a featured story has no link | Not decided |

### Verification done (staging)

- Reproduced the outage with an image-bearing featured story without a link (it needs an image).
- Fix 1: the homepage returned 200 and the story rendered as plain text.
- Fix 2: Kernel tests (7, mutation-checked) and a Chrome run. An admin saw the detailed notice in the featured slot
  with the rest of the page intact; an anonymous visitor saw the neutral message with `Cache-Control: max-age=60,
  public`; deleting the broken story restored the block without a cache clear.
- Staging was reverted after every test, except fix 1, which is intentionally left in place.

## Open items and unverified

- The scheduler logged both "publishing" and "unpublishing" for an Annual Report node at 12:31:17. Unexplained;
  possibly a same-title node. Not shown to be related.
- Only the Layout Builder path of the guard was tested on a real page; the region-block path is covered by
  Kernel tests only.
- A second target group, `alb-library-drupal-production-1`, has no load balancer and no traffic. Not investigated.
- The block guard changes core render behavior through service overrides; re-run its tests on Drupal 11.
- Prod runs the 2026-10-22 release; the guard needs a full release (26 commits on `main`) to reach prod.

## Lessons

- One optional field and a token-plus-text link path was enough to take a homepage down; Views rewrite paths are
  untested code paths in practice.
- The homepage is also the health check, so a content error became an infrastructure signal.
- Fail-open hid how bad it looked from the ALB side; alerting needs to watch unhealthy hosts and 5xx, not rely on
  the ALB removing nodes.
