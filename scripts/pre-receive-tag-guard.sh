#!/bin/sh
# Forgejo/Gitea pre-receive hook: reject bad RELEASE tags at push time.
#
# Enforces the same rules as the `guard` job in .forgejo/workflows/release.yaml, but at
# the source — a bad tag never lands in the repo, so there is nothing to clean up.
#
#   • FORMAT   — a release tag is vX.Y.Z (stable) or vX.Y.Z-rcN (prerelease). Nothing else.
#                A version-looking tag WITHOUT the leading `v` (e.g. 0.9.27-rc7) is rejected
#                with a hint, because it silently fails to trigger the release workflow.
#   • NO MOVE  — an existing release tag may not be force-moved or deleted (immutable once cut).
#   • ORDERING — a new release tag must be strictly greater than every existing release tag.
#                Stable > its own RCs (0.9.27 > 0.9.27-rc9); rc10 > rc2.
#
# Non-release tags (channel tags `latest`/`latest-beta`, or anything not version-shaped and
# without a `v` prefix) pass untouched. Branches are ignored.
#
# INSTALL (needs `[security] DISABLE_GIT_HOOKS = false` in Forgejo's app.ini, then restart):
#   Repo → Settings → Git Hooks → pre-receive → paste this file, OR drop it at
#   <repo>.git/hooks/pre-receive.d/tag-guard and `chmod +x`.

set -eu

ZERO=0000000000000000000000000000000000000000
status=0

# numeric-comparable, fixed-width key for a version string (v stripped).
# stable releases get rc=9999999 so they sort ABOVE any -rcN of the same base.
ver_key() {
	v="${1#v}"
	base="${v%%-rc*}"
	case "$v" in
		*-rc*) rc="${v##*-rc}" ;;
		*)     rc=9999999 ;;
	esac
	# split base into major.minor.patch
	ma="${base%%.*}"; rest="${base#*.}"; mi="${rest%%.*}"; pa="${rest#*.}"
	printf '%05d%05d%05d%07d' "$ma" "$mi" "$pa" "$rc"
}

is_release_tag() { printf '%s' "$1" | grep -qE '^v[0-9]+\.[0-9]+\.[0-9]+(-rc[0-9]+)?$'; }
looks_like_version() { printf '%s' "$1" | grep -qE '^v?[0-9]+\.[0-9]+\.[0-9]+(-rc[0-9]+)?$'; }

while read -r oldrev newrev ref; do
	case "$ref" in
		refs/tags/*) tag="${ref#refs/tags/}" ;;
		*) continue ;;   # branches and everything else: not our concern
	esac

	# Deletion of an existing tag.
	if [ "$newrev" = "$ZERO" ]; then
		if is_release_tag "$tag"; then
			echo "GUARD: refusing to delete release tag '$tag' — release tags are immutable." >&2
			status=1
		fi
		continue
	fi

	# A version-looking tag missing the leading `v` — almost always a mistake; it would not
	# trigger the release workflow (which listens on v*). Reject with a hint.
	if ! printf '%s' "$tag" | grep -q '^v' && looks_like_version "$tag"; then
		echo "GUARD: tag '$tag' looks like a version but has no 'v' prefix — use 'v$tag'." >&2
		status=1
		continue
	fi

	# Not a v-prefixed tag at all (e.g. channel tags, feature tags): let it through.
	printf '%s' "$tag" | grep -q '^v' || continue

	# It starts with v — it must be a well-formed release tag.
	if ! is_release_tag "$tag"; then
		echo "GUARD: '$tag' is not a valid release tag. Use vX.Y.Z (stable) or vX.Y.Z-rcN (prerelease)." >&2
		status=1
		continue
	fi

	# Force-move of an existing release tag.
	if [ "$oldrev" != "$ZERO" ]; then
		echo "GUARD: refusing to move existing release tag '$tag' — cut a new version instead." >&2
		status=1
		continue
	fi

	# ORDERING — strictly greater than the current highest release tag.
	newkey="$(ver_key "$tag")"
	maxkey=000000000000000000000000
	maxtag=""
	for t in $(git tag -l 'v*'); do
		is_release_tag "$t" || continue
		[ "$t" = "$tag" ] && continue
		k="$(ver_key "$t")"
		if [ "$(printf '%s\n%s\n' "$maxkey" "$k" | sort | tail -n1)" = "$k" ] && [ "$k" != "$maxkey" ]; then
			maxkey="$k"; maxtag="$t"
		fi
	done
	if [ -n "$maxtag" ]; then
		# newkey must be > maxkey (string compare of equal-width numeric keys == numeric compare)
		if [ "$(printf '%s\n%s\n' "$newkey" "$maxkey" | sort | tail -n1)" != "$newkey" ] || [ "$newkey" = "$maxkey" ]; then
			echo "GUARD: out-of-order tag '$tag' — not greater than latest release '$maxtag'. Tags must move forward." >&2
			status=1
			continue
		fi
	fi

	echo "GUARD: release tag '$tag' accepted." >&2
done

exit "$status"
