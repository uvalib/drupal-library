#!/usr/bin/env bash
#
# Is the Drupal image's PHP base up to date? Exits 0 if the base carries at least the
# minimum PHP, 1 if not (or if it cannot tell).
#
# WHY: package/Dockerfile builds FROM the official `drupal` image, which is itself built FROM
# the official `php` image. When PHP ships a security release, `php` is rebuilt first and
# `drupal` follows later, sometimes days later, so rebuilding our image alone does not pick
# up the fix until Docker refreshes the `drupal` tag. This answers "has it yet?" without
# pulling anything by default, and the build log now prints the PHP version anyway
# (when added to package/Dockerfile) so a build tells you what it got.
#
# Usage:
#   ./scripts/check-php-base-image.sh                 # tag dates only (fast, no pull)
#   ./scripts/check-php-base-image.sh --verify        # pull the drupal tag and read its real PHP version
#   MIN_PHP=8.4.27 ./scripts/check-php-base-image.sh --verify
#
# Without --verify it can only say whether the `drupal` tag was rebuilt after the `php` tag
# (a necessary, not sufficient, sign), so it exits 1 until it can prove the version.
set -euo pipefail

DRUPAL_TAG="${DRUPAL_TAG:-10.6-php8.4-apache}"
PHP_TAG="${PHP_TAG:-8.4-apache}"
MIN_PHP="${MIN_PHP:-8.4.27}"
VERIFY=0
[ "${1:-}" = "--verify" ] && VERIFY=1

hub() { # repo tag -> "last_updated digest"
  curl -sS -A "drupal-library-check" "https://hub.docker.com/v2/namespaces/library/repositories/$1/tags/$2" |
    python3 -c 'import sys,json; d=json.load(sys.stdin); print(d["last_updated"][:19], (d.get("digest") or "")[:19])'
}

read -r php_updated php_digest <<<"$(hub php "$PHP_TAG")"
read -r drupal_updated drupal_digest <<<"$(hub drupal "$DRUPAL_TAG")"
echo "php:$PHP_TAG             rebuilt $php_updated  $php_digest"
echo "drupal:$DRUPAL_TAG  rebuilt $drupal_updated  $drupal_digest"

if [[ "$drupal_updated" < "$php_updated" ]]; then
  echo "The drupal tag was last rebuilt BEFORE the php tag: it has not picked up the latest PHP."
  newer=0
else
  echo "The drupal tag was rebuilt after the php tag."
  newer=1
fi

if [ "$VERIFY" -eq 1 ]; then
  command -v docker >/dev/null || { echo "docker not found; cannot --verify" >&2; exit 1; }
  img="public.ecr.aws/docker/library/drupal:$DRUPAL_TAG"
  docker pull -q "$img" >/dev/null
  have="$(docker run --rm --entrypoint php "$img" -r 'echo PHP_VERSION;')"
  echo "PHP inside $img: $have (minimum wanted: $MIN_PHP)"
  if [ "$(printf '%s\n%s\n' "$MIN_PHP" "$have" | sort -V | head -1)" = "$MIN_PHP" ]; then
    echo "OK: the base is at or above $MIN_PHP. Rebuild (webhook or the pipeline) to pick it up."
    exit 0
  fi
  echo "NOT YET: the base is below $MIN_PHP."
  exit 1
fi

[ "$newer" -eq 1 ] && echo "Run with --verify to read the PHP version it actually carries."
exit 1
