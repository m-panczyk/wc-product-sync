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

# Version from the plugin header (single source of truth).
VERSION="$(grep -m1 -oE 'Version:[[:space:]]*[0-9.]+' "$SLUG.php" | grep -oE '[0-9.]+')"
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
