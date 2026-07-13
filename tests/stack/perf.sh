#!/usr/bin/env bash
# Paired A/B performance test against the ephemeral stack.
#
# Absolute timings from a container on shared hardware are worthless — the same code can
# swing 2x between runs. So this never reports "a sync takes N seconds". It syncs the SAME
# seeded catalog twice, back to back, on the same hardware in the same minute: once with the
# plugin from a BASELINE ref, once with the working tree. Environmental noise hits both halves
# equally, so the RATIO is stable even when the absolute numbers are not.
#
# That catches the regressions that actually matter — an extra REST round-trip per product,
# images re-downloading when the map says they shouldn't, a rollup firing on no-op updates.
# It cannot tell you how long a real sync takes on real hardware; nothing hermetic can.
#
# A discarded warm-up run comes first, because the first sync of a cold stack pays for PHP
# opcache, MySQL buffers and image pulls — without it, whichever side ran second would look
# artificially fast.
#
#   WPS_BASE_REF=v0.9.22 tests/stack/perf.sh
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$(cd ../.. && pwd)"

MAX_RATIO="${WPS_PERF_MAX:-1.5}"    # hard fail above this
WARN_RATIO="${WPS_PERF_WARN:-1.2}"  # warn above this
SLUG="wc-product-sync"

# Baseline = the last release tag, so the question is "is this slower than what we shipped?".
# `latest` is a moving metadata tag, not a version — v[0-9]* excludes it.
BASE_REF="${WPS_BASE_REF:-$(git -C "$ROOT" describe --tags --abbrev=0 --match 'v[0-9]*' 2>/dev/null || echo 'HEAD~1')}"

twp() { docker compose exec -T tgt-cli wp --allow-root "$@"; }
invoke() { twp eval "\$m=new ReflectionMethod('WC_Product_Sync','$1');\$m->setAccessible(true);\$m->invoke(WC_Product_Sync::instance());"; }

version_of() { grep -m1 -oE 'Version:[[:space:]]*[0-9][0-9A-Za-z.-]*' "$1/$SLUG.php" | sed -E 's/^Version:[[:space:]]*//'; }

# Wipe every product AND attachment on the target, so each timed run pays the full cost of
# creating the catalog and sideloading images from scratch. Without the attachment wipe the
# second run would reuse images and win for the wrong reason.
reset_target() {
	twp eval '
	foreach ( get_posts( array( "post_type" => array( "product", "product_variation", "attachment" ), "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	' >/dev/null
}

# Time one full sync to completion. Echoes seconds.
time_sync() {
	twp transient delete wps_sync_progress >/dev/null 2>&1 || true
	twp transient delete wps_sync_running  >/dev/null 2>&1 || true
	local t0 t1
	t0=$(date +%s%N)
	invoke run_sync_cron >/dev/null
	local n=1
	while twp transient get wps_sync_progress --format=json 2>/dev/null | grep -q current_page; do
		n=$((n + 1))
		[ "$n" -gt 80 ] && { echo "runaway batch loop" >&2; exit 1; }
		invoke run_resume_batch >/dev/null
	done
	t1=$(date +%s%N)
	echo "scale=2; ($t1 - $t0) / 1000000000" | bc
}

run_with() { # run_with <label> <zip>  -> seconds
	twp plugin install "$1" --force --activate >/dev/null 2>&1
	reset_target
	time_sync
}

echo "==> Building both plugin builds"
( cd "$ROOT" && ./build.sh >/dev/null )
CAND_VER="$(version_of "$ROOT")"
CAND_ZIP="$ROOT/dist/$SLUG-$CAND_VER.zip"

WORKTREE="$(mktemp -d)/base"
git -C "$ROOT" worktree add --detach "$WORKTREE" "$BASE_REF" >/dev/null 2>&1 || {
	echo "!! cannot check out baseline ref '$BASE_REF' — is the history shallow? (need fetch-depth: 0)" >&2
	exit 1
}
( cd "$WORKTREE" && ./build.sh >/dev/null )
BASE_VER="$(version_of "$WORKTREE")"
BASE_ZIP="$WORKTREE/dist/$SLUG-$BASE_VER.zip"

echo "    baseline:  $BASE_REF ($BASE_VER)"
echo "    candidate: working tree ($CAND_VER)"

# Deterministic sync settings: a time-based batch stop would make the run length depend on
# the clock rather than on the code.
docker compose exec -T tgt-cli wp --allow-root eval '
	$o = (array) get_option( "wc_product_sync_options", array() );
	$o["per_page"] = 100; $o["sync_batch_limit"] = 0; $o["max_batch_seconds"] = 0; $o["force_full_sync"] = 0;
	update_option( "wc_product_sync_options", $o );
' >/dev/null

min() { if [ "$(echo "$1 < $2" | bc)" = "1" ]; then echo "$1"; else echo "$2"; fi; }

# Interleaved, best-of-two per side. A single pass each is not enough: with IDENTICAL code
# on both sides this harness still measured a 1.14x spread, i.e. the noise floor is ~15%.
# Interleaving cancels slow drift over the run, and taking the min discards transient stalls
# (a neighbouring container, a ZFS flush) rather than letting them masquerade as a result.
echo "==> Warm-up (discarded)"
echo "    $(run_with "$BASE_ZIP")s"

echo "==> Round 1"
B1="$(run_with "$BASE_ZIP")"; C1="$(run_with "$CAND_ZIP")"
echo "    baseline ${B1}s   candidate ${C1}s"

echo "==> Round 2"
B2="$(run_with "$BASE_ZIP")"; C2="$(run_with "$CAND_ZIP")"
echo "    baseline ${B2}s   candidate ${C2}s"

T_BASE="$(min "$B1" "$B2")"
T_CAND="$(min "$C1" "$C2")"

git -C "$ROOT" worktree remove --force "$WORKTREE" >/dev/null 2>&1 || true

RATIO="$(echo "scale=3; $T_CAND / $T_BASE" | bc)"
echo
echo "baseline ${T_BASE}s   candidate ${T_CAND}s   ratio ${RATIO}x  (fail > ${MAX_RATIO}x)"

if [ "$(echo "$RATIO > $MAX_RATIO" | bc)" = "1" ]; then
	echo "::error::performance regression: candidate is ${RATIO}x the baseline (limit ${MAX_RATIO}x)"
	exit 1
fi
if [ "$(echo "$RATIO > $WARN_RATIO" | bc)" = "1" ]; then
	echo "::warning::candidate is ${RATIO}x the baseline (warn above ${WARN_RATIO}x)"
fi
echo "perf PASS"
