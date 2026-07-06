---
status: resolved
opened: 2026-06-18
jira: null
---

# DDEV Refuses to Run the Project from This Directory

**Status:** resolved (documented 2026-06-18)

## Symptom

A DDEV command (e.g. `ddev import-db`) fails with:

```
Error: unable to get project: a project (web container) in running state already exists
for drupal-library that was created at /Users/ys2n/Code/ddev/drupal-library
```

…even though you're running it from a different, valid checkout of the same project.

## Cause

DDEV maps **one project *name* to one directory**. If `drupal-library` was started from
another checkout (e.g. `~/Code/ddev/drupal-library`), DDEV won't let a second directory
(`~/Code/uvalib/drupal-library`) adopt the same name while the first is registered. There
is no "this is the same project, allow it" flag.

## Fix

The database lives in a Docker volume keyed by project *name*, so re-pointing the project
to the checkout you want is safe — the DB carries over:

```bash
ddev stop --unlist drupal-library     # release the name from the old path (files untouched)
cd /Users/ys2n/Code/uvalib/drupal-library
ddev start                            # re-registers the project here, reuses the db volume
```

Alternatively, just run DDEV commands from whichever directory currently owns the name.
