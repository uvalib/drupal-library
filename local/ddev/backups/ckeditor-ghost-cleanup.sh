#!/bin/bash
#
# ckeditor-ghost-cleanup.sh — normalise post-sync module state on a NON-prod env.
#
# Fixes the CKEditor 4 "ghost module" WSOD: when a DB carrying `ckeditor` ENABLED
# (any prod snapshot — prod's DB still has it enabled) lands on code where the
# ckeditor files are REMOVED (anything built from `main` since 5d9513b), Drupal
# fatals on /admin/modules with:
#     UnknownExtensionException: "The module ckeditor does not exist."
# The same prod snapshot also DISABLES `devops_docs` (neither module is in
# config-as-code / core.extension.yml), so this also re-enables devops_docs.
#
# Idempotent and GUARDED — it only acts when the condition actually exists:
#   * removes ckeditor ONLY if it is enabled AND its files are absent (a ghost)
#   * enables devops_docs ONLY if the files are present AND it is disabled
# So it is a safe no-op on a healthy env (e.g. prod/dev where ckeditor's files
# are still present), and never fires spuriously.
#
# INTERIM MITIGATION ONLY. The durable fix is to uninstall ckeditor on prod
# (while its files still exist) — see docs/troubleshooting/ and the CKEditor 4
# removal notes. Once prod's DB no longer has ckeditor enabled, this becomes a
# permanent no-op everywhere and can be retired.
#
# Usage:
#   ./ckeditor-ghost-cleanup.sh [local|dev|staging]     (default: local)
#
# Runs standalone; also invoked automatically at the end of
# update-db-from-remote.sh after a local DB import.

set -euo pipefail

TARGET="${1:-local}"

DEV_HOST="library-drupal-develop-0.internal.lib.virginia.edu"
STAGING_HOST="library-drupal-staging-0.internal.lib.virginia.edu"

case "$TARGET" in
    local)
        run_drush() { ddev drush "$@"; }
        ;;
    dev)
        run_drush() { ssh -o BatchMode=yes "$DEV_HOST" "sudo docker exec -i drupal-0 /opt/drupal/vendor/bin/drush $*"; }
        ;;
    staging)
        run_drush() { ssh -o BatchMode=yes "$STAGING_HOST" "sudo docker exec -i drupal-0 /opt/drupal/vendor/bin/drush $*"; }
        ;;
    prod|production)
        echo "Refusing to run against production." >&2
        echo "pm/config writes on prod's two live nodes risk the shared-cache deadlock" >&2
        echo "(2026-06-26 WSOD). Use the maintenance-mode sequence in the production" >&2
        echo "deploy runbook to uninstall ckeditor on prod instead." >&2
        exit 2
        ;;
    *)
        echo "Usage: $0 [local|dev|staging]" >&2
        exit 64
        ;;
esac

echo "Target: $TARGET"
echo "Checking module state..."

# The guarded logic runs inside the container via drush php:script. It prints a
# human-readable report and, if it changed anything, the marker __CLEANUP_CHANGED__.
if ! out=$(run_drush php:script - <<'PHP'
$changed = false;

$config  = \Drupal::configFactory()->getEditable('core.extension');
$modules = $config->get('module');

// Fresh filesystem view of discoverable modules (independent of enabled state).
$list = \Drupal::service('extension.list.module');
$list->reset();
$on_disk = $list->getList();

// --- CKEditor 4 ghost: enabled in config but files absent ---
$ck_enabled = isset($modules['ckeditor']);
$ck_on_disk = array_key_exists('ckeditor', $on_disk);
if ($ck_enabled && !$ck_on_disk) {
  unset($modules['ckeditor']);
  $config->set('module', $modules)->save();
  \Drupal::keyValue('system.schema')->delete('ckeditor');
  print("ckeditor:    GHOST removed (was enabled, files absent)\n");
  $changed = true;
}
elseif ($ck_enabled) {
  print("ckeditor:    enabled and present -> healthy, left as-is\n");
}
else {
  print("ckeditor:    not enabled -> nothing to do\n");
}

// --- devops_docs: present on disk but disabled (a prod-snapshot sync disables it) ---
$modules_now  = \Drupal::config('core.extension')->get('module');   // re-read after any save
$dd_enabled   = isset($modules_now['devops_docs']);
$dd_on_disk   = array_key_exists('devops_docs', $on_disk);
if ($dd_on_disk && !$dd_enabled) {
  \Drupal::service('module_installer')->install(['devops_docs']);
  print("devops_docs: enabled (was present but disabled)\n");
  $changed = true;
}
elseif ($dd_on_disk) {
  print("devops_docs: already enabled -> nothing to do\n");
}
else {
  print("devops_docs: not present on disk -> skipped\n");
}

if ($changed) { print("__CLEANUP_CHANGED__\n"); }
PHP
); then
    echo "$out"
    echo "Error: cleanup drush script failed against '$TARGET'." >&2
    exit 1
fi

# Strip the marker from the report shown to the user.
echo "$out" | grep -v '__CLEANUP_CHANGED__' || true

if grep -q '__CLEANUP_CHANGED__' <<<"$out"; then
    echo "Changes made; rebuilding caches..."
    run_drush cr
    echo "Done."
else
    echo "No changes needed; module state already clean."
fi
