---
status: deferred
opened: 2026-06-29
jira: null
---

# Follow-up: Standardize SimpleSAMLphp config-as-code on staging (and prod)

**Status:** open follow-up (deferred 2026-06-25)
**Repo:** `uvalib/terraform-infrastructure` → `library.virginia.edu/staging/ansible/`

## Background

The `develop` deploy playbook was reworked (commit `a2bc6ec36`, 2026-04-10, xw5d:
*"Updated deploy_backend.yml … to use new environment handling and to deploy
simplesamlphp"*) to manage SimpleSAMLphp configuration **as version-controlled code**.
This was **never ported to `staging`** (or production). On 2026-06-25 we ported only the
safe, file-free operational steps ("item A": `composer install`, `drush cr`,
`apache2ctl configtest` + `apache2 reload`, `docker_prune`). This doc covers the
remaining "item B".

## What "B" entails

Bring staging's `deploy_backend.yml` (and `deploy_netbadge.yml`) up to the develop
standard:

- **Split env handling:** `container.env` + `container.env.managed` + ccrypt-encrypted
  `container.env.secret.cpt`, merged at deploy time; plus `ENVIRONMENT` / `DEVOPS_LABEL`
  metadata and the Matomo (`DSF_MATOMO_*`) warning checks.
- **SimpleSAMLphp config-as-code:** a `files/var/simplesamlphp/` tree
  (`config/{config.php,authsources.php}`, `metadata/saml20-idp-remote.php`,
  `drupal-config/simplesamlphp_auth.settings.yml`), bind-mounted into the container under
  the new `simplesamlphp/{cert,config,metadata,log,drupal-config}` layout.
- **Apply Drupal SAML config on deploy:** `drush cim -y --partial
  --source=/var/simplesamlphp/drupal-config` (imports `simplesamlphp_auth.settings.yml`).
- `enable simplesamlphp_auth` + `set necessary directory permissions` steps.

## Why it was deferred (not drop-in)

Staging is **missing** the supporting files this mechanism requires:
`container.env.managed`, `container.env.secret(.cpt)`, and the entire
`files/var/simplesamlphp/**` tree. These must be authored with **staging-specific** values
(base URL, entity ID, IdP metadata, cert, and an env-correct
`simplesamlphp_auth.settings.yml` — develop's has `debug: true` and specific
`default_login_users` that must not be copied verbatim). Because the deploy imports those
settings via `drush cim` on every run, wrong values directly break NetBadge login.

## Recommended approach

- Do this deliberately, ideally with **xw5d** (owns the SAML mechanism).
- Build the staging supporting files first; dry-run; verify NetBadge login on staging
  before relying on it.
- Then apply the same to **production** (note: prod has **two** nodes and uses
  `container_0.env` / `release` tags per `DEPLOY_SCRIPT_DIFFERENCES.md`).
