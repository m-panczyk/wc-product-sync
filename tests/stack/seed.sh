#!/usr/bin/env bash
# Seed the source store with a deterministic catalog.
#
# Deterministic on purpose: a parity failure must be reproducible from the same seed,
# not a one-off that vanishes on re-run. Same SEED => same SKUs, prices and stock.
#
#   WPS_SIMPLE=40 WPS_VARIABLE=5 WPS_GROUPED=2 WPS_IMAGES=4 SEED=1337 tests/stack/seed.sh
set -euo pipefail
cd "$(dirname "$0")"

SIMPLE="${WPS_SIMPLE:-40}"
VARIABLE="${WPS_VARIABLE:-5}"
GROUPED="${WPS_GROUPED:-2}"
IMAGES="${WPS_IMAGES:-4}"
SEED="${SEED:-1337}"

echo "==> Seeding source: ${SIMPLE} simple, ${VARIABLE} variable, ${GROUPED} grouped, ${IMAGES} with images (seed=${SEED})"

# `wp eval` takes no positional args (that is eval-file) — pass them via the environment.
docker compose exec -T \
	-e "WPS_SIMPLE=$SIMPLE" -e "WPS_VARIABLE=$VARIABLE" -e "WPS_GROUPED=$GROUPED" \
	-e "WPS_IMAGES=$IMAGES" -e "WPS_SEED=$SEED" \
	src-cli wp --allow-root eval '
$simple   = (int) getenv( "WPS_SIMPLE" );
$variable = (int) getenv( "WPS_VARIABLE" );
$grouped  = (int) getenv( "WPS_GROUPED" );
$images   = (int) getenv( "WPS_IMAGES" );
$seed     = (int) getenv( "WPS_SEED" );
mt_srand( $seed );

// Wipe any previous catalog so re-seeding is idempotent.
foreach ( get_posts( array( "post_type" => array( "product", "product_variation" ), "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) as $id ) {
	wp_delete_post( $id, true );
}

// Two categories, so we can check a product carrying BOTH survives the sync — a single
// shared category would pass even if the plugin only ever copied the first one.
$cat_ids = array();
foreach ( array( "e2e", "e2e-secondary" ) as $slug ) {
	$t = term_exists( $slug, "product_cat" ) ?: wp_insert_term( $slug, "product_cat" );
	$cat_ids[] = (int) $t["term_id"];
}
$cat_id = $cat_ids[0];

// A 1x1 PNG is enough to exercise the sideload + _wps_image_map path without paying
// for real image bytes on every run.
$png = base64_decode( "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==" );

$make_image = function( $n ) use ( $png ) {
	$up   = wp_upload_dir();
	$name = "e2e-$n.png";
	file_put_contents( trailingslashit( $up["path"] ) . $name, $png );
	$id = wp_insert_attachment( array(
		"post_mime_type" => "image/png",
		"post_title"     => $name,
		"post_status"    => "inherit",
	), trailingslashit( $up["path"] ) . $name );
	return $id;
};

$n = 0;
for ( $i = 1; $i <= $simple; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( sprintf( "E2E Simple %03d", $i ) );
	$p->set_sku( sprintf( "E2E-S-%03d", $i ) );
	$p->set_regular_price( number_format( mt_rand( 500, 20000 ) / 100, 2, ".", "" ) );
	$p->set_manage_stock( true );
	$p->set_stock_quantity( mt_rand( 0, 250 ) );
	$p->set_status( "publish" );
	// Every field the plugin claims to sync (sync_fields), so an unsynced one is a failure
	// rather than something nobody looked at. Unicode in the body: a broken charset on the
	// REST hop would mangle it, and ASCII-only text would never notice.
	$p->set_description( sprintf( "Opis produktu %03d — zażółć gęślą jaźń. <strong>HTML</strong> & encje.", $i ) );
	$p->set_short_description( sprintf( "Krótki opis %03d", $i ) );
	$p->set_weight( number_format( mt_rand( 10, 5000 ) / 100, 2, ".", "" ) );
	$p->set_length( (string) mt_rand( 1, 60 ) );
	$p->set_width( (string) mt_rand( 1, 60 ) );
	$p->set_height( (string) mt_rand( 1, 60 ) );
	// Every 5th product is on sale and in both categories.
	if ( 0 === $i % 5 ) {
		$p->set_sale_price( number_format( (float) $p->get_regular_price() * 0.8, 2, ".", "" ) );
		$p->set_category_ids( $cat_ids );
	} else {
		$p->set_category_ids( array( $cat_id ) );
	}
	if ( $n < $images ) {
		$main = $make_image( ++$n );
		$p->set_image_id( $main );
		// The first image-bearing product also gets a GALLERY. A presence-only check
		// (has an image: yes/no) would pass even if the gallery were dropped entirely.
		if ( 1 === $n ) {
			$p->set_gallery_image_ids( array( $make_image( 900 ), $make_image( 901 ) ) );
		}
	}
	$p->save();
}

for ( $i = 1; $i <= $variable; $i++ ) {
	$p = new WC_Product_Variable();
	$p->set_name( sprintf( "E2E Variable %03d", $i ) );
	$p->set_sku( sprintf( "E2E-V-%03d", $i ) );
	$p->set_status( "publish" );
	$p->set_category_ids( array( $cat_id ) );
	$attr = new WC_Product_Attribute();
	$attr->set_name( "Rozmiar" );
	$attr->set_options( array( "S", "M", "L" ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p->set_attributes( array( $attr ) );
	$pid = $p->save();

	foreach ( array( "S", "M", "L" ) as $k => $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_sku( sprintf( "E2E-V-%03d-%s", $i, $size ) );
		$v->set_attributes( array( "rozmiar" => $size ) );
		$v->set_regular_price( number_format( mt_rand( 1000, 30000 ) / 100, 2, ".", "" ) );
				$v->set_manage_stock( true );
				$v->set_stock_quantity( mt_rand( 0, 100 ) );
				// First two variations also carry a non-standard tax class to test variation-level sync.
				if ( $k < 2 ) {
					$v->set_tax_class( "reduced-rate" );
				}
				$v->save();
	}
	// Roll parent min/max price + stock status up from the variations.
	WC_Product_Variable::sync( $pid );
}

for ( $i = 1; $i <= $grouped; $i++ ) {
	$children = wc_get_products( array( "limit" => 3, "type" => "simple", "return" => "ids" ) );
	$p = new WC_Product_Grouped();
	$p->set_name( sprintf( "E2E Grouped %03d", $i ) );
	$p->set_sku( sprintf( "E2E-G-%03d", $i ) );
	$p->set_status( "publish" );
	$p->set_children( $children );
	$p->save();
}

// --- The "Ograniczenia" contract in README, as fixtures -----------------------------------
// These exist so the supported/unsupported list cannot quietly drift from reality. Two of its
// claims were simply false until they were checked against a real store.

// GLOBAL attribute (pa_kolor) on a VARIABLE product — the README says attributes are supported,
// and they are, but ONLY on variable products.
$tax = "pa_kolor";
if ( ! wc_attribute_taxonomy_id_by_name( "kolor" ) ) {
	wc_create_attribute( array( "name" => "Kolor", "slug" => "kolor", "type" => "select" ) );
}
if ( ! taxonomy_exists( $tax ) ) {
	register_taxonomy( $tax, "product", array( "hierarchical" => false, "public" => true ) );
}
foreach ( array( "Czerwony", "Zielony" ) as $t ) {
	if ( ! term_exists( $t, $tax ) ) { wp_insert_term( $t, $tax ); }
}
$gv  = new WC_Product_Variable();
$gv->set_name( "E2E Global Attr" );
$gv->set_sku( "E2E-GA-001" );
$gv->set_status( "publish" );
$gid = $gv->save();
wp_set_object_terms( $gid, array( "Czerwony", "Zielony" ), $tax );
$ga = new WC_Product_Attribute();
$ga->set_id( wc_attribute_taxonomy_id_by_name( "kolor" ) );
$ga->set_name( $tax );
$ga->set_options( wp_list_pluck( get_terms( array( "taxonomy" => $tax, "hide_empty" => false ) ), "term_id" ) );
$ga->set_visible( true );
$ga->set_variation( true );
$gv->set_attributes( array( $ga ) );
$gv->save();
foreach ( array( "czerwony", "zielony" ) as $i => $slug ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $gid );
	$v->set_sku( "E2E-GA-001-" . $slug );
	$v->set_attributes( array( $tax => $slug ) );
	$v->set_regular_price( (string) ( 50 + $i * 10 ) );
	$v->save();
}
WC_Product_Variable::sync( $gid );

// Things the README says are NOT synced. e2e.sh asserts they really are not — an "unsupported"
// list is only worth anything if something checks that it stays true.
$u = new WC_Product_Simple();
$u->set_name( "E2E Unsupported" );
$u->set_sku( "E2E-UNSUP-001" );
$u->set_regular_price( "42.00" );
$u->set_status( "publish" );
$u->set_upsell_ids( array( wc_get_product_id_by_sku( "E2E-S-002" ) ) );
$u->set_cross_sell_ids( array( wc_get_product_id_by_sku( "E2E-S-003" ) ) );
$uid = $u->save();
wp_set_object_terms( $uid, array( "promocja" ), "product_tag" );
update_post_meta( $uid, "_e2e_custom_field", "wartosc-123" );

// --- Products the plugin MUST skip -------------------------------------------------------
// Nothing asserted these before, so "the plugin skips drafts / no-SKU products" was folklore.
// e2e.sh checks each of these is absent from the target.

// Draft: sync_statuses defaults to publish only.
$d = new WC_Product_Simple();
$d->set_name( "E2E Draft 001" );
$d->set_sku( "E2E-DRAFT-001" );
$d->set_regular_price( "99.00" );
$d->set_status( "draft" );
$d->save();

// Private: also outside the publish filter.
$v = new WC_Product_Simple();
$v->set_name( "E2E Private 001" );
$v->set_sku( "E2E-PRIVATE-001" );
$v->set_regular_price( "98.00" );
$v->set_status( "private" );
$v->save();

// No SKU: matching is SKU-first, so this one has nothing to match on and is skipped.
$k = new WC_Product_Simple();
$k->set_name( "E2E NoSku 001" );
$k->set_regular_price( "97.00" );
$k->set_status( "publish" );
$k->save();

// --- Tax class fixture -----------------------------------------------------------------------
// The plugin claims to sync tax_class (sync_fields includes 'tax_class'). This product has a
// non-standard tax class so the compare in e2e.sh can verify it actually arrives on target.
$taxClass = "reduced-rate";
$tc = new WC_Product_Simple();
$tc->set_name( "E2E Tax Class Fixture" );
$tc->set_sku( "E2E-TAX-001" );
$tc->set_regular_price( "10.00" );
$tc->set_status( "publish" );
$tc->set_category_ids( array( $cat_id ) );
$tc->set_tax_class( $taxClass );
$tc->save();

printf( "seeded: %d products (incl. draft/private/no-SKU skip cases, tax-class fixture)\n",
	count( get_posts( array( "post_type" => "product", "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) ) );
'
