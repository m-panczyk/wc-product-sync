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

$cat = term_exists( "e2e", "product_cat" ) ?: wp_insert_term( "e2e", "product_cat" );
$cat_id = (int) $cat["term_id"];

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
	$p->set_category_ids( array( $cat_id ) );
	if ( $n < $images ) { $p->set_image_id( $make_image( ++$n ) ); }
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

printf( "seeded: %d products\n", count( get_posts( array( "post_type" => "product", "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) ) );
'
