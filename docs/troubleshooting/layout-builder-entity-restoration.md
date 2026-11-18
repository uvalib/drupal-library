# Restoring content entities from a raw DB dump (Layout Builder / block_content)

Came up recovering content lost to the [prod↔dev ID-collision
trap](../incidents/2026-08-10-dls-68-dev-content-loss-and-recovery.md). Two gotchas worth
keeping if this ever needs doing again — for a **node**, prefer the real Entity API
(`Node::create()->save()` etc.); these are specifically about restoring rows **by hand** into
a live table, which was necessary for the `block_content` entities a Layout Builder page
referenced.

## Don't hand-parse `mysqldump` output

`mysqldump`'s extended-insert rows are one-row-per-line, but the row itself is a MySQL
string literal containing a PHP-serialized blob (config `data` columns, Layout Builder
`Section` objects, etc.) — backslash-escaped (`\"`, `\'`, `\n`) because this instance runs
with `ANSI_QUOTES` in effect. A hand-rolled unescape pass silently truncated the last 322
bytes of a 13KB CSS value on the first attempt — the truncation wasn't visible until it was
checked against the byte length PHP's own serialize format declares (`s:<N>:"..."`).

**Fix:** don't unescape it yourself. Import the actual row into a throwaway local MySQL
container (`docker run --rm -d mysql:8.0 --skip-ssl`) and let MySQL's real parser handle the
escaping, then read the value back out with PHP's real `unserialize()` (or just
`SELECT ... ` it back for a plain string field). Verify the recovered length against what the
serialize header declares before trusting it.

## `block_content` base fields live in `*_field_revision`, not `*_field_data`

Restoring a `block_content` entity by raw SQL insert requires **`block_content_field_revision`**
— easy to miss, because the entity still *renders* fine without it (rendering resolves the
default revision's fields via a different path), and the gap only surfaces the moment someone
tries to **edit** it:

```
Drupal\Core\Entity\EntityStorageException: SQLSTATE[23000]: Integrity constraint violation:
1048 Column 'default_langcode' cannot be null: INSERT INTO "block_content_field_data" ...
```

Tracing the actual query (`\Drupal\Core\Database\Database::startLog()` around
`BlockContent::load()`) shows why:

```sql
SELECT "revision".*, "data"."reusable" AS "reusable"
FROM "block_content_field_revision" "revision"
LEFT OUTER JOIN "block_content_field_data" "data"
  ON ("revision"."id" = "data"."id" AND "revision"."langcode" = "data"."langcode")
WHERE ("revision"."id" IN (:id)) AND ("revision"."revision_id" IN (:revision_id))
```

`SqlContentEntityStorage` reads the translatable base fields (`default_langcode`, `status`,
`changed`, `revision_translation_affected`, `info`) from **`block_content_field_revision`**
for a given revision. `block_content_field_data` only contributes `reusable` via a
`LEFT OUTER JOIN`. If `block_content_field_revision` has no row for that
`(id, revision_id)`, every base field the *query itself* selects reads back `NULL` at the PHP
entity level — even though `block_content_field_data`'s own row for that id/revision looks
completely correct. When Layout Builder then clones the entity to create a new revision (the
normal "edit an inline block" flow), it inherits those nulls and the `INSERT` fails the
`NOT NULL` constraint.

**The full table set to restore for a `block_content` entity by hand:**

| Table | What it holds |
|-------|---------------|
| `block_content` | base row: id, current default `revision_id`, `type`, `uuid`, `langcode` |
| `block_content_revision` | revision metadata: `revision_id`, `revision_created`, `revision_user`, `revision_default` |
| `block_content_field_data` | current values of translatable base fields (`info`, `status`, `default_langcode`, `reusable`, ...) — read for `reusable` and rendering |
| **`block_content_field_revision`** | **the same base fields, per-revision — this is what entity *loads* actually query** |
| `block_content__<field>` / `block_content_revision__<field>` | each configured field's current/per-revision values (`body`, `field_card_image`, etc.) |

**How to check you have it right:** load the entity via the real API and read a base field —
`NULL` means the revision table is missing or mismatched, regardless of what the raw row in
`block_content_field_data` looks like:

```php
$block = \Drupal\block_content\Entity\BlockContent::load($id);
var_export($block->get('default_langcode')->value); // must not be NULL
```

Then, as a stronger check, actually simulate the operation that broke — clone and save a new
revision:

```php
$dup = $block->createDuplicate();
$dup->setNewRevision(TRUE);
$dup->save(); // should succeed cleanly
```
