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

extract() { # extract <version-token> <file>
	awk -v ver="$1" '
		# A version heading: "### <ver>" possibly followed by " (current)" or " — title".
		/^### / {
			# Version token is the 2nd field: "### 0.9.27 (current) — ..." → $1=###, $2=0.9.27.
			# Compared with == (string equality, not regex), so a dotted version is safe.
			v = $2
			if ( in_section ) { exit }          # next version heading ends our section
			if ( v == ver ) { in_section = 1; print; next }
		}
		in_section { print }
	' "$2"
}

# Every -rcN gets its own CHANGELOG.md section here (see "Release versioning" in project docs),
# so try an EXACT match on the full version first. Only fall back to the bare base version
# (-rcN/-betaN suffix stripped) when no per-prerelease section exists — e.g. a final release cut
# before it got its own entry, or a genuine first-ever beta that predates any section at all. The
# old unconditional strip-then-match here meant every rc silently got the stale, unrelated bare
# "0.9.27" section instead of its own notes (rc6, rc8, rc9 all published identical release notes).
OUT="$(extract "$VERSION" "$FILE")"
if [ -z "$OUT" ]; then
	OUT="$(extract "${VERSION%%-*}" "$FILE")"
fi
printf '%s\n' "$OUT"
