#!/usr/bin/env bash
#
# Rebuild the devops_docs static site from docs/.
#
# The built site (static/) is committed to this repo and shipped as-is in the
# container image -- there is no docs build step in the Docker/CI build. So after
# editing anything under docs/ (or mkdocs/mkdocs.yml), run this and commit the
# resulting static/ changes alongside your markdown.
#
# Prefers a local `mkdocs`; otherwise builds in a throwaway Docker container so
# no local Python install is required.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
OUT="package/data/opt/drupal/web/modules/custom/devops_docs/static"

if command -v mkdocs >/dev/null 2>&1; then
  echo "Building docs with local mkdocs..."
  ( cd mkdocs && mkdocs build --site-dir "$(pwd)/../$OUT" )
else
  echo "No local mkdocs found; building in a Docker container..."
  docker run --rm -v "$PWD":/repo -w /repo python:3.12-slim sh -c '
    apt-get -qq update >/dev/null && apt-get -qq install -y git >/dev/null &&
    pip install --quiet -r mkdocs/requirements.txt &&
    cd mkdocs && mkdocs build --site-dir "/repo/'"$OUT"'"
  '
fi

echo "✓ Rebuilt $OUT"
echo "  Review and commit the static/ changes:  git add $OUT"
