# Proposal: Guard the request timezone across the in-process SimpleSAMLphp boundary

**Status:** Proposal / discussion — long-term follow-up to DLS-67. Nothing adopted.
**Relationship to the shipped fix:** This does **not** replace the DLS-67 fix. That fix
(`package/Dockerfile`: `ENV TZ=America/New_York`) is the current, operational remedy and stays.
This is about the **durable, construction-level defense** — and, realistically, our role in it.
**Our action:** file an **upstream bug report** against `drupal/simplesamlphp_auth`. We are not
the right party to carry a patch for a general contrib defect; the honest deliverable is a good
report. **Minimum ask: a WARNING** when the module changes the host's process timezone out from
under it. **Preferred: save/restore** around the SAML boundary (below). A carried composer patch in
`local/ddev/patches/` is only a fallback if the issue stalls upstream *and* we hit the bug again
despite the env fix.
**Related:** [Incident 2026-07-10 — timezone UTC clobber](../incidents/2026-07-10-scheduler-timezone-utc-clobber.md),
[SimpleSAMLphp version-compat check](saml-version-compat-check.md),
`docs/architecture/authentication-netbadge.md`.

## The problem (recap from DLS-67)

SimpleSAMLphp runs **in-process** inside the Drupal container via `drupal/simplesamlphp_auth`.
On every authenticated request its `Utils/Time::initTimezone()` calls
`date_default_timezone_set(getenv('TZ') ?: 'UTC')` and **never restores** the previous value —
clobbering the `America/New_York` that Drupal's `TimeZoneResolver` set moments earlier, and
skewing all timestamp reads/writes by the ET offset for the rest of the request.

The shipped DLS-67 fix removes the *disagreement* by making `getenv('TZ')` resolve to Eastern in
`drupal-0` (matching `netbadge-0`). That works, but it fixes the symptom by **convention**: it
depends on the two containers' `TZ` defaults staying identical forever. If they ever drift apart
again, the bug returns. This proposal fixes it by **construction**.

## Why the module is the correct home (not the library, not Drupal core)

The insight that DLS-67 surfaced: **SimpleSAMLphp clobbering the process timezone is correct when
it runs standalone, and wrong only when it runs embedded.**

- In **`netbadge-0`** (standalone SP) SimpleSAMLphp *owns* the process — it *should* set its own
  configured timezone; there is no host to trample.
- In **`drupal-0`** (embedded via `simplesamlphp_auth`) it is a **guest** inside Drupal's request
  — it must not trample the host's global state.

The **library** can't cheaply tell which mode it is in, so a fix in `Utils/Time.php` would break
the legitimate standalone case. **Drupal core** can't anticipate a rude guest. But
**`simplesamlphp_auth` is the seam** — it is the exact code that boots the library from inside a
Drupal request, and it *knows* it is the embedded case. The save/restore responsibility belongs
at the boundary where control crosses from host into guest, and the module is that boundary. This
is the "views and awareness of both environments" argument: only the module sees both Drupal's
intent and the library's override.

## Our role: an upstream bug report (with an escalating ask)

We won't own this fix — it's a general defect in a contrib module (any Drupal site with a non-UTC
site timezone is exposed), so the right move is to **report it upstream** and let the maintainers
choose the remedy. The report should present two tiers:

1. **Floor — a WARNING (the minimum we're asking for).** Today the clobber is *silent*: session
   validation changes the process default timezone as a side effect and nothing says so, which is
   exactly why DLS-67 took a CLI-vs-web diagnostic split to find. At minimum, when the module boots
   SimpleSAMLphp and the timezone it's about to set differs from the host's current default, it
   should **log a warning** — e.g. *"SimpleSAMLphp changed the process default timezone from
   `America/New_York` to `UTC`; this can skew host-application timestamps for the rest of the
   request."* Non-breaking, and it makes the failure mode diagnosable instead of invisible.
2. **Preferred — save/restore** the host's timezone around the boundary (below), which removes the
   clobber entirely.

The report's value is the **diagnosis and reproducer**, which this incident already has in full
(see the linked incident doc): the standalone-vs-embedded distinction, the exact call site
(`Utils/Time::initTimezone()`), the interaction with Drupal's `TimeZoneResolver`, and a minimal
reproduction against the stock base image.

### Draft issue (for the `drupal/simplesamlphp_auth` queue)

Formatted to the drupal.org issue-summary template. Fill the `Version` from the module release
actually running (check `composer show drupal/simplesamlphp_auth`) before posting.

> **Title:** In-process session validation silently clobbers the host's default timezone
>
> | Field | Value |
> |-------|-------|
> | Project | SimpleSAMLphp Authentication (`simplesamlphp_auth`) |
> | Version | *(fill from the running release, e.g. `4.0.x-dev` / `4.0.5`)* |
> | Component | Code |
> | Category | Bug report |
> | Priority | Normal |
> | Status | Active |
>
> **Problem/Motivation**
>
> When the module validates a SAML session it boots the SimpleSAMLphp library in-process. The
> library's `SimpleSAML\Utils\Time::initTimezone()` calls
> `date_default_timezone_set(getenv('TZ') ?: 'UTC')` and never restores the previous value. On a
> Drupal site whose timezone is not UTC, this overwrites the `date_default_timezone_set()` that
> core's `TimeZoneResolver` set earlier in the same request (subscribes to `KernelEvents::REQUEST`,
> priority 299), so every subsequent timestamp read/write in that request runs under the wrong zone
> — skewed by the offset. The change is completely silent: nothing logs that the process default
> timezone moved.
>
> This is arguably the *module's* concern rather than the library's: SimpleSAMLphp setting the
> process timezone is correct when it runs standalone (it owns the process), and wrong only when it
> runs **embedded** in a host request. The library can't tell which mode it's in; the module, being
> the code that embeds it, can.
>
> Real-world impact on a production site (site timezone `America/New_York`): authenticated editors
> saw all admin-side dates shifted +4h, and the Scheduler module unpublished content 4h early
> (values stored at the wrong epoch). Any site with a non-UTC site timezone is exposed. Note drush
> /CLI never boots SimpleSAMLphp, so every CLI diagnostic reports the correct timezone — the
> web-vs-CLI split is the only outward tell.
>
> **Steps to reproduce**
>
> 1. Drupal site with a non-UTC site timezone (e.g. `America/New_York`) and `simplesamlphp_auth`
>    active for authenticated requests.
> 2. As an authenticated user, create a node with a date-only scheduled unpublish (Scheduler) for a
>    future date.
> 3. Observe the stored `unpublish_on` lands at the UTC wall-clock time rather than the site
>    timezone (fires hours early).
>
> Minimal alternative: within a single authenticated web request, log
> `date_default_timezone_get()` before and after the module's auth check — it changes from the site
> timezone to `getenv('TZ') ?: 'UTC'`.
>
> **Proposed resolution**
>
> - *Minimum:* log a **warning** when session validation changes the process default timezone away
>   from the host's current value, so the side effect is diagnosable instead of silent.
> - *Preferred:* **snapshot and restore** the host timezone around the in-process SAML calls
>   (`$tz = date_default_timezone_get();` … `date_default_timezone_set($tz);` in a `finally`), or
>   re-assert the host timezone via a low-priority post-auth event subscriber, so the module leaves
>   the process's global state as it found it.
>
> **Remaining tasks**
>
> - Confirm the choke point(s) where the module boots the library (single service wrapper vs.
>   multiple entry paths).
> - Decide between call-site save/restore and a re-asserting event subscriber.
> - Patch + test coverage (assert the default timezone is unchanged across an authenticated
>   request).
>
> **User interface changes:** None.
>
> **API changes:** None.
>
> **Data model changes:** None.

## The preferred fix: snapshot/restore around the SAML boundary

The fix is environment-agnostic — it hardcodes no timezone and does not depend on `TZ` matching.
The module snapshots whatever the host currently has and restores it around its calls into
SimpleSAMLphp:

```php
$hostTz = date_default_timezone_get();   // Drupal already set America/New_York (REQUEST prio 299)
try {
    // ... boot SimpleSAMLphp / validate the session ...
} finally {
    date_default_timezone_set($hostTz);  // undo the guest's clobber
}
```

Because it captures the *current* default rather than a literal zone, it keeps working even if the
containers' `TZ` defaults drift — which is precisely the fragility the DLS-67 convention leaves
open.

## To iterate / open questions

- **Find the choke point.** Does all in-process SAML access funnel through a single service/helper
  in `simplesamlphp_auth` (so one wrapper covers it), or does session validation enter via multiple
  paths (request subscriber, access checks, user-login) that each boot the library? Needs a read of
  the module source (cloned into the gitignored webroot at runtime; not present without ddev up).
  If there is no single boundary, look for the lowest common one — ideally the wrapper around
  `\SimpleSAML\Auth\Simple` instantiation/`isAuthenticated()`.
- **Timing of the snapshot.** Confirm the module's auth check runs *after* `TimeZoneResolver`
  (REQUEST priority 299) so that `date_default_timezone_get()` at module entry already returns
  Eastern rather than the raw UTC baseline. (Expected true — the resolver runs very early — but
  verify.)
- **File the report.** Post the draft above to the `drupal/simplesamlphp_auth` issue queue on
  drupal.org (outward-facing — the user files it, not us). Attach the incident doc's reproducer.
  The choke-point and snapshot-timing notes above strengthen the report but aren't blockers to
  filing — the maintainers own the final placement.
- **Does upstream want it framed differently?** They may prefer the guard live in a dedicated
  event subscriber that re-asserts Drupal's timezone at a low priority after auth, rather than
  wrapping call sites. Either satisfies the "host defends its global state" principle — the report
  suggests both and leaves the choice to them.
- **Carried patch is fallback only.** Only if the issue stalls upstream *and* we hit the bug again
  despite the env fix: a composer patch in `local/ddev/patches/` + the package build, carried and
  re-verified on every module update.
- **Keep the env fix regardless.** Even with this in place, keeping `drupal-0` and `netbadge-0` on
  the same `TZ` default remains good hygiene (and is what the incident doc's future-proofing rule
  calls for). This proposal makes the system *robust to* drift; it is not a license to let the
  defaults diverge.
