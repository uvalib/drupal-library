# Session Log: DevOps Docs Module (/devops-docs)

**Date:** 2026-07-01
**Participants:** Yuji Shinozaki, Claude (Sonnet 5, then Opus 4.8 mid-session)
**Branch:** `main`
**Outcome:** Built a new `devops_docs` Drupal module that publishes this repo's mkdocs documentation in-app at `/devops-docs`, NetBadge-authenticated and gated by a dedicated `devops` role. Complete and verified in local ddev; not yet committed/deployed at time of writing. (The `preview.library.virginia.edu` redirect work from the same day has its own log, `2026-07-01-preview-hostname-redirect.md`.)

---

## Design

Goal: publish the `mkdocs`-built `docs/` site inside Drupal at `/devops-docs`, NetBadge-protected and role-gated, rather than at the Apache layer. Same container image ships to all environments, so access control is purely a Drupal permission question.

**Why Drupal-level, not Apache-level:** NetBadge login already works end-to-end via `drupal/simplesamlphp_auth` (confirmed active on the config-sync `production` branch). Reusing it avoids inventing Apache-level SAML gating, which SimpleSAMLphp doesn't offer generically the way Shibboleth does. An Apache `Alias` + a bespoke PHP `Auth\Simple::requireAuth()` shim was considered and rejected as more custom code for less reuse.

**Risk assessment:** checked whether NetBadge/LDAP group membership maps to Drupal roles — it does **not** (`role.population: ''` in `simplesamlphp_auth.settings.yml`; the `uvaldap` module only enriches a "Person" content type, nothing to do with auth). Crucially, `register_users: false` means SAML login does **not** auto-create Drupal accounts — only people who already have a curated Drupal account can log in at all. That bounds the blast radius of any permission misconfiguration to the existing editor/admin user list, not the whole university. Conclusion: low risk, provided we use a **dedicated permission on a dedicated role**, never "authenticated user."

## Implementation

Module embedded at `package/data/opt/drupal/web/modules/custom/devops_docs/` (not a separate repo — deliberate, it's site-specific and coupled to this Dockerfile).

- **`view devops docs`** permission (`restrict access: true`); **`devops`** role shipped as `config/install/user.role.devops.yml` (auto-imports on module enable); `hook_install()` seeds users `ys2n` and `xw5d` onto the role if those accounts exist.
- **Docker:** a multi-stage `docs-builder` stage (`python:3.12-slim` + `git` — the git-revision-date plugin needs the real git binary, caught by test-building) runs `mkdocs build`; only the static output is copied into the final image, wired in via the same self-clone/symlink pattern the Dockerfile already uses for `config`/`patches`/`vendor-archive`.
- Ddev post-start symlink so the module is visible locally; `.gitignore` for the built `static/` dir (it's a generated artifact — see below).

### Why `static/` is gitignored

`static/` holds the **mkdocs build output** (compiled HTML/CSS/JS), a generated artifact. The source of truth is `docs/` (markdown) + `mkdocs/mkdocs.yml`. It's produced fresh by the Docker `docs-builder` stage at image-build time for dev/staging/prod. Committing it would duplicate every doc as compiled HTML, churn huge diffs on every docs edit, and risk source/build drift — same reasoning as the pre-existing `mkdocs/site/` ignore. It exists in the working tree only because it was built locally for ddev preview (ddev doesn't run the Docker build stage).

### The routing saga (why it's an event subscriber, not a route)

First attempt used a Drupal route + controller + inbound path processor. This fought Drupal hard:
- Drupal's `RouteProvider` matches routes by **exact path-segment count**, so a single `/devops-docs/{sub_path}` route (a plain-Symfony `.*` trick) can never match deeper paths. Worked around with a path processor collapsing everything to `/devops-docs`.
- That parameterless route then tripped the **`redirect` module's URL normalizer**, which 301'd every nested path back to bare `/devops-docs`. The documented `_disable_route_normalizer` escape hatch mostly worked — but trailing-slash URLs hit an intermittent failure traced (via debug subscribers logging `spl_object_id`) to **two different Request objects** in one request: the flag was set on one, the normalizer read another.

Rather than keep fighting this, **the whole approach was replaced** (after a model switch to Opus 4.8) with a single early `KernelEvents::REQUEST` subscriber (`DevopsDocsRequestSubscriber`, priority 100 — after authentication at 300, before Symfony's `RouterListener` at 32). It owns the entire `/devops-docs` subtree and short-circuits before routing, so the router, path processing, and the redirect module never run for these paths. The route, controller, and path processor were all deleted.

The subscriber:
- Manually checks the `view devops docs` permission (throws `AccessDeniedHttpException` → 403).
- Serves assets (extensioned paths) directly; for mkdocs "pages" (built as `foo/index.html` with page-relative asset links) redirects bare directory URLs to add a trailing slash, so relative asset links resolve correctly — this was the user-reported "styling is messed up" symptom.
- Sets **explicit Content-Type per extension** — finfo mis-detects `.css`/`.js` as `text/plain`, which browsers reject under strict MIME checking (the other half of the styling bug).
- Guards path traversal with `realpath()` + docs-root prefix check.
- Sets a **bare stand-in route object** on the request before throwing 403/404, because core's `CsrfExceptionSubscriber::on403()` (and the error-page renderer) assume a route object exists and fatal without one when an exception is thrown pre-routing.

Verified in local ddev (full matrix): anon → 403; bare/nested paths → 301 adding trailing slash; trailing-slash → 200; assets → 200 with correct MIME; missing page → 404; no watchdog errors.

## Styling adopted from mandala-navina

Copied the sidebar polish from `mandala-navina/docs/stylesheets/extra.css` into `docs/stylesheets/extra.css` (wired via `extra_css`): bold section labels, deeper indentation on nested nav items, and a bottom-right last-updated date. Then **enabled sidebar accordions** by removing `navigation.sections` from `mkdocs.yml` — that feature had been flattening sections into always-open group headers; without it, mkdocs-material renders them as collapsible accordions (matching mandala). Adjusted the bold-label selector to target the collapsible `--nested` markup. Rebuilt and verified (8 collapsible toggles, 7 nested sections).

---

## Open items (carried forward)

- **Commit + deploy the devops_docs module.** Complete and verified locally, not yet committed/pushed as of end of session.
- **`devops` role into config-sync.** `hook_install()` seeds it on enable, but ongoing role membership isn't captured by the config-sync `production` branch yet — ties into the config-sync mechanism redesign.
- **`hook_install()` runs once** — adding a 3rd docs viewer later needs a manual role grant or an update hook.
