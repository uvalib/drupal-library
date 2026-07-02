# Proposal: SimpleSAMLphp version-compatibility check at deploy time

**Status:** Proposal / discussion — near-converged, iterating. Nothing adopted.
**Implementation home:** `drupal-netbadge` (the shared SP hub) + the shared Ansible deploy
machinery.
**Documentation home:** *here*, in this devops-docs site — `drupal-netbadge` currently has no
documentation infrastructure, and this contract is cross-repo anyway, so the devops-docs site is
the natural hub for it (the eventual contract note belongs in
`docs/architecture/authentication-netbadge.md`, not in the netbadge repo).
**Related:** [Ephemeral environments](ephemeral-environments.md), the SAML config-as-code
("item B") work, `docs/architecture/authentication-netbadge.md`.

## The problem

NetBadge/SAML uses **two SimpleSAMLphp instances** that interoperate through a **shared Redis
session store**: the SP container (`drupal-netbadge` / `netbadge-0`) *writes* the session; the
Drupal-side SimpleSAMLphp library (`drupal/simplesamlphp_auth` in each Drupal app) *reads* it.

The **config** side of that contract is already handled — the shared store/salt/auth-source are
driven by environment variables in the terraform configs, so those stay in sync by
construction. The **gap** is the SimpleSAMLphp **library versions**: each side's version is
governed by its own independent `composer.lock` (drupal-library's, drupal-dsf's, and
drupal-netbadge's), with nothing coordinating them. A **major** drift changes the session
serialization format, so the SP writes sessions the Drupal side can't deserialize → login
breaks silently (manifests as "session invalid," not an error).

**Current state (2026-07-02):** aligned by coincidence, both on `simplesamlphp/saml2 v5.0.5`.
But the two repos are already skewed at the *patch* level (netbadge on `assets-base 2.4.3` /
`saml2-legacy 4.19.1`, library on `2.4.6` / `4.19.2`), which proves the locks float
independently — it's benign only because both are still 2.4.x.

**What actually matters:** the **major** version (session format tracks the major; patch/minor
skew is harmless).

## Approaches considered (and why not)

- **Version exposed via the SAML/SimpleSAMLphp API** — there isn't one. SAML metadata carries no
  software version; SimpleSAMLphp 2.x emits no version header and hides it from the public UI.
  The only version in the exchange is the SAML *protocol* version (`2.0`), which is constant.
- **Runtime version endpoints + client self-report + a shared client module** — over-engineered
  for what is fundamentally a build-artifact comparison.
- **Static `composer.lock` diff (scheduled/CI)** — workable, but a lock may not match a given
  built image tag, and it needs its own job.

## The approach: an advisory check at deploy time

The deploy already has **both in view** — `deployspec.yml` runs `deploy_netbadge.yml` *and*
`deploy_backend.yml` in the same run — so the check needs no new endpoint, module, or job, and
it inspects the **actual installed versions in the running images** (more accurate than
lock-diffing). It is **advisory only** (light coupling): it warns, it does not gate the deploy.

```yaml
# saml_version_check_task.yml  (shared include — advisory)
- name: read simplesamlphp/saml2 version from each container
  shell: >
    docker exec {{ item.container }}
    sh -c 'grep -A2 "simplesamlphp/saml2" {{ item.installed_json }} | grep -m1 version'
  loop:
    - { name: drupal,   container: drupal-0,   installed_json: /opt/drupal/vendor/composer/installed.json }
    - { name: netbadge, container: netbadge-0, installed_json: /var/www/.../vendor/composer/installed.json }  # confirm path
  register: saml2_versions

- name: warn if the SAML2 majors diverge
  debug:
    msg: "⚠ SAML2 drift — drupal={{ drupal_v }} netbadge={{ netbadge_v }}; session compat tracks the major, align them."
  when: (drupal_v | major) != (netbadge_v | major)
```

It slots in exactly like the shared `theme_deployment_task.yml` include — one file, pulled into
both env deploys.

## Home & ownership

Split, because `drupal-netbadge` has no documentation infrastructure today:

- **Implementation** — `drupal-netbadge` is the hub every Drupal deployment (library, dsf,
  future ones) shares, so it owns the shared task (and the SP side of the contract). The Drupal
  apps inherit the check for free via the common deploy machinery.
- **Documentation** — the "**both sides must share a SimpleSAMLphp/saml2 major**" contract note
  lives in this devops-docs site (`authentication-netbadge.md`), since the netbadge repo has
  nowhere to put it and the contract spans repos regardless. If drupal-netbadge later grows its
  own docs, the note can move or be mirrored — but there's no reason to gate this on that.

## To iterate / open questions

- Confirm the netbadge container's `vendor/composer/installed.json` path (and that the drupal
  path is stable).
- Compare on `simplesamlphp/saml2`, or on `simplesamlphp/simplesamlphp` core? (saml2 is a good
  session-format proxy; core may be more direct.)
- Does netbadge's *own* independent deploy also run the check (against whatever drupal images are
  live), or only the drupal-side deploys?
- Where the shared task file physically lives — `terraform-infrastructure` shared tasks (next to
  `theme_deployment_task.yml`) vs. sourced from the netbadge repo.
- Keep advisory forever, or offer an opt-in gating mode? (Lean advisory.)
