#!/usr/bin/env bash
# Publish a release to Forgejo: build the ZIP, attach it to the versioned tag, and
# swap update.json on the moving `latest` release (which is what the in-plugin updater
# actually polls). Used by .forgejo/workflows/release.yaml and runnable by hand.
#
# The `latest` release carries ONLY update.json — never a ZIP. Versioned ZIPs stay
# immutable under their own tags, so an install of 0.9.22 keeps pulling the exact
# package built for 0.9.22 long after 0.9.23 ships.
#
# Usage:  FORGEJO_TOKEN=... ./publish.sh v0.9.23
#
# Env:
#   FORGEJO_TOKEN  (required)  token with write access to the repo
#   FORGEJO_URL    instance base URL      (default https://git.panczyk.cc)
#   FORGEJO_REPO   owner/name             (default mpanczyk/wc-product-sync)
#   COMMIT         commitish for new tags (default current HEAD)
set -euo pipefail

TAG="${1:-}"
[ -n "$TAG" ] || { echo "usage: $0 v<version>" >&2; exit 2; }

: "${FORGEJO_TOKEN:?FORGEJO_TOKEN is required}"
FORGEJO_URL="${FORGEJO_URL:-https://git.panczyk.cc}"
FORGEJO_REPO="${FORGEJO_REPO:-mpanczyk/wc-product-sync}"
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"
COMMIT="${COMMIT:-$(git rev-parse HEAD)}"

API="$FORGEJO_URL/api/v1/repos/$FORGEJO_REPO"
SLUG="wc-product-sync"

# The tag is the source of truth for what we claim to ship; the plugin header is the
# source of truth for what the updater will compare against. They must agree, or stores
# either never see the update or re-download forever.
VERSION="$(grep -m1 -oE 'Version:[[:space:]]*[0-9.]+' "$SLUG.php" | grep -oE '[0-9.]+')"
[ "$TAG" = "v$VERSION" ] || {
	echo "ERROR: tag '$TAG' does not match plugin header version '$VERSION' (expected v$VERSION)" >&2
	exit 1
}

api() { # api METHOD PATH [curl args...]
	local method="$1" path="$2"; shift 2
	curl -sS -X "$method" "$API$path" \
		-H "Authorization: token $FORGEJO_TOKEN" \
		-H 'Accept: application/json' "$@"
}

# Print a top-level field of a JSON object on stdin ("" when absent).
jget() { python3 -c 'import json,sys; print(json.load(sys.stdin).get(sys.argv[1],"") or "")' "$1"; }

# Release id for a tag, or "" when the release does not exist.
release_id() {
	local tag="$1" body
	body="$(api GET "/releases/tags/$tag" -w '\n%{http_code}')"
	[ "$(printf '%s' "$body" | tail -n1)" = "200" ] || { echo ""; return 0; }
	printf '%s' "$body" | sed '$d' | jget id
}

ensure_release() { # ensure_release TAG NAME BODY PRERELEASE -> id
	local tag="$1" name="$2" body="$3" pre="$4" id
	id="$(release_id "$tag")"
	if [ -n "$id" ]; then echo "$id"; return 0; fi
	python3 - "$tag" "$name" "$body" "$pre" "$COMMIT" >/tmp/wps-rel.json <<'PY'
import json,sys
tag,name,body,pre,commit = sys.argv[1:6]
json.dump({"tag_name":tag,"target_commitish":commit,"name":name,"body":body,
           "draft":False,"prerelease":pre=="true"}, sys.stdout)
PY
	api POST "/releases" -H 'Content-Type: application/json' --data-binary @/tmp/wps-rel.json | jget id
}

# Forgejo will not overwrite an attachment with a duplicate name, so drop it first.
upload_asset() { # upload_asset RELEASE_ID FILE NAME
	local id="$1" file="$2" name="$3" old
	old="$(api GET "/releases/$id" | python3 -c '
import json,sys
r=json.load(sys.stdin)
print(next((str(a["id"]) for a in r.get("assets") or [] if a["name"]==sys.argv[1]), ""))' "$name")"
	if [ -n "$old" ]; then
		api DELETE "/releases/$id/assets/$old" >/dev/null
		echo "  replaced existing $name"
	fi
	api POST "/releases/$id/assets?name=$name" -F "attachment=@$file" >/dev/null
	echo "  uploaded $name"
}

DL_BASE="$FORGEJO_URL/$FORGEJO_REPO/releases/download/$TAG"

echo "==> Building $SLUG-$VERSION.zip (download base: $DL_BASE)"
WPS_UPDATE_BASE_URL="$DL_BASE" ./build.sh >/dev/null
ZIP="dist/$SLUG-$VERSION.zip"
[ -f "$ZIP" ] && [ -f dist/update.json ] || { echo "ERROR: build produced no artifacts" >&2; exit 1; }

echo "==> Versioned release $TAG"
VER_ID="$(ensure_release "$TAG" "$TAG" "WC Product Sync $VERSION" false)"
[ -n "$VER_ID" ] || { echo "ERROR: could not create/find release $TAG" >&2; exit 1; }
upload_asset "$VER_ID" "$ZIP" "$SLUG-$VERSION.zip"
upload_asset "$VER_ID" dist/update.json update.json

# This is the step that actually ships the update. Until update.json on `latest` is
# swapped, every store still sees the previous version.
echo "==> Moving release 'latest' (updater metadata)"
LATEST_ID="$(ensure_release latest "latest — updater metadata" \
	"Metadata pointer for the in-plugin updater (WC_PRODUCT_SYNC_UPDATE_URL). Do not download plugin ZIPs from here — use the versioned release." \
	true)"
[ -n "$LATEST_ID" ] || { echo "ERROR: could not create/find release 'latest'" >&2; exit 1; }
upload_asset "$LATEST_ID" dist/update.json update.json

echo
echo "Published $VERSION"
echo "  ZIP:     $DL_BASE/$SLUG-$VERSION.zip"
echo "  Updater: $FORGEJO_URL/$FORGEJO_REPO/releases/download/latest/update.json"
