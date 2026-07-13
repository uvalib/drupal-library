# Session Log: DLS-67 — Scheduler/timezone skew traced to SimpleSAMLphp clobbering the request TZ

**Date:** 2026-07-10
**Participants:** Yuji Shinozaki, Claude (Opus 4.8)
**Branch:** `main`
**Outcome:** Root-caused DLS-67 (site timezone 4h off; Scheduler firing early) to
**SimpleSAMLphp resetting the PHP default timezone to UTC in-process** on authenticated
requests, overriding Drupal's correct `America/New_York`. Identified the fix as a one-line
`package/Dockerfile` `TZ` change, **made it and verified it locally** against the production
base image (the reproduction hit the exact bad epoch on disk; the fix hit the exact corrected
epoch). Found the affected data is a single pending Scheduler entry. Wrote up the incident.
**Not yet deployed** — picking up the push/deploy + data correction the week of 2026-07-13.

Full record: [incidents/2026-07-10-scheduler-timezone-utc-clobber.md](../incidents/2026-07-10-scheduler-timezone-utc-clobber.md).

---

## 1. The paradox that made this hard

DLS-67 reported everything ~4h ahead (EDT→UTC offset) and Scheduler unpublishing at 8am
instead of noon. A prior browser-only session had ruled out, via drush, every config/system
layer: `system.date` = `America/New_York`, PHP CLI default = Eastern, core date formatter
correct, reporter's account TZ correct. It handed off asking to run direct server queries.

Picking that up here with SSH/`docker exec` access to prod, the tell was that **every check
was CLI/drush** and all clean, while the *web* path was clearly UTC. Confirmed on prod:

- Container is UTC top to bottom (`TZ=UTC`, `/etc/localtime→UTC`, `php.ini date.timezone`
  unset); raw `php -r` = UTC. drush shows Eastern only because Drupal sets it at bootstrap.
- Core `TimeZoneResolver` (subscribes `KernelEvents::REQUEST` prio 299 + `AccountEvents::SET_USER`)
  returns `America/New_York` for anon/`akl3b`/admin — so Drupal *should* set Eastern every
  web request too. Yet node 3172's `unpublish_on` was physically stored as noon **UTC**.

That contradiction — resolver correct, data UTC — is what pointed past Drupal entirely.

## 2. Root cause

Grepping `vendor/` for `date_default_timezone_set` found the one in-process, non-drush
culprit: **`simplesamlphp/simplesamlphp/src/SimpleSAML/Utils/Time.php`**, which sets the PHP
default timezone from SimpleSAMLphp's `timezone` config —
`/var/simplesamlphp/config/config.php:77` = `'timezone' => getenv('TZ') ?: 'UTC'`. With the
container `TZ=UTC`, SAML runs `date_default_timezone_set('UTC')` on every authenticated
request (session validation via `simplesamlphp_auth`), **after** Drupal's resolver set Eastern
— poisoning all downstream timestamp reads (+4h display) and datetime-widget writes (noon →
noon UTC = 8am EDT). drush never boots SimpleSAMLphp, so CLI stayed clean the whole time.

The clincher: **`netbadge-0`** (the standalone SP container) has the *identical* `config.php`
line but `TZ=America/New_York`, so its SAML is correct. Only the **Drupal** container was left
at `TZ=UTC`. `ENV TZ=UTC` has been in `package/Dockerfile` since the 2023 initial commit — so
the bug was latent and activated by the in-process SAML timezone behavior, not a recent TZ
change. (Exact activation date not bounded this session.)

## 3. Source-of-record trace

- `drupal-0` `TZ=UTC` comes solely from **`package/Dockerfile:11`** — the Ansible deploy
  (`terraform-infrastructure/.../deploy_backend.yml`, env from `container_0.env.managed`) sets
  no `TZ`, and `config.php` is a mounted, Ansible-generated file whose `getenv('TZ')` template
  is correct by design.
- Fix belongs here: `ENV TZ=UTC` → `ENV TZ=America/New_York`.

## 4. Made + tested the fix locally

Edited `package/Dockerfile` (with an explanatory comment pointing at the incident doc). Built a
minimal image from the exact base (`public.ecr.aws/docker/library/drupal:10.6.10`) mirroring
lines 4/11/12, and replayed the two request steps:

| Build | SAML sets | "noon" stored | epoch |
|-------|-----------|---------------|-------|
| `TZ=UTC` (current prod) | UTC | `12:00:00 UTC` (8am EDT) | `1784721600` ← matches node 3172 |
| `TZ=America/New_York` (fix) | Eastern | `16:00:00 UTC` (noon EDT) | `1784736000` ← the corrected value |

The reproduction reproduced the exact on-disk bad epoch and the fix produced the exact
corrected epoch. `php.ini date.timezone` stayed UTC in both (TZ is the lever, not php.ini).

## 5. Affected data — a single entry

Read-only audit of `node_field_data` (all `publish_on`/`unpublish_on`): **one** pending value
site-wide — node **3172** `unpublish_on = 1784721600` (2026-07-22 **08:00 EDT**), should be
`1784736000` (**12:00 EDT**). Fires 2026-07-22, ample runway. `created`/`changed` need no fix
(stored as true epoch; only displayed wrong). Scheduler deletes the fields after firing, so
past mis-fires leave nothing to backfill.

## 6. State at end of session

- `package/Dockerfile` — **edited, uncommitted** (the one-line `TZ` fix + comment).
- New docs — incident record + this log (uncommitted).
- **Nothing pushed / deployed.** Per standing rule, pushing `main` auto-deploys to staging, so
  that's left for the deliberate Monday pickup.

## Next steps (week of 2026-07-13)

1. Commit + push `main` → verify on **staging** (date-only scheduled node stores `16:00 UTC`;
   admin content list renders Eastern).
2. `main → release` PR → **prod**; verify **both** nodes.
3. Correct node **3172** `unpublish_on` on prod (set `1784736000`, or re-save via UI **after**
   the fix — not before, or the form re-corrupts it).
4. Post DLS-67 resolution comment.
