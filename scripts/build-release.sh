#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

if [[ ! -f "composer.json" ]]; then
  echo "Run this script from the project root."
  exit 1
fi

if ! command -v php >/dev/null 2>&1; then
  echo "php is required."
  exit 1
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "composer is required."
  exit 1
fi

if ! command -v npm >/dev/null 2>&1; then
  echo "npm is required."
  exit 1
fi

APP_NAME="$(php -r '$json=json_decode(file_get_contents("composer.json"), true); $name=$json["name"] ?? "nrth"; echo preg_replace("/[^a-zA-Z0-9._-]+/", "-", basename($name));')"
VERSION="${1:-$(git describe --tags --always --dirty 2>/dev/null || date +%Y%m%d%H%M%S)}"
RELEASE_DIR="$ROOT_DIR/releases"
ARCHIVE_NAME="${APP_NAME}-${VERSION}.tar.gz"
ARCHIVE_PATH="$RELEASE_DIR/$ARCHIVE_NAME"
CHECKSUM_PATH="${ARCHIVE_PATH}.sha256"

mkdir -p "$RELEASE_DIR"

echo "==> Running tests"
php artisan test

echo "==> Building frontend assets"
npm run build

echo "==> Installing production PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

echo "==> Optimizing autoloader"
composer dump-autoload --optimize --no-dev --classmap-authoritative

echo "==> Creating release archive: $ARCHIVE_NAME"
tar \
  --exclude='./node_modules' \
  --exclude='./.git' \
  --exclude='./tests' \
  --exclude='./.env' \
  --exclude='./storage/app/*' \
  --exclude='./storage/logs/*' \
  --exclude='./releases' \
  -czf "$ARCHIVE_PATH" .

echo "==> Generating SHA256 checksum"
shasum -a 256 "$ARCHIVE_PATH" | awk '{print $1}' > "$CHECKSUM_PATH"

CHECKSUM="$(cat "$CHECKSUM_PATH")"

echo ""
echo "Release created:"
echo "  Archive : $ARCHIVE_PATH"
echo "  SHA256  : $CHECKSUM"
echo "  Checksum file: $CHECKSUM_PATH"
