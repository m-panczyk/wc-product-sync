#!/usr/bin/env bash
# Print the CHANGELOG.md section for one version, as Markdown.
#
# CHANGELOG.md is the single source of truth for release notes. build.sh feeds this into
# update.json (so WordPress shows real notes in "View version details" at update time) and
# publish.sh feeds it into the Forgejo release body. Write the notes once, in the PR; they
# reach both surfaces from here.
#
# Section headers look like:  ### 0.9.27 (current) — some title
# The "(current)" marker and the "— title" are optional. Matching is on the version token.
#
#   scripts/changelog-section.sh 0.9.27 [path/to/CHANGELOG.md]
set -euo pipefail

VERSION="${1:?usage: changelog-section.sh <version> [changelog-path]}"
FILE="${2:-$(cd "$(dirname "$0")/.." && pwd)/CHANGELOG.md}"
[ -f "$FILE" ] || { echo "changelog not found: $FILE" >&2; exit 1; }

awk -v ver="$VERSION" '
	# A version heading: "### <ver>" possibly followed by " (current)" or " — title".
	/^### / {
		# Extract the version token: 3rd field of the heading.
		v = $2
		if ( in_section ) { exit }          # next version heading ends our section
		if ( v == ver ) { in_section = 1; print; next }
	}
	in_section { print }
' "$FILE"
