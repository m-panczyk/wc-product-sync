#!/usr/bin/env bash
# End-to-end sync test against the ephemeral stack.
#
# Drives a full sync on the target (same entry point the cron uses — run_sync_cron, then
# run_resume_batch until the progress transient clears), then asserts source<->target parity
# for every product: type, price, stock, image presence, and every variation's price/stock.
#
# Assumes `tests/stack/up.sh` and `tests/stack/seed.sh` have run.
#
#   tests/stack/e2e.sh
set -euo pipefail
cd "$(dirname "$0")"

swp() { docker compose exec -T src-cli wp --allow-root "$@"; }
twp() { docker compose exec -T tgt-cli wp --allow-root "$@"; }

# The plugin's sync methods are private; the cron path reaches them the same way.
teval() { twp eval "$1"; }
invoke() { teval "\$m=new ReflectionMethod('WC_Product_Sync','$1');\$m->setAccessible(true);\$m->invoke(WC_Product_Sync::instance());"; }

# Dump a catalog as JSON keyed by SKU. Same shape on both stores, so a diff is meaningful.
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

echo "==> Driving a full sync on the target"
START=$(date +%s)
twp transient delete wps_sync_progress >/dev/null 2>&1 || true
twp transient delete wps_sync_running  >/dev/null 2>&1 || true
invoke run_sync_cron >/dev/null

BATCHES=1
while twp transient get wps_sync_progress --format=json 2>/dev/null | grep -q current_page; do
	BATCHES=$((BATCHES + 1))
	if [ "$BATCHES" -gt 40 ]; then echo "!! too many batches — aborting" >&2; exit 1; fi
	invoke run_resume_batch >/dev/null
done
DUR=$(( $(date +%s) - START ))
echo "    done in ${DUR}s across ${BATCHES} batch(es)"

echo "==> Comparing catalogs"
swp eval "$DUMP" > /tmp/wps-src.json
twp eval "$DUMP" > /tmp/wps-tgt.json

python3 - /tmp/wps-src.json /tmp/wps-tgt.json <<'PY'
import json, sys

src = json.load(open(sys.argv[1]))
tgt = json.load(open(sys.argv[2]))
fails = []

missing = sorted(set(src) - set(tgt))
extra   = sorted(set(tgt) - set(src))
for sku in missing:
    fails.append(f"{sku}: missing on target")
for sku in extra:
    fails.append(f"{sku}: present on target but not source")

for sku in sorted(set(src) & set(tgt)):
    s, t = src[sku], tgt[sku]
    for field in ("type", "price", "stock", "images"):
        if s[field] != t[field]:
            fails.append(f"{sku}: {field} source={s[field]!r} target={t[field]!r}")
    # Variable rollup: the parent's min/max must reflect its variations, which is the
    # bug class 0.9.13-0.9.15 was about.
    for k in ("min_price", "max_price"):
        if k in s and s.get(k) != t.get(k):
            fails.append(f"{sku}: {k} source={s.get(k)!r} target={t.get(k)!r}")
    sv, tv = s.get("vars", {}), t.get("vars", {})
    for vsku in sorted(set(sv) - set(tv)):
        fails.append(f"{sku}/{vsku}: variation missing on target")
    for vsku in sorted(set(sv) & set(tv)):
        if sv[vsku] != tv[vsku]:
            fails.append(f"{sku}/{vsku}: source={sv[vsku]} target={tv[vsku]}")

checked = len(src)
nvars = sum(len(v.get("vars", {})) for v in src.values())
if fails:
    print(f"FAIL: {len(fails)} mismatch(es) across {checked} products / {nvars} variations\n")
    for f in fails[:40]:
        print("  " + f)
    if len(fails) > 40:
        print(f"  ... and {len(fails) - 40} more")
    sys.exit(1)

print(f"PASS: {checked} products / {nvars} variations match source<->target")
PY
