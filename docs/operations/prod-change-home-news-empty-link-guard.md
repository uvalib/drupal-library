# Prod change: guard empty `field_link` in the homepage news views

Status: applied and verified on **staging** 2026-11-18. **Not yet applied to prod.**
Cause and background: the 2026-11-18 homepage outage (~12:32-12:58 ET). See also PR #22 (`uvalib_block_guard`).

## What and why

Three Views fields build their link from `{{ field_link }}?mtm_campaign=...&mtm_kwd=...`. When a news story has no
`field_link`, the path becomes `http://?mtm_...`, `FieldPluginBase::renderAsLink()` throws `InvalidArgumentException`,
and the whole Layout Builder homepage returns a 500. The fix wraps each path in `{% if field_link %}...{% endif %}`,
so a story without a link renders as plain text instead of throwing.

| View | Display | Field | New "Rewrite results > Output this field as a link > Link path" |
|---|---|---|---|
| `home_news_featured` | default | `field_library_news_image` | `{% if field_link %}{{ field_link }}?mtm_campaign=home-news-featured&mtm_kwd={{ field_keyword_for_analytics }}{% endif %}` |
| `home_news_featured` | default | `title` | same as above |
| `home_news` | default | `title` | `{% if field_link %}{{ field_link }}?mtm_campaign=home-news&mtm_kwd={{ field_keyword_for_analytics }}{% endif %}` |

Everything else in each field's settings stays as is, including the "External server URL" flag.

Prod has one shared database, so apply once. Both nodes pick it up after the cache clear.

## Option 1: Views UI (about 5 minutes)

1. `/admin/structure/views/view/home_news_featured`. Open the field `field_library_news_image` > Rewrite results > Link path, and replace the value with the one in the table. Repeat for `title`. Save.
2. `/admin/structure/views/view/home_news`. Do the same for `title`. Save.
3. `drush cr`.

## Option 2: drush script (idempotent)

Save as `/tmp/fix.php` in the `drupal-0` container and run `drush php:script /tmp/fix.php`, then `drush cr` and `rm /tmp/fix.php`.
Do not use `drush sql:*` against prod (RDS TLS chain issue); `php:script` goes through Drupal's own DB connection.

```php
<?php
foreach ([['home_news_featured', 'field_library_news_image'], ['home_news_featured', 'title'], ['home_news', 'title']] as [$id, $f]) {
  $v = \Drupal\views\Entity\View::load($id);
  $d = $v->get('display');
  $p = $d['default']['display_options']['fields'][$f]['alter']['path'];
  if (str_starts_with($p, '{% if')) { continue; }
  $d['default']['display_options']['fields'][$f]['alter']['path'] = '{% if field_link %}' . $p . '{% endif %}';
  $v->set('display', $d);
  $v->save();
  echo "$id/$f -> " . $d['default']['display_options']['fields'][$f]['alter']['path'] . "\n";
}
```

## Verify

Do not create test content on prod.

1. Read the config back and confirm all three paths start with `{% if field_link %}`:
   `drush cget views.view.home_news_featured display.default.display_options.fields.title.alter.path`
   (and the same for `field_library_news_image`; for `home_news` use `views.view.home_news`).
2. `curl -s -o /dev/null -w '%{http_code}' https://library.virginia.edu/` returns 200 on both nodes.
3. The "Library news" column and the featured story still render, with working links. A story that has a link
   must still produce `?mtm_campaign=home-news...` links, so spot-check one link target.

## Rollback

Remove the `{% if field_link %}` prefix and `{% endif %}` suffix from the three paths (UI or script), then `drush cr`.

## Caveats

- A story with an image but no link now renders unlinked. It is no longer an outage, but editors get no warning.
  If that matters, add a required-link rule or a validation on `library_news_`.
- The config-sync export is automated and was not edited by hand. After prod changes, confirm the next automated
  export to `drupal-library-config-sync` picks up all three field paths, so a later config import does not undo the fix.
- Staging and dev carry the fix only in their databases. The weekly Monday prod→dev DB clone overwrites dev, so dev
  loses it until prod has it.
- This guards only the empty-link case. `uvalib_block_guard` (PR #22) covers any other block failure.
