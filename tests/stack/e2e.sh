#!/usr/bin/env bash
# End-to-end sync test against the ephemeral stack.
#
# Phase 1 — full sync across MULTIPLE batches, then assert source<->target parity for every
#           product: type, price, stock, image presence, variable rollup, every variation.
# Phase 2 — force-full deletion: drop products from the source, re-sync with force_full_sync,
#           and assert ONLY the dropped ones disappear from the target.
#
# Batching is forced (small per_page / sync_batch_limit) because the bug class this guards
# against only appears across RESUME batches: in 0.9.20 force-full either never ran (catalog
# split over batches) or deleted the products it had just synced (catalog in one batch). A
# single-batch run would exercise neither, so a batch count of 1 is treated as a failure.
#
# Assumes tests/stack/up.sh and tests/stack/seed.sh have run.
set -euo pipefail
cd "$(dirname "$0")"

PER_PAGE="${WPS_PER_PAGE:-10}"
BATCH_LIMIT="${WPS_BATCH_LIMIT:-15}"
DROP="${WPS_DROP:-3}"          # products removed from the source in phase 2

swp() { docker compose exec -T src-cli wp --allow-root "$@"; }
twp() { docker compose exec -T tgt-cli wp --allow-root "$@"; }

# `wp option patch update` refuses to create a key that is not already persisted, and the
# plugin merges its defaults at runtime rather than storing them — so write the array.
opt() { # opt <key> <value>
	docker compose exec -T -e "WPS_K=$1" -e "WPS_V=$2" tgt-cli wp --allow-root eval '
		$o = (array) get_option( "wc_product_sync_options", array() );
		$v = getenv( "WPS_V" );
		$o[ getenv( "WPS_K" ) ] = is_numeric( $v ) ? (int) $v : $v;
		update_option( "wc_product_sync_options", $o );
	' >/dev/null
}
invoke() { twp eval "\$m=new ReflectionMethod('WC_Product_Sync','$1');\$m->setAccessible(true);\$m->invoke(WC_Product_Sync::instance());"; }

DUMP='
$out = array();
foreach ( wc_get_products( array( "limit" => -1, "status" => "publish" ) ) as $p ) {
	$sku = $p->get_sku();
	if ( ! $sku ) { continue; }
	$row = array(
		"type"   => $p->get_type(),
		"price"  => (string) $p->get_regular_price(),
		"stock"  => $p->get_stock_quantity(),
		"images" => $p->get_image_id() ? 1 : 0,
		"vars"   => array(),
	);
	if ( $p->is_type( "variable" ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->get_sku() ) { continue; }
			$row["vars"][ $v->get_sku() ] = array(
				"price" => (string) $v->get_regular_price(),
				"stock" => $v->get_stock_quantity(),
			);
		}
		ksort( $row["vars"] );
		$row["min_price"] = (string) $p->get_variation_price( "min" );
		$row["max_price"] = (string) $p->get_variation_price( "max" );
	}
	$out[ $sku ] = $row;
}
ksort( $out );
echo wp_json_encode( $out );
'

# Drive a sync to completion the way cron does; echo the number of batches it took.
drive() {
	twp transient delete wps_sync_progress >/dev/null 2>&1 || true
	twp transient delete wps_sync_running  >/dev/null 2>&1 || true
	invoke run_sync_cron >/dev/null
	local n=1
	while twp transient get wps_sync_progress --format=json 2>/dev/null | grep -q current_page; do
		n=$((n + 1))
		if [ "$n" -gt 60 ]; then echo "!! runaway batch loop" >&2; exit 1; fi
		invoke run_resume_batch >/dev/null
	done
	echo "$n"
}

compare() { # compare <label>
	swp eval "$DUMP" > /tmp/wps-src.json
	twp eval "$DUMP" > /tmp/wps-tgt.json
	python3 - /tmp/wps-src.json /tmp/wps-tgt.json "$1" <<'PY'
import json, sys
src, tgt, label = json.load(open(sys.argv[1])), json.load(open(sys.argv[2])), sys.argv[3]
fails = []
for sku in sorted(set(src) - set(tgt)):
    fails.append(f"{sku}: missing on target")
for sku in sorted(set(tgt) - set(src)):
    fails.append(f"{sku}: on target but gone from source (should have been deleted)")
for sku in sorted(set(src) & set(tgt)):
    s, t = src[sku], tgt[sku]
    for f in ("type", "price", "stock", "images"):
        if s[f] != t[f]:
            fails.append(f"{sku}: {f} source={s[f]!r} target={t[f]!r}")
    for k in ("min_price", "max_price"):
        if k in s and s.get(k) != t.get(k):
            fails.append(f"{sku}: {k} source={s.get(k)!r} target={t.get(k)!r}")
    sv, tv = s.get("vars", {}), t.get("vars", {})
    for v in sorted(set(sv) - set(tv)):
        fails.append(f"{sku}/{v}: variation missing on target")
    for v in sorted(set(sv) & set(tv)):
        if sv[v] != tv[v]:
            fails.append(f"{sku}/{v}: source={sv[v]} target={tv[v]}")
nvars = sum(len(v.get("vars", {})) for v in src.values())
if fails:
    print(f"  FAIL [{label}]: {len(fails)} mismatch(es) over {len(src)} products / {nvars} variations")
    for f in fails[:30]:
        print("    " + f)
    if len(fails) > 30:
        print(f"    ... and {len(fails)-30} more")
    sys.exit(1)
print(f"  PASS [{label}]: {len(src)} products / {nvars} variations match")
PY
}

echo "==> Forcing the batching path (per_page=$PER_PAGE, sync_batch_limit=$BATCH_LIMIT)"
opt per_page "$PER_PAGE"
opt sync_batch_limit "$BATCH_LIMIT"
opt max_batch_seconds 0     # time-based stops would make the batch count nondeterministic
opt force_full_sync 0

echo "==> Phase 1: full sync"
BATCHES="$(drive)"
echo "    completed in $BATCHES batch(es)"
if [ "$BATCHES" -lt 2 ]; then
	echo "!! FAIL: the sync finished in one batch — the resume path was never exercised." >&2
	echo "         Seed more products or lower WPS_BATCH_LIMIT; this test is worthless single-batch." >&2
	exit 1
fi
compare "full sync, $BATCHES batches"

echo "==> Phase 2: force-full deletion across batches"
# `wp eval` takes no positional args — hand WPS_DROP over the environment.
DROPPED="$(docker compose exec -T -e "WPS_DROP=$DROP" src-cli wp --allow-root eval '
$n    = (int) getenv( "WPS_DROP" );
$skus = array();
foreach ( wc_get_products( array( "limit" => $n, "type" => "simple", "orderby" => "sku", "order" => "DESC" ) ) as $p ) {
	$skus[] = $p->get_sku();
	$p->delete( true );
}
echo implode( ",", $skus );
')"
[ -n "$DROPPED" ] || { echo "!! FAIL: could not drop any product from the source" >&2; exit 1; }
echo "    removed from source: $DROPPED"

opt force_full_sync 1
BATCHES2="$(drive)"
echo "    re-synced in $BATCHES2 batch(es)"
if [ "$BATCHES2" -lt 2 ]; then
	echo "!! FAIL: force-full ran in a single batch — this is the exact shape that hid the 0.9.20 bug." >&2
	exit 1
fi

# The 0.9.20 failure modes, precisely: force-full deleted everything it had just synced, or
# it never ran at all. compare() catches both — a surviving dropped SKU means it never ran;
# a missing kept SKU means it ate the catalog.
compare "after force-full, $BATCHES2 batches"
opt force_full_sync 0

echo
echo "e2e PASS (multi-batch full sync + force-full deletion)"
