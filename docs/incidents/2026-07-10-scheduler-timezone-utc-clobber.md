---
status: diagnosed
opened: 2026-07-10
jira: DLS-67
---

# 2026-07-10 — Site timezone off by 4h (SimpleSAMLphp clobbers the request timezone to UTC)

**Severity:** data-integrity + display bug on production; no outage
**Trigger:** any authenticated request whose SAML session is validated in-process
**Status:** root cause identified; **one-line fix + one data correction pending** (see
[Resolution](#resolution) — to be applied the week of 2026-07-13)
**Jira:** DLS-67 (reporter: Amber Reichert / `akl3b`)

## Summary

Content editors saw every date on the admin side shifted **+4 hours** (EDT→UTC offset), and
the **Scheduler** module fired early: an item scheduled to unpublish at **noon** unpublished
at **8am**. Every system- and config-level check came back correct — Drupal's site timezone
is `America/New_York`, PHP under drush resolves to Eastern, the core date formatter converts
correctly. The skew was exactly **one** UTC offset (4h, not 8h), and it only appeared in the
**web** request path, never under drush/CLI.

Root cause: **SimpleSAMLphp**, running **in-process** inside the Drupal container (via
`simplesamlphp_auth`), calls `date_default_timezone_set()` during session handling. Its
timezone config resolves from the container's `TZ` env, which is **`UTC`**, so on every
authenticated request SAML **overwrites** the correct `America/New_York` that Drupal's
`TimeZoneResolver` had just set — poisoning all timestamp reads (display +4h) and datetime
widget writes (noon stored as noon-UTC = 8am EDT) for the rest of that request. drush never
boots SimpleSAMLphp, which is exactly why every CLI diagnostic looked clean.

## Symptoms (from DLS-67)

- Item scheduled to **unpublish at noon** unpublished at **8am** (4h early).
- Node created ~9:30am showed a `created` time of ~1:30pm (+4h).
- Manual publish ~10:00am showed "Updated" 13:59 (+4h).
- Setting "Publish on" to 10:30am while the real time was 10:02am was rejected as "not in the
  future" — the widget's notion of "now" was already ~4h ahead.

## Evidence / diagnostic trail

All checks run on prod node 0 (`library-drupal-0`), confirmed identical on node 1.

| # | Check | Result | Reading |
|---|-------|--------|---------|
| 1 | `drush config:get system.date` | `timezone.default: America/New_York`, `user.configurable: false` | Drupal config correct |
| 2 | `drush php:eval "echo date_default_timezone_get();"` | `America/New_York` | drush path Eastern (Drupal sets it) |
| 3 | `php -r "echo date_default_timezone_get();"` (no Drupal) | `UTC` | raw PHP baseline is UTC |
| 4 | container `TZ` env; `/etc/localtime`; `php.ini date.timezone` | `UTC`; `→ /usr/share/zoneinfo/UTC`; **unset** | whole box is UTC; nothing sets Eastern except Drupal at runtime |
| 5 | `date.formatter->format($ts,'custom',…)` with PHP default = UTC, **no explicit tz** | renders **+4h (UTC)** | any formatter without an explicit tz skews when default is UTC |
| 6 | Exercised `TimeZoneResolver` via `setAccount()` for anon/`akl3b`/admin | **`America/New_York`** for all | resolver is correct; Drupal *should* set Eastern every request |
| 7 | `grep date_default_timezone_set` in contrib/custom/themes | only test files | nothing in Drupal-space resets it |
| 8 | `grep date_default_timezone_set` in `vendor/` | **`simplesamlphp/.../Utils/Time.php:60-62`** | the in-process clobberer |
| 9 | `/var/simplesamlphp/config/config.php:77` | `'timezone' => getenv('TZ') ?: 'UTC'` | SAML timezone = container `TZ` = **UTC** |
| 10 | `netbadge-0` container `TZ` vs `drupal-0` | `America/New_York` vs **`UTC`** | the SP container is correct; only Drupal's container is UTC |
| 11 | node **3173** raw `changed` = `1783691998` | = 09:59 EDT / 13:59 UTC | storage correct; "13:59" was a UTC **display** |
| 12 | node **3172** raw `unpublish_on` = `1784721600` | = **12:00:00 UTC = 08:00 EDT** | write stored 4h early (noon entered → noon UTC) |

### The mechanism (why the paradox resolves)

1. The container is UTC top to bottom (`TZ=UTC`, `/etc/localtime→UTC`, `php.ini date.timezone`
   unset). Nothing makes PHP Eastern except Drupal at runtime.
2. On every web request, core's `TimeZoneResolver` (subscribes to `KernelEvents::REQUEST`
   priority **299**, right after auth, and to `AccountEvents::SET_USER`) calls
   `date_default_timezone_set('America/New_York')`. Verified it returns Eastern for anon and
   authenticated users alike (`user.configurable: false` → everyone gets the site default).
3. **Then** `simplesamlphp_auth` validates the SAML session in-process, booting SimpleSAMLphp,
   whose `Utils\Time` reads `config.php`'s `'timezone'` (= `getenv('TZ')` = **`UTC`**) and calls
   `date_default_timezone_set('UTC')` — **after** step 2, clobbering it for the rest of the
   request.
4. Every downstream op now runs under UTC:
   - **Reads** — `TimestampFormatter` / datetime widgets render the (correctly-stored) UTC
     epoch without converting back to Eastern → **+4h display**.
   - **Writes** — the datetime widget builds `DrupalDateTime` in the request timezone (UTC), so
     "noon" is stored as **noon UTC = 8am EDT**. Scheduler's cron then fires at that (correct,
     but wrong-by-4h) epoch.
5. **drush never boots SimpleSAMLphp**, so the CLI default stays at Drupal's Eastern — which is
   why config, `date_default_timezone_get`, the core formatter, and the reporter's account
   timezone all checked out clean. The CLI-vs-web split was the whole tell.

## Root cause

`package/Dockerfile:11` sets `ENV TZ=UTC` (present since the repo's initial commit, 2023-03-22
— **not** a recent change). The SAML config template
(`terraform-infrastructure/…/files/var/simplesamlphp/config/config.php:77`) intentionally reads
`'timezone' => getenv('TZ') ?: 'UTC'`. The **netbadge SP container** is given
`TZ=America/New_York` (from its own image), so its SAML is correct; the **Drupal container** was
left at `TZ=UTC`, so the same in-process SAML code forces UTC. The bug is that asymmetry.

> **Why it surfaced when it did (confirmed):** `TZ=UTC` has been in the Dockerfile since 2023
> and was harmless on its own — nothing booted SimpleSAMLphp in-process on Drupal requests. The
> bug **activated when `simplesamlphp_auth` was deployed this Spring** (2026): from that point,
> every authenticated request validates the SAML session in-process, running SimpleSAMLphp's
> `date_default_timezone_set(getenv('TZ') ?: 'UTC')` and clobbering Drupal's Eastern. The
> deployed `config.php` dated 2026-05-12 is consistent with that rollout.

## Resolution

### 1. Code fix (one line, this repo)

`package/Dockerfile`:

```diff
 # set the timezone appropriatly
-ENV TZ=UTC
+ENV TZ=America/New_York
 RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone
```

This makes `getenv('TZ')` resolve to Eastern, so SAML's in-process `date_default_timezone_set()`
**matches** Drupal instead of fighting it, and aligns `drupal-0` with the already-correct
`netbadge-0`. Drupal stores all timestamps in UTC internally regardless, so there is **no data
migration** for `created`/`changed` — only the live conversion is corrected.

**Rejected alternatives:**
- Hardcoding `config.php`'s `'timezone' => 'America/New_York'` — works, but that file is
  generated from the `terraform-infrastructure` Ansible template (different repo) and its
  env-driven design is correct; fixing `TZ` respects it and keeps both containers consistent.
- Setting `php.ini date.timezone` alone — **insufficient**; SAML explicitly overrides via
  `getenv('TZ')`.

**Local verification (2026-07-10):** built a minimal image from the identical base
(`public.ecr.aws/docker/library/drupal:10.6.10`) mirroring `Dockerfile` lines 4/11/12, and
replayed the two request steps (Drupal resolver sets Eastern → SimpleSAMLphp `Time.php` runs
`date_default_timezone_set(getenv('TZ') ?: 'UTC')`):

| Build | SAML sets | "noon 2026-07-22" stored as | Epoch |
|-------|-----------|-----------------------------|-------|
| `TZ=UTC` (current prod) | `UTC` | `12:00:00 UTC` (8am EDT) — **clobbered** | `1784721600` |
| `TZ=America/New_York` (fix) | `America/New_York` | `16:00:00 UTC` (noon EDT) — **correct** | `1784736000` |

The reproduction reproduces the exact bad epoch on disk (`1784721600` = node 3172) and the fix
produces the exact corrected epoch (`1784736000`). `php.ini date.timezone` stayed UTC in both —
confirming `TZ`, not php.ini, is the lever. (The OS clock also becomes EDT under the fix, same as
`netbadge-0`; harmless — Drupal stores in UTC internally.)

### 2. Data correction (exactly one entry)

A full audit of `node_field_data` found **one** pending scheduler value site-wide (Scheduler
deletes `publish_on`/`unpublish_on` once fired, so past mis-fires leave no row):

| nid | field | stored (raw) | currently reads | correct value | correct reads |
|-----|-------|--------------|-----------------|---------------|---------------|
| 3172 | `unpublish_on` | `1784721600` | 2026-07-22 **08:00 EDT** | `1784736000` (`raw+14400`) | 2026-07-22 **12:00 EDT** |

Signature `UTC=12:00:00` = a date-only entry with `default_time: '12:00'` applied under UTC.
It fires **2026-07-22** (ample runway). `created`/`changed` need no correction (stored as true
request epoch; they only *displayed* wrong).

> ⚠️ **Sequencing:** do **not** re-save node 3172 through the edit form *before* the TZ fix is
> live — under the current UTC clobber the form would re-corrupt it. Either set the raw value
> directly, or re-save via the UI **after** the fix deploys and confirm it stores `16:00 UTC`.

## Next steps (pick up week of 2026-07-13)

- [x] Apply the `package/Dockerfile` `TZ` edit above — **done locally (uncommitted)**, verified
      against the production base image (see [Local verification](#resolution)).
- [ ] Push `main` → auto-deploys to **staging**. Verify: as an authenticated editor, create a
      **date-only** scheduled node → confirm `unpublish_on` stores `16:00:00 UTC` (not
      `12:00:00`); confirm the admin content list renders Eastern.
- [ ] Open the `main → release` PR → deploy to **prod** (verify **both** nodes).
- [ ] Correct node **3172** on prod (after the fix): set `unpublish_on = 1784736000`, or
      re-save via the UI to noon and confirm `16:00 UTC`.
- [ ] Post the DLS-67 resolution comment (root cause + fix + the one corrected entry).

## Lessons

**This was a global-state ownership hazard, not just a timezone typo.** The transferable lesson
is about *who is allowed to mutate shared process-global state, and who is responsible for
defending it* — a pattern that will recur any time an in-process library is booted inside the
Drupal request.

- **The real footgun is PHP's mutable, process-global default timezone.**
  `date_default_timezone_set()` sets one value for the whole process/request. Drupal's
  `TimeZoneResolver` sets it **once** early in the request and then *trusts* it — it never
  re-asserts it and has no way to detect that something changed it afterward. That is *trusting*,
  not broken (essentially every PHP app relies on the global default the same way), but it means
  any code booted later in the same request can silently win.
- **The most culpable actor was the library that clobbered the global without restoring it.**
  SimpleSAMLphp's `Utils/Time.php` calls `date_default_timezone_set(getenv('TZ') ?: 'UTC')` and
  walks away — it never saves/restores the host's previous value. A well-behaved in-process
  library either avoids the process default entirely (passes explicit timezones internally) or
  wraps its own work in a save/restore. We can't easily fix that (upstream, different repo), which
  is *why* we corrected the environment instead.
- **Drupal offers an escape hatch that the skewed code paths didn't use.** `DateFormatter::format()`
  and `DrupalDateTime` both accept an **explicit** `$timezone`; code that passes it is immune. The
  bug only bit the paths that omit it and fall through to the global default (the
  `TimestampFormatter` and the datetime widget). Detecting the clobber generically isn't achievable
  — PHP gives no hook for "someone called `date_default_timezone_set()`," so Drupal would have to
  poll and re-assert before every date op, which is exactly the cost the once-set default is meant
  to avoid.

### Why the environment fix is the *current* fix (and where the durable one lives)

Correcting `drupal-0`'s `TZ` default is the right layer to fix this **now**: it's a one-liner in a
file we own, it removes the *disagreement* entirely (both Drupal and the in-process SAML now want
Eastern), and it aligns `drupal-0` with the already-correct `netbadge-0`. But it fixes the symptom
by **convention** — it depends on the two containers' `TZ` defaults staying identical forever.

The **durable, construction-level fix belongs in `drupal/simplesamlphp_auth`**, and that module is
the *correct* owner — better than the library or Drupal core. The reason: SimpleSAMLphp clobbering
the process timezone is **correct when it runs standalone** (`netbadge-0`, where it owns the
process) and **wrong only when it runs embedded** (`drupal-0`, where it's a guest in Drupal's
request). The library can't cheaply tell which mode it's in, and Drupal core can't anticipate a
rude guest — but `simplesamlphp_auth` *is the seam* that boots the library from inside a Drupal
request and knows it's the embedded case. It can snapshot the host's current timezone and restore
it around its SAML calls (environment-agnostic — no hardcoded zone, robust even if the `TZ`
defaults drift). That's a longer loop (contrib code, best done as an upstream PR), so it's tracked
as a follow-up, not a same-day change: see
[Proposal: guard the request timezone across the in-process SimpleSAMLphp boundary](../proposals/saml-timezone-clobber-guard.md).

### ⚠️ Future-proofing rule

> **Keep the default `TZ` env identical across the NetBadge (`netbadge-0`) and Drupal (`drupal-0`)
> containers.** They both run the same SimpleSAMLphp code, whose `config.php` derives its timezone
> from `getenv('TZ')`. If the two containers' `TZ` defaults ever drift apart again, the bug comes
> straight back on the container with the wrong value.
>
> **Note the subtlety:** SimpleSAMLphp will **still override** the process/Drupal timezone on every
> authenticated request regardless — that behavior is unchanged. The fix does not stop the override;
> it only makes the value being forced (`getenv('TZ')`) *agree* with what Drupal wants. The `TZ`
> env is therefore the real control surface, and it must match between the two containers. Do not
> assume Drupal's `system.date` config or `php.ini`'s `date.timezone` will protect you — SAML's
> `getenv('TZ')` overrides both.

## Related

- [SimpleSAMLphp secure cookie](../troubleshooting/simplesamlphp-secure-cookie.md) — another
  case of SimpleSAMLphp behavior interacting with this HTTP-on-container setup
- [Authentication / NetBadge architecture](../architecture/authentication-netbadge.md)
- `package/Dockerfile` — the fix location
- `terraform-infrastructure/library.virginia.edu/*/ansible/files/var/simplesamlphp/config/config.php` — the env-driven SAML timezone template
