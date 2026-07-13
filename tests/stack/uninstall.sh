#!/usr/bin/env bash
# Uninstall test: does removing the plugin clean up after itself WITHOUT taking the catalog?
#
# uninstall() has a two-sided contract, and both sides can fail badly:
#
#   DELETES   options, the three cron events, the in-flight transients.
#             If this half breaks, the store accumulates dead cron events that keep firing
#             hooks for a plugin that no longer exists, and stale settings resurface if it is
#             ever reinstalled.
#
#   PRESERVES product meta (_wps_synced, _wps_source_id, _wps_image_map, _wps_soft_deleted_at)
#             and the wps-usuniete tag. If THIS half breaks, uninstalling the plugin destroys
#             data the store still runs on — the products stay, but everything that links them
#             back to the source is gone, so a reinstall re-creates duplicates instead of
#             matching. That is the failure worth guarding.
#
# The cleanup assertions are checked to be non-vacuous first: we verify the options, crons and
# transients actually EXIST before uninstalling, otherwise "it's gone afterwards" proves nothing.
#
# Assumes tests/stack/up.sh and tests/stack/seed.sh have run.
set -euo pipefail
cd "$(dirname "$0")"

swp() { docker compose exec -T src-cli wp --allow-root "$@"; }
twp() { docker compose exec -T tgt-cli wp --allow-root "$@"; }
opt() {
	docker compose exec -T -e "WPS_K=$1" -e "WPS_V=$2" tgt-cli wp --allow-root eval '
		$o = (array) get_option( "wc_product_sync_options", array() );
		$v = getenv( "WPS_V" );
		$o[ getenv( "WPS_K" ) ] = is_numeric( $v ) ? (int) $v : $v;
		update_option( "wc_product_sync_options", $o );' >/dev/null
}
invoke() { twp eval "\$m=new ReflectionMethod('WC_Product_Sync','$1');\$m->setAccessible(true);\$m->invoke(WC_Product_Sync::instance());"; }
drive() {
	twp transient delete wps_sync_progress >/dev/null 2>&1 || true
	twp transient delete wps_sync_running  >/dev/null 2>&1 || true
	invoke run_sync_cron >/dev/null
	local n=0
	while twp transient get wps_sync_progress --format=json 2>/dev/null | grep -q current_page; do
		n=$((n + 1)); [ "$n" -gt 60 ] && { echo "runaway" >&2; exit 1; }
		invoke run_resume_batch >/dev/null
	done
}

echo "==> Building state: full sync, then a soft-delete (so the tag and its meta exist)"
opt deletion_mode soft
opt force_full_sync 0
drive

# Drop two products from the source so the next run soft-deletes them: draft + wps-usuniete tag
# + _wps_soft_deleted_at. This is also the only coverage soft-delete has.
docker compose exec -T -e "N=2" src-cli wp --allow-root eval '
$n = (int) getenv( "N" );
foreach ( wc_get_products( array( "limit" => $n, "type" => "simple", "status" => "publish", "orderby" => "sku", "order" => "DESC" ) ) as $p ) {
	echo $p->get_sku() . " ";
	$p->delete( true );
}' >/dev/null
drive

# Make sure the cron events exist, so "uninstall cleared them" is a real assertion.
twp eval '
foreach ( array( "wc_product_sync_daily_event", "wps_sync_resume", "wc_product_sync_fast_event" ) as $h ) {
	if ( ! wp_next_scheduled( $h ) ) { wp_schedule_single_event( time() + 3600, $h ); }
}
set_transient( "wps_sync_source_keys", array( "x" ), 900 );
set_transient( "wps_update_info", array( "version" => "9.9.9" ), 900 );
update_option( "wps_last_sync_report", array( "x" ) );' >/dev/null

SNAP='
$out = array(
	"products"    => count( get_posts( array( "post_type" => "product", "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) ),
	"attachments" => count( get_posts( array( "post_type" => "attachment", "numberposts" => -1, "fields" => "ids", "post_status" => "any" ) ) ),
	"m_synced"    => 0, "m_source_id" => 0, "m_image_map" => 0, "m_soft_del" => 0,
	"tag"         => term_exists( "wps-usuniete", "product_tag" ) ? 1 : 0,
	"drafts"      => count( get_posts( array( "post_type" => "product", "post_status" => "draft", "numberposts" => -1, "fields" => "ids" ) ) ),
);
global $wpdb;
foreach ( array( "m_synced" => "_wps_synced", "m_source_id" => "_wps_source_id", "m_image_map" => "_wps_image_map", "m_soft_del" => "_wps_soft_deleted_at" ) as $k => $meta ) {
	$out[ $k ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta ) );
}
$out["opt_main"]   = get_option( "wc_product_sync_options" ) ? 1 : 0;
$out["opt_result"] = get_option( "wps_last_sync_result" ) ? 1 : 0;
$out["opt_report"] = get_option( "wps_last_sync_report" ) ? 1 : 0;
$out["cron_daily"] = wp_next_scheduled( "wc_product_sync_daily_event" ) ? 1 : 0;
$out["cron_resume"]= wp_next_scheduled( "wps_sync_resume" ) ? 1 : 0;
$out["cron_fast"]  = wp_next_scheduled( "wc_product_sync_fast_event" ) ? 1 : 0;
$out["tr_keys"]    = get_transient( "wps_sync_source_keys" ) ? 1 : 0;
$out["tr_update"]  = get_transient( "wps_update_info" ) ? 1 : 0;
echo wp_json_encode( $out );
'

BEFORE="$(twp eval "$SNAP")"
echo "    przed: $BEFORE"

# Non-vacuity: if the things uninstall() is supposed to remove are not there to begin with,
# the whole test proves nothing.
echo "$BEFORE" | python3 -c '
import json, sys
b = json.load(sys.stdin)
missing = [k for k in ("opt_main","opt_result","opt_report","cron_daily","cron_resume","cron_fast","tr_keys","tr_update") if not b[k]]
if missing:
    print("  FAIL: fixture is vacuous — these were already absent before uninstall: " + ", ".join(missing))
    sys.exit(1)
for k in ("products","m_synced","m_source_id","tag","drafts"):
    if not b[k]:
        print(f"  FAIL: fixture is vacuous — {k} is 0 before uninstall, nothing to preserve")
        sys.exit(1)
print("  fixture OK: options, crons and transients present; catalog, meta, tag and drafts present")
'

echo "==> Uninstalling the plugin (runs register_uninstall_hook)"
twp plugin uninstall wc-product-sync --deactivate >/dev/null

AFTER="$(twp eval "$SNAP")"
echo "    po:    $AFTER"

python3 - "$BEFORE" "$AFTER" <<'PY'
import json, sys
b, a = json.load(open('/dev/stdin')) if False else (json.loads(sys.argv[1]), json.loads(sys.argv[2]))
fails = []

# Half 1 — must be GONE.
for k, what in (
    ("opt_main",   "option wc_product_sync_options"),
    ("opt_result", "option wps_last_sync_result"),
    ("opt_report", "option wps_last_sync_report"),
    ("cron_daily", "cron wc_product_sync_daily_event"),
    ("cron_resume","cron wps_sync_resume"),
    ("cron_fast",  "cron wc_product_sync_fast_event"),
    ("tr_keys",    "transient wps_sync_source_keys"),
    ("tr_update",  "transient wps_update_info"),
):
    if a[k]:
        fails.append(f"NOT CLEANED UP: {what} survived the uninstall")

# Half 2 — must SURVIVE. This is the one that matters: losing it means the store keeps its
# products but loses every link back to the source, so a reinstall duplicates the catalog.
for k, what in (
    ("products",    "products"),
    ("attachments", "attachments (product images)"),
    ("m_synced",    "_wps_synced meta"),
    ("m_source_id", "_wps_source_id meta"),
    ("m_image_map", "_wps_image_map meta"),
    ("m_soft_del",  "_wps_soft_deleted_at meta"),
    ("drafts",      "soft-deleted drafts"),
):
    if a[k] < b[k]:
        fails.append(f"DATA DESTROYED: {what} {b[k]} -> {a[k]}")
if b["tag"] and not a["tag"]:
    fails.append("DATA DESTROYED: the wps-usuniete tag was removed")

if fails:
    print(f"\n  FAIL: {len(fails)} problem(s)")
    for f in fails:
        print("    " + f)
    sys.exit(1)
print(f"\n  PASS: options/crons/transients removed; {a['products']} products, {a['attachments']} attachments, "
      f"{a['m_synced']} _wps_synced, {a['m_image_map']} image maps, {a['drafts']} drafts and the tag all intact")
PY

echo
echo "uninstall PASS (cleanup complete, product data preserved)"
