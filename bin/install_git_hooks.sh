#!/usr/bin/env bash
#
# One-time (per clone) setup step for the build-number hook: points this
# repo's core.hooksPath at the tracked githooks/ directory instead of the
# default, never-versioned .git/hooks/. Without this, githooks/pre-commit
# just sits on disk and git never runs it -- see README.md's "Build
# number" section for the full design and the reasoning behind that
# tradeoff (every clone that wants the counter to keep incrementing needs
# to run this once).
#
# Idempotent -- safe to re-run.
#
# Usage:
#   bin/install_git_hooks.sh

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

git -C "$PROJECT_ROOT" config core.hooksPath githooks

echo "core.hooksPath set to githooks/ -- commits in this clone will now bump BUILD_NUMBER."
