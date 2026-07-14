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

# Dump every field the plugin claims to sync. A field that is not compared is a field that
# can vanish silently — which is exactly how the image-sideload breakage hid behind a sync
# that cheerfully reported "błędy=0".
#
# Only publish + has-SKU products are dumped: those are the ones the plugin is supposed to
# copy. The skip cases (draft/private/no-SKU) are asserted separately, by absence.
DUMP='
$out = array();
foreach ( wc_get_products( array( "limit" => -1, "status" => "publish" ) ) as $p ) {
	$sku = $p->get_sku();
	if ( ! $sku ) { continue; }

	$cats = wp_get_post_terms( $p->get_id(), "product_cat", array( "fields" => "slugs" ) );
	sort( $cats );

	$row = array(
		"type"        => $p->get_type(),
		"price"       => (string) $p->get_regular_price(),
		"sale"        => (string) $p->get_sale_price(),
		"stock"       => $p->get_stock_quantity(),
		"stock_status"=> $p->get_stock_status(),
		"manage"      => $p->get_manage_stock() ? 1 : 0,
		"desc"        => (string) $p->get_description(),
		"short"       => (string) $p->get_short_description(),
		"weight"      => (string) $p->get_weight(),
		"dims"        => implode( "x", array( (string) $p->get_length(), (string) $p->get_width(), (string) $p->get_height() ) ),
		"cats"        => $cats,
		"n_images"    => ( $p->get_image_id() ? 1 : 0 ) + count( $p->get_gallery_image_ids() ),
		"vars"        => array(),
	);

	// Grouped: the children themselves, by SKU. Checking only that the type is "grouped"
	// would pass even if it pointed at nothing at all.
	if ( $p->is_type( "grouped" ) ) {
		$kids = array();
		foreach ( $p->get_children() as $cid ) {
			$c = wc_get_product( $cid );
			if ( $c && $c->get_sku() ) { $kids[] = $c->get_sku(); }
		}
		sort( $kids );
		$row["children"] = $kids;
	}

	if ( $p->is_type( "variable" ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || ! $v->get_sku() ) { continue; }
			$attrs = $v->get_attributes();
			ksort( $attrs );
			$row["vars"][ $v->get_sku() ] = array(
				"price"  => (string) $v->get_regular_price(),
				"stock"  => $v->get_stock_quantity(),
				"attrs"  => $attrs,
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

# Status filter and SKU-less matching.
#
# Draft/private must NOT be copied: sync_statuses defaults to publish only.
#
# A product with no SKU MUST still be copied — the plugin falls back to matching by name
# ("Dopasowanie po SKU (lub nazwie gdy brak SKU)", plugin header). This test originally
# asserted the opposite, on the strength of a README line claiming SKU-less products are
# skipped; the run proved the code right and the README wrong.
assert_status_filter() {
	echo "==> Asserting the status filter and SKU-less matching"
	twp eval '
	$fail = 0;
	foreach ( array( "E2E-DRAFT-001", "E2E-PRIVATE-001" ) as $sku ) {
		if ( wc_get_product_id_by_sku( $sku ) ) {
			echo "  FAIL: $sku was synced, but its source status is outside sync_statuses\n";
			$fail = 1;
		}
	}
	$noSku = get_posts( array( "post_type" => "product", "title" => "E2E NoSku 001", "post_status" => "any", "numberposts" => 1 ) );
	if ( ! $noSku ) {
		echo "  FAIL: the no-SKU product was NOT synced (name-matching fallback is broken)\n";
		$fail = 1;
	}
	if ( ! $fail ) { echo "  PASS: draft/private skipped; no-SKU product matched by name\n"; }
	exit( $fail );
	'
}

# The README's "not supported" list, asserted. A list of things that don't sync is worth nothing
# unless something checks it stays true — and the supported list had two false claims in it until
# they were checked against a real store.
assert_unsupported_not_synced() {
	echo "==> Asserting the documented NON-features really do not sync"
	twp eval '
	$fail = 0;
	$p = wc_get_product( wc_get_product_id_by_sku( "E2E-UNSUP-001" ) );
	if ( ! $p ) { echo "  FAIL: E2E-UNSUP-001 did not sync at all — fixture broken\n"; exit( 1 ); }
	$tags = wp_get_post_terms( $p->get_id(), "product_tag", array( "fields" => "slugs" ) );
	if ( $tags )                                                 { echo "  FAIL: product tags were synced (" . implode( ",", $tags ) . ")\n"; $fail = 1; }
	if ( $p->get_upsell_ids() )                                  { echo "  FAIL: upsells were synced\n"; $fail = 1; }
	if ( $p->get_cross_sell_ids() )                              { echo "  FAIL: cross-sells were synced\n"; $fail = 1; }
	if ( get_post_meta( $p->get_id(), "_e2e_custom_field", true ) ) { echo "  FAIL: custom meta was synced\n"; $fail = 1; }
	if ( ! $fail ) { echo "  PASS: tags, upsells, cross-sells and custom meta correctly NOT synced\n"; }
	exit( $fail );
	'
}

# Global attributes (pa_*) work — but only on variable products. Both halves of that sentence are
# load-bearing: the README claimed attributes worked generally, and on a simple product they are
# silently dropped with no error at all.
assert_global_attributes() {
	echo "==> Asserting global attributes reach the target (variable products)"
	twp eval '
	if ( ! taxonomy_exists( "pa_kolor" ) ) { echo "  FAIL: taxonomy pa_kolor was not created on the target\n"; exit( 1 ); }
	$p = wc_get_product( wc_get_product_id_by_sku( "E2E-GA-001" ) );
	if ( ! $p ) { echo "  FAIL: E2E-GA-001 did not sync\n"; exit( 1 ); }
	$found = false;
	foreach ( $p->get_attributes() as $k => $a ) {
		if ( "pa_kolor" === $k && $a->is_taxonomy() && count( $a->get_options() ) >= 2 ) { $found = true; }
	}
	if ( ! $found ) { echo "  FAIL: pa_kolor is missing or not a taxonomy attribute on the target\n"; exit( 1 ); }
	if ( count( $p->get_children() ) < 2 ) { echo "  FAIL: variations of the global-attribute product did not sync\n"; exit( 1 ); }
	echo "  PASS: pa_kolor taxonomy, terms and variation assignments all synced\n";
	'
}

# The sync must not merely finish — it must finish without errors.
#
# WARNING is in the pattern deliberately. Image sideload failures used to log at 'warning' and
# leave błędy=0, so a run that silently stripped images off products looked perfectly green here.
# Anything the plugin considers worth warning about is worth failing a test over; if a benign
# warning ever shows up, whitelist that specific one rather than widening this.
assert_no_errors() {
	echo "==> Asserting the sync logged no errors or warnings"
	twp eval '
	$files = glob( WP_CONTENT_DIR . "/uploads/wc-logs/wc-product-sync*.log" );
	if ( ! $files ) { echo "  FAIL: no plugin log found — did the sync run?\n"; exit( 1 ); }
	$bad = array();
	foreach ( $files as $f ) {
		foreach ( file( $f ) as $line ) {
			if ( preg_match( "/\b(ERROR|WARNING)\b|błędy=[1-9]/u", $line ) ) { $bad[] = trim( $line ); }
		}
	}
	if ( $bad ) {
		echo "  FAIL: " . count( $bad ) . " error/warning line(s) in the sync log\n";
		foreach ( array_slice( $bad, 0, 10 ) as $l ) { echo "    $l\n"; }
		exit( 1 );
	}
	echo "  PASS: no ERROR/WARNING lines, błędy=0\n";
	'
}

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
import json, re, sys
src, tgt, label = json.load(open(sys.argv[1])), json.load(open(sys.argv[2])), sys.argv[3]

# WooCommerce's REST layer runs wpautop()/do_shortcode() over the description on the way out,
# so the target legitimately stores <p>-wrapped markup the source never had. Comparing raw
# would fail on every product for a formatting difference that is not a data loss. Normalize
# markup and whitespace away — this still catches a dropped, truncated or mojibaked body.
def norm(s):
    s = re.sub(r"<[^>]+>", " ", s or "")
    return re.sub(r"\s+", " ", s).strip()

# Every field in the dump is compared. Adding a field to DUMP without adding it here would
# silently un-test it, so the field list is derived, not hand-maintained.
TEXT = {"desc", "short"}
fails = []

for sku in sorted(set(src) - set(tgt)):
    fails.append(f"{sku}: MISSING on target")
for sku in sorted(set(tgt) - set(src)):
    fails.append(f"{sku}: on target but gone from source (should have been deleted)")

for sku in sorted(set(src) & set(tgt)):
    s, t = src[sku], tgt[sku]
    for f in sorted(set(s) - {"vars"}):
        a, b = s.get(f), t.get(f)
        if f in TEXT:
            a, b = norm(a), norm(b)
        if a != b:
            fails.append(f"{sku}: {f} source={a!r} target={b!r}")
    sv, tv = s.get("vars", {}), t.get("vars", {})
    for v in sorted(set(sv) - set(tv)):
        fails.append(f"{sku}/{v}: variation MISSING on target")
    for v in sorted(set(tv) - set(sv)):
        fails.append(f"{sku}/{v}: variation on target but not source")
    for v in sorted(set(sv) & set(tv)):
        if sv[v] != tv[v]:
            fails.append(f"{sku}/{v}: source={sv[v]} target={tv[v]}")

nvars = sum(len(v.get("vars", {})) for v in src.values())
nimg = sum(v.get("n_images", 0) for v in src.values())
if fails:
    print(f"  FAIL [{label}]: {len(fails)} mismatch(es) over {len(src)} products / {nvars} variations")
    for f in fails[:30]:
        print("    " + f)
    if len(fails) > 30:
        print(f"    ... and {len(fails)-30} more")
    sys.exit(1)
print(f"  PASS [{label}]: {len(src)} products / {nvars} variations / {nimg} images match")
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
assert_status_filter
assert_global_attributes
assert_unsupported_not_synced
assert_no_errors

echo "==> Phase 2: force-full deletion across batches"
# `wp eval` takes no positional args — hand WPS_DROP over the environment.
# Must drop products that were actually SYNCED — published, with a SKU. Dropping a draft or
# a no-SKU product would delete something the target never had, so force-full would have
# nothing to do and phase 2 would pass while testing nothing at all.
DROPPED="$(docker compose exec -T -e "WPS_DROP=$DROP" src-cli wp --allow-root eval '
$n    = (int) getenv( "WPS_DROP" );
$skus = array();
foreach ( wc_get_products( array( "limit" => -1, "type" => "simple", "status" => "publish", "orderby" => "sku", "order" => "DESC" ) ) as $p ) {
	if ( count( $skus ) >= $n ) { break; }
	if ( ! $p->get_sku() ) { continue; }
	$skus[] = $p->get_sku();
	$p->delete( true );
}
echo implode( ",", $skus );
')"
DROPPED_N="$(printf '%s' "$DROPPED" | tr ',' '\n' | grep -c .)"
[ "$DROPPED_N" -eq "$DROP" ] || {
	echo "!! FAIL: expected to drop $DROP synced products, dropped $DROPPED_N ('$DROPPED')" >&2
	exit 1
}
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

# --- Phase 3: a failed image download must not destroy the images we already have ----------
#
# The scenario, exactly as it would happen in production: the source swaps a product's image,
# and the target cannot fetch the new one (blip, TLS mismatch, 502). Before the fix, the new
# key never entered the image map, so the cleanup pass deleted the product's existing
# attachments and an empty list stripped the images off the product — permanent local data
# loss from a transient network error, reported as błędy=0.
#
# We break fetching for real rather than mocking it: removing the source's mu-plugin makes WP
# advertise https:// image URLs again, and there is no TLS listener in the stack.
echo "==> Phase 3: image download failure must not delete existing images"

BEFORE="$(twp eval '
$p = wc_get_product( wc_get_product_id_by_sku( "E2E-S-001" ) );
echo ( $p->get_image_id() ? 1 : 0 ) + count( $p->get_gallery_image_ids() );
')"
echo "    target has $BEFORE image(s) for E2E-S-001"
[ "$BEFORE" -ge 1 ] || { echo "!! FAIL: fixture broken — E2E-S-001 has no images to lose" >&2; exit 1; }

# Source swaps in a NEW image (new attachment id => new map key => the target must download).
swp eval '
$p  = wc_get_product( wc_get_product_id_by_sku( "E2E-S-001" ) );
$up = wp_upload_dir();
$f  = trailingslashit( $up["path"] ) . "e2e-swapped.png";
file_put_contents( $f, base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" ) );
$att = wp_insert_attachment( array( "post_mime_type" => "image/png", "post_title" => "e2e-swapped", "post_status" => "inherit" ), $f );
$p->set_image_id( $att );
$p->save();
' >/dev/null

# Break image fetching: without the mu-plugin the source serves https:// URLs, which nothing
# in this stack can answer.
docker compose exec -T -u 0 src-wp rm -f /var/www/html/wp-content/mu-plugins/00-e2e-http-urls.php

twp eval 'foreach ( glob( WP_CONTENT_DIR . "/uploads/wc-logs/wc-product-sync*.log" ) as $f ) { unlink( $f ); }' >/dev/null
drive >/dev/null

AFTER="$(twp eval '
$p = wc_get_product( wc_get_product_id_by_sku( "E2E-S-001" ) );
echo ( $p->get_image_id() ? 1 : 0 ) + count( $p->get_gallery_image_ids() );
')"
echo "    target has $AFTER image(s) after the failed fetch"

FAIL=0
if [ "$AFTER" -lt "$BEFORE" ]; then
	echo "  FAIL: images were DESTROYED by a failed download ($BEFORE -> $AFTER)" >&2
	FAIL=1
fi
# And it must be reported, not swallowed: a run that loses nothing but says nothing is still
# a run where the operator never learns the source has an image they cannot fetch.
if ! twp eval 'foreach ( glob( WP_CONTENT_DIR . "/uploads/wc-logs/wc-product-sync*.log" ) as $f ) { echo file_get_contents( $f ); }' \
	| grep -qE 'błędy=[1-9]'; then
	echo "  FAIL: the failed image download was not counted as an error (błędy=0)" >&2
	FAIL=1
fi

# Put the source back the way we found it.
docker compose cp mu/00-e2e-http-urls.php src-wp:/var/www/html/wp-content/mu-plugins/00-e2e-http-urls.php

[ "$FAIL" -eq 0 ] || exit 1
echo "  PASS: existing images preserved, failure counted as an error"

# --- Phase 3b: the sync must survive losing /products/attributes ---------------------------
#
# WooCommerce guards /products/attributes with `manage_product_terms`, while /products only needs
# `read_private_products`. So an API key can list the entire catalog and still get 401 on the
# attributes endpoint — and plenty of stores cannot issue a broader key. That used to abort every
# single run.
#
# It does not have to: the map only ever carried name + slug per attribute, and WooCommerce ships
# both inline in every product AND variation payload. This proves the plugin rebuilds the map from
# the payloads and still creates the global attribute on the target.
echo "==> Phase 3b: global attributes must still sync when /products/attributes is 401"

cat > /tmp/wps-block-attr.php <<'PHP'
<?php
// Test rig only: make /products/attributes return the exact 401 a key without
// manage_product_terms would get, while leaving /products fully readable.
add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
	if ( '/wc/v3/products/attributes' === $request->get_route() ) {
		return new WP_Error( 'woocommerce_rest_cannot_view', 'Przepraszamy, ale nie możesz listować zasobów.', array( 'status' => 401 ) );
	}
	return $result;
}, 10, 3 );
PHP
docker compose cp /tmp/wps-block-attr.php src-wp:/var/www/html/wp-content/mu-plugins/wps-block-attr.php >/dev/null

# Force a real rebuild: drop the product and the attribute taxonomy from the target, so the sync
# has to recreate both WITHOUT the endpoint. Otherwise it would pass on leftovers from phase 1.
twp eval '
$id = wc_get_product_id_by_sku( "E2E-GA-001" );
if ( $id ) { wp_delete_post( $id, true ); }
$aid = wc_attribute_taxonomy_id_by_name( "kolor" );
if ( $aid ) { wc_delete_attribute( $aid ); }
' >/dev/null
twp eval 'foreach ( glob( WP_CONTENT_DIR . "/uploads/wc-logs/wc-product-sync*.log" ) as $f ) { unlink( $f ); }' >/dev/null
drive >/dev/null

ATTR_OK="$(twp eval '
if ( ! taxonomy_exists( "pa_kolor" ) ) { echo "no-taxonomy"; exit; }
$p = wc_get_product( wc_get_product_id_by_sku( "E2E-GA-001" ) );
if ( ! $p ) { echo "no-product"; exit; }
$ok = false;
foreach ( $p->get_attributes() as $k => $a ) {
	if ( "pa_kolor" === $k && $a->is_taxonomy() && count( $a->get_options() ) >= 2 ) { $ok = true; }
}
if ( ! $ok ) { echo "no-attribute"; exit; }
if ( count( $p->get_children() ) < 2 ) { echo "no-variations"; exit; }
echo "ok";
')"
ERRS_3B="$(twp eval '$r = get_option( "wps_last_sync_result" ); echo (int) ( $r["errors"] ?? 0 );')"

docker compose exec -T -u 0 src-wp rm -f /var/www/html/wp-content/mu-plugins/wps-block-attr.php

if [ "$ATTR_OK" != "ok" ]; then
	echo "  FAIL: with /products/attributes at 401 the global attribute did not sync ($ATTR_OK)" >&2
	echo "        A store whose key cannot read that endpoint would lose its variable products'" >&2
	echo "        attributes entirely." >&2
	exit 1
fi
if [ "$ERRS_3B" -ne 0 ]; then
	echo "  FAIL: the run reported błędy=$ERRS_3B — losing that endpoint must not be an error" >&2
	exit 1
fi
echo "  PASS: pa_kolor rebuilt from the product payloads, run clean (błędy=0)"

# --- Phase 4: an empty source must never wipe the target ----------------------------------
#
# HTTP 200 + an empty list is what the source returns when the API key belongs to a user who
# cannot see products, when the source URL points at the wrong site, or when nothing is
# published. It is indistinguishable from a genuinely empty catalog — and it used to be
# treated as a successful sync of nothing, reporting "błędy: 0" behind a green notice.
#
# That is a catalog-wipe waiting to happen: with force_full_sync (or deletion_mode=hard), a
# zero-product view of the source means "everything was deleted upstream". A bad API key would
# take the entire local catalog with it.
echo "==> Phase 4: an empty source must be an error, and must delete nothing"

BEFORE_N="$(twp eval 'echo count( get_posts( array( "post_type" => "product", "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) );')"
echo "    target holds $BEFORE_N products"

swp eval '
foreach ( get_posts( array( "post_type" => array( "product", "product_variation" ), "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) as $id ) {
	wp_delete_post( $id, true );
}' >/dev/null
echo "    source emptied (simulates a key that cannot see products)"

opt force_full_sync 1      # the most dangerous mode: deletes anything not refreshed
twp eval 'foreach ( glob( WP_CONTENT_DIR . "/uploads/wc-logs/wc-product-sync*.log" ) as $f ) { unlink( $f ); }' >/dev/null
drive >/dev/null
opt force_full_sync 0

AFTER_N="$(twp eval 'echo count( get_posts( array( "post_type" => "product", "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) );')"
ERRS="$(twp eval '$r = get_option( "wps_last_sync_result" ); echo (int) ( $r["errors"] ?? 0 );')"

FAIL4=0
if [ "$AFTER_N" -ne "$BEFORE_N" ]; then
	echo "  FAIL: an empty source DELETED products ($BEFORE_N -> $AFTER_N)" >&2
	FAIL4=1
fi
if [ "$ERRS" -lt 1 ]; then
	echo "  FAIL: an empty source reported błędy=$ERRS — a broken API key looks like success" >&2
	FAIL4=1
fi
[ "$FAIL4" -eq 0 ] || exit 1
echo "  PASS: $AFTER_N products intact, run reported błędy=$ERRS"

echo
echo "e2e PASS (multi-batch sync + force-full + image-failure + empty-source safety)"
