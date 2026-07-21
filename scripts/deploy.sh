#!/usr/bin/env bash
#
# Backwards-compatible alias for ./scripts/update
#
# Prefer: ./scripts/update
# Still accepted: ./scripts/deploy.sh [production|dev]

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec "$ROOT_DIR/scripts/update" "$@"
