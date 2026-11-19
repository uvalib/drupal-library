# uvalib_block_guard

Fault isolation for block rendering.

## Why

Drupal core has no per-block failure isolation. If one block throws while it
renders, the exception propagates and the **whole page** returns HTTP 500.

On 2026-11-18 (12:31–12:55 ET) a single featured news story with an image but
no link made the `home_news_featured` Views block throw
`InvalidArgumentException: The internal path component 'http://' is external`.
That took down the homepage on both prod nodes and failed the ALB health check
(`GET /`) for 24 minutes. See `docs/incidents/` for the write-up.

## What it does

When a block throws while rendering, the module:

1. logs it (channel `uvalib_block_guard`, severity error, with plugin ID and
   stack trace),
2. restores the renderer's render context (core leaves it pointing at an
   abandoned context, which would make later blocks lose their cache tags and
   libraries),
3. renders a small inline notice in its place, with `max-age` 60 and cache tags
   that invalidate it on content or view-config changes, so it retries within a
   minute and self-heals once the cause is fixed:
   * **Everyone** sees "This content is temporarily unavailable." (no detail).
   * **Users with the `access site reports` permission** (the people who can read
     the log) see "There was an error rendering this content. Please check the
     logs for details." plus the block plugin ID and the exception, HTML-escaped
     and capped at 300 characters. The notice is cached per permission set
     (`user.permissions` context),
4. caps the **page cache** lifetime of any response that contains a failure
   (`DegradedResponseSubscriber`, see below).

The rest of the page renders normally.

### Why the page cache needs its own cap

The block placeholder's `max-age: 60` is honored by the render cache and the
dynamic page cache, but **not** by Drupal's internal page cache, which serves
most anonymous traffic. It takes its expiry from the `Expires` header, and core
sets that to a date in the past, which stores the page *permanently* until a
cache tag is invalidated. A homepage degraded by a transient error (say a
database deadlock inside one block) would then stay degraded until the next
content change. Every degraded placeholder therefore carries the cache tag
`uvalib_block_guard:degraded`, and `DegradedResponseSubscriber` sets `Expires`
to now + 60 s and lowers a public `Cache-Control: max-age` to 60 for responses
with that tag. Other responses are untouched.

## Coverage

| Path | Mechanism |
|---|---|
| Layout Builder components (homepage `/node/2036`) | `UvalibBlockGuardServiceProvider` swaps `layout_builder.render_block_component_subscriber` for `GuardedBlockComponentRenderArray` |
| Blocks placed in theme regions | `uvalib_block_guard_entity_type_alter()` swaps the `block` view builder for `GuardedBlockViewBuilder` |
| Page cache lifetime of a degraded page | `DegradedResponseSubscriber` (`uvalib_block_guard.services.yml`) |

Not covered: failures outside block rendering (routing, the page controller,
theme preprocess for the page itself) still return 500, as they should.

## Deliberately not swallowed

* `HttpExceptionInterface` (403/404/…) and `EnforcedResponseException` (form
  redirects) are control flow, not failures, and keep propagating.

## Trade-offs and operations

* **A swallowed error is a silent error.** The page returns 200 with a block
  missing. **Alert on `uvalib_block_guard` log entries** (watchdog type
  `uvalib_block_guard`) or the failure will go unnoticed. Without an alert this
  module hides outages instead of shortening them.
* It also contains transient database errors (e.g. deadlocks) inside a block.
  A real database outage still fails routing and sessions and returns 500.
* `BlockRenderGuard::accessContext()` reads Renderer's protected static
  `$contextCollection` through a scope-bound closure, because core exposes no
  accessor. If core changes it, the guard degrades to "log and skip without
  restoring context" and the Kernel test `testRenderContextIsRestored` fails,
  so re-run the tests on every core upgrade (Drupal 11 included).

## Enabling

Like `devops_docs`, this ships **disabled**; enabling is a separate step per
environment:

```bash
drush en uvalib_block_guard -y && drush cr
```

Verify by checking `/` still returns 200 and that a failing block is logged:
`drush watchdog:show --type=uvalib_block_guard`.

## Tests

Kernel tests in `tests/src/Kernel/BlockRenderGuardTest.php` (fixtures in
`tests/modules/uvalib_block_guard_test`):

```bash
cd web/core && SIMPLETEST_DB=sqlite://localhost//tmp/ubg.sqlite \
  ../../vendor/bin/phpunit -c . ../modules/custom/uvalib_block_guard/tests
```
