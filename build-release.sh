#!/usr/bin/env bash

set -euo pipefail

PLUGIN_SLUG="shortcode-to-blocks-pro"
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST_DIR="$ROOT_DIR/dist"
STAGE_ROOT="$(mktemp -d)"
STAGE_DIR="$STAGE_ROOT/$PLUGIN_SLUG"
ZIP_PATH="$DIST_DIR/$PLUGIN_SLUG.zip"

cleanup() {
  rm -rf "$STAGE_ROOT"
}
trap cleanup EXIT

mkdir -p "$DIST_DIR"
mkdir -p "$STAGE_DIR"

# Copy plugin files into a clean staging directory while excluding dev/system files.
rsync -a \
  --exclude='.git/' \
  --exclude='.github/' \
  --exclude='.vscode/' \
  --exclude='node_modules/' \
  --exclude='tests/' \
  --exclude='dist/' \
  --exclude='.DS_Store' \
  --exclude='Thumbs.db' \
  --exclude='*.log' \
  --exclude='*.map' \
  --exclude='.gitignore' \
  --exclude='.env' \
  --exclude='.env.*' \
  --exclude='*.md' \
  --exclude='build-release.sh' \
  "$ROOT_DIR/" "$STAGE_DIR/"

# Install production Composer dependencies (update checker library)
if [ -f "$STAGE_DIR/composer.json" ]; then
  echo "Installing production dependencies..."
  (cd "$STAGE_DIR" && composer install --no-dev --optimize-autoloader --quiet)
  # Remove composer files after installing dependencies
  rm -f "$STAGE_DIR/composer.json" "$STAGE_DIR/composer.lock"
fi

rm -f "$ZIP_PATH"

(
  cd "$STAGE_ROOT"
  zip -rq "$ZIP_PATH" "$PLUGIN_SLUG" -x "*/.DS_Store" "__MACOSX/*"
)

echo "Release zip created: $ZIP_PATH"
