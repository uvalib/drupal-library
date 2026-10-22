---
status: needs-review
opened: 2026-10-22
jira: null
---

# Theme repo: `main` and `release` have diverged

!!! warning "Status: bookmarked for later review (2026-10-22) — investigation parked"
    `uvalib-drupal-theme` has two lines of history: `release` (what staging and production run,
    via SSM-pinned tags) and `main` (what develop runs, unpinned, and what the container image
    clones at build time). They diverged in Nov 2025 and were never reconciled. On 2026-10-22
    only the wanted login-form commits were cherry-picked across; nothing else was decided.

## The facts (as of 2026-10-22)

- `release` tip `e24e27bc` = tag `prod-theme-20261022-login-form-fixes` (the current production
  theme; SSM pin `/themes/uvalib/drupal-library/release` v9). `main` tip `9e2201de`.
  `main` is 12 commits ahead and 9 behind `release`.
- Last common commit: `8f08bfa2` (2025-11-20, PR #30). `release` took `main` once more on
  2025-11-21 (PR #31); after that `main` moved on alone, and `release` got only merges plus one
  content commit.
- **The one real release-only change:** on 2025-11-10/11 two commits made directly on the staging
  host (`Reverted MR #25`, `2d8a1466`; and `Revert "another round of updates (list config)"`,
  `1431a415`) undid Christopher Welte's `c8d93ba1` (2025-06-18) in `scss/base/_lists.scss`.
  **Neither commit message says why**, and `main` still has the list-config updates.
- Files that still differ between `main` and `release` (4): `scss/base/_lists.scss` (the revert,
  88 lines), `css/main.css` (+26), `templates/maintenance-page.html.twig` (new downtime links,
  +21/−3), `pipeline/deployspec.yml` (one line, the terraform repo move). The login form is now
  identical on both (Jack's five 2026-10-15 commits were cherry-picked onto `release` as PR #34).
- Production's two nodes once ran different themes (node 0 on an unpushed staging-host commit,
  `adc08b4c`, about equivalent to `1431a415`), which is how this drift was noticed. That commit is
  unrecoverable; see the session log.

## Open questions

1. **Why was the list-config change reverted?** Ask whoever worked on the theme on the staging host
   in Nov 2025 and Christopher Welte. Until that is known, do not promote `main` to production:
   it would bring the reverted list CSS back.
2. Reconcile the branches, or formally keep `release` as the only deployed line and treat `main`
   as the integration branch with cherry-picks (the approach used for PR #34)? Pick one and write
   it down; today it is accidental.
3. Develop tracks `main` (unpinned) while production is pinned to `release` tags, so develop
   shows changes production does not have. Is that intended?
4. The maintenance page on `main` has new downtime links that production lacks. Decide whether
   they should ship (a visible change).
5. The container image clones `main` at build time, but staging/production then check out the
   pinned tag. If the pin is ever missed, a node runs `main`'s theme. Consider whether the image
   should clone `release` instead.

## Related

- [Production runbook: shipping a theme change](../operations/production-deploy-runbook.md)
- [Release branch review](release-branch-review.md)
- [Session log 2026-10-22](../session-logs/2026-10-22-logout-url-port-fix-and-release-tagging.md)
