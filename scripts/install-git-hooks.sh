#!/usr/bin/env bash
#
# Install this repo's tracked git hooks by pointing core.hooksPath at
# scripts/git-hooks. Run once per clone. The hooks are advisory only.
#
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
git config core.hooksPath scripts/git-hooks
echo "✓ core.hooksPath set to scripts/git-hooks"
echo "  Active hooks:"
ls scripts/git-hooks | sed 's/^/    /'
