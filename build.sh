#!/usr/bin/env bash
# Build a clean, installable ZIP of the wc-product-sync plugin.
#
# The ZIP contains a single top-level folder `wc-product-sync/` (so WP admin
# "Upload Plugin" installs it correctly) with ONLY the files a user needs:
# the plugin, its docs, and the license. Internal dev/test artifacts listed in
# .distignore are excluded.
#
# Usage: ./build.sh   ->  dist/wc-product-sync-<version>.zip
set -euo pipefail

SLUG="wc-product-sync"
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

# Version from the plugin header (single source of truth). Accepts a prerelease suffix
# (0.9.24-beta1): PHP's version_compare ranks that BELOW 0.9.24, which is what the beta
# channel relies on — a beta never looks newer than the final it precedes.
VERSION="$(grep -m1 -oE 'Version:[[:space:]]*[0-9][0-9A-Za-z.-]*' "$SLUG.php" | sed -E 's/^Version:[[:space:]]*//')"
[ -n "$VERSION" ] || { echo "ERROR: could not read Version from $SLUG.php" >&2; exit 1; }

# Files/dirs that ship in the package.
INCLUDE=( "$SLUG.php" README.md LICENSE docs )

STAGE="$(mktemp -d)"
DEST="$STAGE/$SLUG"
mkdir -p "$DEST"
for item in "${INCLUDE[@]}"; do
	[ -e "$item" ] || { echo "ERROR: missing '$item'" >&2; exit 1; }
	cp -R "$item" "$DEST/"
done

mkdir -p dist
ZIP="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" )
rm -rf "$STAGE"

echo "Built $ZIP"
unzip -l "$ZIP"

# --- Self-hosted update metadata -----------------------------------------------------------------
# Emit dist/update.json for the in-plugin updater (WC_PRODUCT_SYNC_UPDATE_URL). Host this file AND
# the zip at $WPS_UPDATE_BASE_URL; the plugin's download_url must serve THIS versioned zip.
# Override the host with:  WPS_UPDATE_BASE_URL=https://your-host/wc-product-sync ./build.sh
BASE_URL="${WPS_UPDATE_BASE_URL:-https://EXAMPLE.invalid/wc-product-sync}"
REQUIRES="$(grep -m1 -oE 'Requires at least:[[:space:]]*[0-9.]+' "$SLUG.php" | grep -oE '[0-9.]+' || true)"
REQUIRES_PHP="$(grep -m1 -oE 'Requires PHP:[[:space:]]*[0-9.]+' "$SLUG.php" | grep -oE '[0-9.]+' || true)"
cat > dist/update.json <<JSON
{
  "name": "WC Product Sync (SKU)",
  "slug": "wc-product-sync",
  "version": "$VERSION",
  "requires": "${REQUIRES:-6.0}",
  "requires_php": "${REQUIRES_PHP:-7.4}",
  "tested": "${WPS_TESTED:-6.7}",
  "author": "Michał Pańczyk",
  "homepage": "$BASE_URL",
  "last_updated": "$(date -u +%F)",
  "download_url": "$BASE_URL/$SLUG-$VERSION.zip",
  "sections": {
    "changelog": "Pełna lista zmian w README.md (sekcja \"Zmiany (Changelog)\")."
  }
}
JSON
echo "Wrote dist/update.json  →  download_url: $BASE_URL/$SLUG-$VERSION.zip"
