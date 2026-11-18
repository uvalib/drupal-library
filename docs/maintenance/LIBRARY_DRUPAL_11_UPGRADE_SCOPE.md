# Drupal 11 Upgrade: Scope and Schedule (Library)

| | |
|---|---|
| **Written** | 2026-11-10 |
| **Deadline** | 2026-12-09 (Drupal 10 end of life) |
| **Target** | Drupal 11.4.x, PHP 8.4, Drush 13 |
| **Companion** | [drupal-11-upgrade.md](drupal-11-upgrade.md) (original plan); DSF has its own scope note in `drupal-dsf/docs/DSF_DRUPAL_11_UPGRADE_SCOPE.md` |

> **Basis and caveats.** This is a static read of `composer.json`, `composer.lock`,
> `core.extension.yml` and drupal.org release data (checked 2026-11-10). `upgrade_status`
> was run afterwards (see Scan results below). The external repos cloned at build time were only partly covered
> (`dml`, `uvaldap`, `drupal_jsonapi_search_api_extension`, `uvalib_drupal_theme_2026`).
> Treat estimates as rough until step 1 is done.

## Current state

- Core 10.6.19, Drush 12.5.3, 210 locked packages.
- Production image `drupal:10.6.19-php8.4-apache`, so PHP is already 8.4.
- **ddev is PHP 8.2 / `type: drupal10`**, which Drupal 11 will not run on.
- Production runs two nodes behind the ALB; staging and dev are single-node.
- Target 11.4.x rather than 11.0: `rdf` 4.0 needs core 11.3 or later, and `simplesamlphp_auth` 4.1 needs 11.3 or later.

## Blockers

### Core modules removed in D11 (enabled here)
`color`, `quickedit`, `rdf`. Uninstall them, or move to the contrib versions
(`quickedit` 2.x, `rdf` 4.0, `color` 2.0 alpha), on D10 **before** the core bump.
Check for config that depends on them (color theme settings, RDF mappings).

### Contrib with no D11 release (enabled)
| Module | Locked | Latest | Action |
|---|---|---|---|
| `custom_search` | 1.0-beta9 (`^10`) | same release; `8.x-1.x` dev branch declares `^10 \|\| ^11 \|\| ^12` | **Must stay (decision 2026-11-11).** Pin `1.x-dev#ab9e188` on D10 first, plus a one-line patch for the `plugin_id` warning (upstream #3634839). Upstream is largely inactive, so test `/search/node` and `/website-search-results` on staging. |
| `iek` | 1.3 (`^9.3 \|\| ^10`) | same; dev branch has no work since 1.3 | **Remove (recommended; production checked 2026-11-11).** The only user, image style `circle_300x300`, is unused in production (see "Production check" below). Fallback if anyone objects: the bot MR `!1` on upstream issue #3430975 applies cleanly (dry-run) and covers every hit. |
| `save_all_nodes` | 2.0.0 (`^10`) | same | Already carries a local patch. Patch for D11 or replace. |
| `ckeditor` (CKEditor 4) | n/a | `^9.4 \|\| ^10` | Uninstall. CKEditor 5 is already enabled. See [ckeditor4-to-ckeditor5.md](ckeditor4-to-ckeditor5.md). |

### Contrib that needs a version bump
| Module | Locked | Bump to |
|---|---|---|
| `scheduler_content_moderation_integration` | 2.0.0 | 3.x (`^10.3 \|\| ^11`) |
| `webform` | 6.2.10 (`^10.2`) | 6.3.x |
| `webform_rest` | 4.1.0 | 4.2.x |
| `cache_utility` | 1.3.0 (`^9 \|\| ^10`) | 1.4.x |
| `exclude_node_title` | 1.4.0 | 2.0.x |
| `w3css_paragraphs` | 1.0.19 | 1.0.20 |
| `d8w3css` | 2.0.16 | 2.0.18 |

Everything else in the lock already declares `^11`.

### Packages to remove from `composer.json` (not enabled)
`drupal/rules` (alpha; 3.x to 4.x is a rewrite), `drupal/typed_data`, `drupal/smartmenus`,
`drupal/devel_entity_updates`, `drupal/classy-classy`. None appears in `core.extension.yml`.

### Custom code
- `uvalibrary_v2a.info.yml`: `core_version_requirement: ^9|^10`. Update it, and verify the base theme `drupal8_w3css_theme`.
- `devops_docs` module: small, check its `info.yml`.
- External repos (see caveat above): deprecation scan and `core_version_requirement` update on each.

### Infrastructure
- `package/Dockerfile`: base to `drupal:11.4-php8.4-apache`; drop the pinned 10.6.19 comment block.
- `composer.json`: `drupal/core`, `core-recommended`, `core-scaffold`, `core-project-message` to 11.x; `core-dev` to `^11`; `drush/drush` to `^13`.
- `.ddev/config.yaml`: `type: drupal11`, `php_version: "8.4"`.
- Pipeline specs and PHP version references.
- `simplesamlphp_auth` against the shared netbadge container.
- Re-check the `save_all_nodes` patch path after any bump.
- `mandala-navina` already runs D11 and can serve as a Dockerfile and pipeline reference.

## Scan results (`upgrade_status`, run 2026-11-10 in ddev, 86 extensions)

`upgrade_status` is not in Library's `composer.json`. It was added temporarily for the scan and `composer.json` and `composer.lock` were then restored byte-for-byte. Most of the large "check manually" counts in contrib (Search API, Webform, Paragraphs, Geolocation) are "library not defined" noise, not blockers. The counts that matter are the removed-in-D11 API calls.

**Removed-API calls in modules we keep or need to decide on:**

| Extension | Result |
|---|---|
| `iek` 1.3 | 27 hits, all one rename: `GDToolkit::getResource()` (23) and `setResource()` (4), replaced by `getImage()` and `setImage()` (available since core 10.2). The upstream bot MR does exactly this in 7 files plus the version constraints, and applies cleanly to 1.3. Used by one image style only: `circle_300x300` (WebP, focal-point crop, `iek_image_corner` radius 150), which no config, content or theme code references. **A trivial port if kept.** *(An earlier version of this row listed unrelated function names and called it a rewrite. That was a parsing error, now corrected.)* |
| `custom_search` beta9 | The static-analysis flags (undefined function and variable in `CustomSearchBlock.php`) were not shown to fail at runtime, and the dev branch cleans that code. Site usage: configures core's `/search/node` and `/website-search-results` advanced search forms; none of its blocks is placed. **Keep, via the dev pin above.** |
| `save_all_nodes` | Only metadata blocks it (`core_version_requirement: ^10`, `composer.json` core constraint). A patch-level fix is plausible. |
| `entity_reference_revisions` 1.14 | 19 hits. Check for a newer release after the bump. |
| `paragraphs` 1.20 | 14 hits. Re-check after the bump. |
| `webform` 6.2.10 | 11 hits. Resolved by 6.3.x. |
| `quickedit`, `rdf` | As expected (core-removed modules). |
| `rules`, `devel_entity_updates` | Hits, but neither is enabled. Remove from `composer.json`. |

**Custom code:**

- `dml` (`uvalib/dml`, monorepo): one `drupal_get_path()` call, declares `^8 || ^9`, and **is disabled on staging and both production nodes**. Remove it from `composer.json` (see sizing below).
- `uvalibrary_v2a` theme: `core_version_requirement: ^9`, plus the `base` library depends on removed `core/modernizr`.
- `jsonapi_search_uvalib`: `^9`. `uvaldap`: `^9 || ^10`. Both are metadata only in this scan.
- `devops_docs`: clean.
- `uvalib_drupal_theme_2026`: scanned separately on 2026-11-11 (`upgrade_status` skips custom themes that aren't installed, so it was installed in the local DB for the scan and uninstalled again). **No known issues found.** It already declares `core_version_requirement: ^10.3 || ^11`, has no `.theme` PHP file, and its base theme is `bootstrap` 5.0.2, which supports D11.
- `classy-classy` 1.0.0-alpha3 is installed but not enabled; remove it.

## Web-component embeds to retest (production DB scan, 2026-11-11, read-only)

The site's JavaScript web components (`unpkg.internal.lib.virginia.edu`) are **not** loaded by any module or theme. Editors paste `<script type="module" src="...">` plus a custom tag into body fields and a view header, so the embeds live in the database and depend on the `full_html` text format not stripping `<script>` or custom elements. Found by scanning current-revision text columns of `node__*`, `block_content__*`, `paragraph__*`, `media__*`, `taxonomy_term__*` and `config` for the unpkg host (prod node 0; revisions not scanned).

| Where | What | Component (version) |
|---|---|---|
| Block 71 "Home: Library hours" | home page | `site-hours-section` (2.2.35) |
| Block 73 "Home: Library events" | home page | `events-section` (2.0.8) |
| Block 88 "Analytics tracking code" | every page | `uvalib-analytics` (0.0.12, Matomo site 10) |
| Block 101 "RDS: Events feed" | events feed | `events-section` (2.0.8) |
| Block 105 "Upcoming exhibition events" | exhibitions | `events-section` (2.0.8) |
| Block 1142 "AI: Upcoming events feed" | events feed | `events-section` (2.0.8) |
| Block 1183 "Events" (type `card`) | card block | `events-section` (2.0.8) |
| View `library_hours_view_locations_new_2023_` header | `/hours` | `library-hours-setup` (2.2.40) |
| Node 786 `/equipment-and-tech` | page body | `events-section` (2.0.8) |
| Node 837 `/search` | page body | `bento-box` (2.2.38) |
| Node 2569 `/locations/shannon/grand-opening` | page body | `events-section` (2.0.8) |

**Staging QA:** load `/`, `/hours`, `/search`, `/equipment-and-tech`, `/locations/shannon/grand-opening` and check the analytics script appears on every page. Confirm `full_html` still allows `<script>` and these custom tags after the CKEditor 4 removal and the D11 bump.

**Limits:** the scan matches only the unpkg host, so an embed from another host or a bare custom tag without a script would be missed. Revisions and unpublished drafts were not scanned. Theme templates and config-sync were checked by grep and hold only the `/hours` view.

## `composer audit` (run 2026-11-11 against the locked versions)

71 advisories across 29 packages. They fall into three groups:

| Group | Packages | Why it matters here |
|---|---|---|
| **Drupal contrib, fix available on D10** | `webform` 6.2.10 (**23 advisories, including one critical RCE, SA-CONTRIB-2026-175**; 6.3.1 available), `webform_rest` 4.1.0 (4.2.2), `geolocation` 3.14.0 (**critical SQL injection**; 4.1.0 is a major bump), `mathjax` 4.1.0 (critical XSS; 4.1.2), `externalauth` 2.0.11, `scheduler` 2.2.2 (2.3.1), `content_moderation_notifications` 3.8.0 (3.9.0), `paragraphs` 1.20.0 | All enabled in config except `paragraphs` (not in `core.extension.yml`; it is present only as a dependency). **These are live production exposures today, independent of D11.** |
| **SimpleSAMLphp** | `simplesamlphp/simplesamlphp` 2.4.5 (2.4.11 available), `saml2` 5.0.5, `saml2-legacy` 4.19.2 | High severity: cross-IdP auth bypass and unexpected-IdP responses. Login goes through NetBadge, so check this first. |
| **Symfony / Guzzle / tooling** | `symfony/*` 6.4.48 (about 20 advisories, fixes in 6.4.50 and later), `guzzlehttp/guzzle` 7.10.6 (9, mostly medium), `enshrined/svg-sanitize`, `composer/composer` and `squizlabs/php_codesniffer` (dev tooling) | Most Symfony ones are patched by the core bump (D11 pulls Symfony 7.x). Core 10.6.21 also exists. |

**Prepared 2026-11-11:** branch `security/webform-simplesamlphp-updates` (one commit, `composer.lock` only, not pushed) updates webform and its sub-modules to 6.3.1, `webform_rest` 4.2.2, `simplesamlphp/simplesamlphp` 2.4.11, `saml2` 5.0.8 and `saml2-legacy` 4.19.6. `composer audit` drops from 71 to 46 advisories, with none left for webform or `simplesamlphp/*`. Webform 6.3.1 brings 12 pending `updb` hooks, including a new index on `webform_submission_data` (check the table size on production before deploying). The NetBadge SP container (`drupal-netbadge`) has its own lock and needs the same SimpleSAMLphp patch.

**Not verified:** I read the advisory titles and the available versions. I did not confirm that each newer release fixes each advisory, or that every affected code path is reachable on this site.

**Effect on the plan:** the webform, geolocation, mathjax and SAML fixes should not wait for the D11 cutover. Treat them as the first items of the pre-upgrade week (or sooner), and take `webform` 6.3.1 anyway since it is already on the bump list. `geolocation` 4.x is a major version, so check it separately.

## `dml` sizing (Drupal Module Loader, `uvalib/dml`)

- **Size:** one 91-line `.module` file plus `map.yml` (16 web-component to CDN-URL mappings). No classes, no services.
- **D11 incompatibilities:** `drupal_get_path()` (1 call, in `drupal_module_loader_get_map()`; replace with `\Drupal::service('extension.list.module')->getPath('drupal_module_loader')`), `core_version_requirement: ^8 || ^9`, and `composer.json` requires `symfony/yaml ^4.4 || ^5.0`. That constraint would conflict with D11's Symfony 7, but the root `composer.json` defines `uvalib/dml` as a `package` repository that carries no `require`, so it does not bite in practice. Fix it anyway in the monorepo. *(The earlier "7 places" count was the scan's repeated reporting of this one call, not 7 call sites.)*
- **Estimate:** none if removed (recommended). About 1-2 hours including a monorepo release if someone wants to keep it.
- **Not enabled anywhere (checked 2026-11-11, read-only `drush pm:list`).** `drupal_module_loader` shows **Disabled** on staging, production node 0 and production node 1. It is installed by composer but not turned on, and it is not in `core.extension.yml`. The hours view does not depend on it: `views.view.library_hours_view_locations_new_2023_` loads its own `<script type="module" src="https://unpkg.internal.lib.virginia.edu/web-components/cdn-v2.2.40/...library-hours-setup.js">` inline. **Action: remove `uvalib/dml` and its `repositories` entry from `composer.json`.** No code work is needed.
- **Existing smell if it is ever enabled:** `drupal_module_loader_page_bottom()` calls `error_log()` with the entire rendered HTML of every page, and renders `$page_bottom` twice.

## Production check: `iek` / `circle_300x300` (2026-11-11, read-only)

Run against both production nodes (`library-drupal-0` and `-1`, container `drupal-0`, Drupal 10.6.19). Nothing was changed.

| Check | Result |
|---|---|
| Style and module present in prod | Yes. `image.style.circle_300x300` exists with effects `image_convert`, `focal_point_scale_and_crop`, `iek_image_corner`. `iek` is installed. |
| Derivatives on disk (both nodes) | **1 file**: `styles/circle_300x300/public/core/modules/image/sample.png.webp`, dated 2025-10-29. That is the sample image Drupal generates for the preview on the style's edit page, not content. For comparison: `thumbnail` has 1,522, `medium` 804, `max_325x325` 341. |
| Config objects referencing the style | Only the style itself. No view display, view, block or other config uses it. |
| Content referencing the style | None, across 183 text columns in `node*`, `paragraph*`, `block_content*`, `media*` and `webform*` tables. |
| Access logs | 0 requests for `circle_300x300` in the available window (about 36 hours, ~350k lines per node, container stdout only). `/styles/thumbnail/` has 25-26 in the same window. |

**Reading:** any real use of this style in the past year would leave derivative files, and there are none. The log window is short, but the missing derivatives are the stronger evidence. **Conclusion: unused. Remove the style and uninstall `iek`.**

Removal steps (on D10, in the pre-upgrade week): delete `image.style.circle_300x300` (uninstall is blocked while config depends on the module), uninstall `iek`, export config, remove `drupal/iek` from `composer.json`, then delete the leftover `files/styles/circle_300x300` directory.

## Schedule

| Week | Work |
|---|---|
| **Nov 10-14** | Run `upgrade_status` in ddev. Security-patch webform, geolocation, mathjax and SimpleSAMLphp on D10 (see `composer audit`). Decide `custom_search` / `iek` / `save_all_nodes`. Review the external repos. |
| **Nov 17-21** | Pre-upgrade on D10: uninstall removed core modules and CKEditor 4, bump contrib, remove unused packages, fix custom code. Deploy to staging (still D10). |
| **Nov 24-25** | Core 11.4 + Drush 13 + Dockerfile on a branch. `updb`, `cim`, fix breakage. Thanksgiving follows. |
| **Nov 30-Dec 4** | Staging soak and QA: Layout Builder, Webform, SAML login, search/Solr, content moderation, scheduler, `drush cr`. Cypress suites. |
| **Dec 7-8** | Production cutover in a maintenance window, **both nodes**. |
| **Dec 9** | Buffer only. |

## Risks

1. `custom_search` (kept via an unmaintained dev branch) and `save_all_nodes` (local patch): both depend on code nobody upstream is maintaining, so staging QA matters. `iek` is resolved: unused in production, so it is removed rather than ported.
2. Theme and external repos may hide a lot of deprecated code.
3. Two-node production needs a deploy-order plan and a tested rollback image.
4. DB backup and restore rehearsal before cutover.
5. Short buffer: any slip in week 3 eats the margin.

## Definition of done

- Prod and staging on Drupal 11.4.x, `drush status` and `/admin/reports/status` clean.
- No removed core modules enabled; no unsupported contrib in the lock.
- ddev, Dockerfile and pipeline all on the same PHP and core line.
- This doc and the stub updated with the outcome.
