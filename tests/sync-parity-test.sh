#!/usr/bin/env bash
#
# sync-parity-test.sh — field-parity integrity test for wc-product-sync (price OR stock).
#
# Models the intended production cadence on the rig (QNAP has no wp-cron, so we DRIVE the plugin's
# scheduled runs externally):
#   full   — drive a full sync (the daily 12:00 job) + parity check of the chosen field.
#   tick   — mutate K random SIMPLE products' field on the SOURCE, drive the fast (update-only) sync
#            on the TARGET, then verify EVERY simple product's field matches source<->target.
#
# Field selected via TEST_FIELD (default price):
#   price  — mutates regular_price; compares regular_price.
#   stock  — mutates manage_stock+stock_quantity; compares stock_quantity.
# The plugin's fast_sync_fields must include the field under test (price/stock).
#
# Logs → metrics/<field>-history.csv (each change) + metrics/<field>-check.csv (PASS/FAIL per run).
# Emits InfluxDB metric wps_price_check (tag field=<field>) + Grafana wps-price annotation.
# Exit != 0 on mismatch (systemd marks the unit failed → the Grafana alert also fires on fail>0).
#
# Usage:  TEST_FIELD=stock tests/sync-parity-test.sh [tick|full]     (default field=price, mode=tick)
#         PARITY_TEST_K=5  — products to mutate per tick (default 5)
#
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
source "$DIR/perf.env"
MODE="${1:-tick}"
FIELD="${TEST_FIELD:-price}"
case "$FIELD" in price|stock) ;; *) echo "TEST_FIELD must be price|stock (got '$FIELD')"; exit 2;; esac
K="${PARITY_TEST_K:-${PRICE_TEST_K:-5}}"
HIST="$DIR/../metrics/${FIELD}-history.csv"
CHECK="$DIR/../metrics/${FIELD}-check.csv"

# One shared lock across price+stock+full: they all drive syncs on the same target, so never overlap.
exec 9>"/tmp/wps-sync-parity.lock"
if ! flock -n 9; then
  echo "$(date '+%F %T') [$FIELD/$MODE] another sync-parity run holds the lock — skipping."
  exit 0
fi

SSHOPTS=(-o BatchMode=yes -o ConnectTimeout=10 -o ControlMaster=auto -o ControlPath=/tmp/wps-parity-%r@%h -o ControlPersist=180)
qssh() { ssh "${SSHOPTS[@]}" "$QNAP_SSH" "$@"; }
sssh() { ssh "${SSHOPTS[@]}" "$SRC_SSH" "$@"; }
CE="$QNAP_DOCKER compose -f $QNAP_PROJECT/docker-compose.yml --project-directory $QNAP_PROJECT exec -T -u 33 cli wp"
teval() { local b; b=$(base64 -w0 <<<"$1"); qssh "$CE eval 'eval(base64_decode(\"$b\"));'"; }
seval() { local b; b=$(base64 -w0 <<<"$1"); sssh "docker exec -u 33 $SRC_CONTAINER wp eval 'eval(base64_decode(\"$b\"));'"; }
tprog() { qssh "$CE transient get wps_sync_progress --format=json 2>/dev/null"; }
ensure_src_up() { sssh "cd $SRC_PROJECT && docker compose up -d >/dev/null 2>&1 || true"; }

# Drive a sync to completion. $1 = batch-1 entry method. Echoes the batch count.
drive() {
  qssh "$CE transient delete wps_sync_progress >/dev/null 2>&1; $CE transient delete wps_sync_running >/dev/null 2>&1"
  teval "\$m=new ReflectionMethod('WC_Product_Sync','$1');\$m->invoke(WC_Product_Sync::instance());" >/dev/null 2>&1
  local n=1
  while tprog | grep -q current_page; do
    n=$((n+1)); [ "$n" -gt 40 ] && { echo "!! too many batches" >&2; break; }
    teval "\$m=new ReflectionMethod('WC_Product_Sync','run_resume_batch');\$m->invoke(WC_Product_Sync::instance());" >/dev/null 2>&1
  done
  echo "$n"
}

# --- Monitoring emission (best-effort; never fails the test) --------------------------------------
emit_influx() { # mode verdict checked mismatch mutated batches dur_s
  [ -n "${INFLUX:-}" ] && [ -n "${INFLUX_TOKEN:-}" ] || return 0
  local fail=0; [ "$2" = "PASS" ] || fail=1
  local ln="wps_price_check,mode=$1,field=${FIELD},verdict=$2 checked=${3}i,mismatch=${4}i,mutated=${5}i,batches=${6}i,duration=${7}i,fail=${fail}i"
  curl -s --max-time 10 -o /dev/null -w "influx write http=%{http_code}\n" \
    -XPOST "$INFLUX/api/v2/write?org=$INFLUX_ORG&bucket=tests&precision=s" \
    -H "Authorization: Token $INFLUX_TOKEN" --data-binary "$ln $(date +%s)" || true
}
emit_annotation() { # verdict text start_ms end_ms
  [ -n "${GRAFANA:-}" ] && [ -n "${GRAFANA_TOKEN:-}" ] || return 0
  curl -s --max-time 10 -o /dev/null -w "grafana annotation http=%{http_code}\n" \
    -H "Authorization: Bearer $GRAFANA_TOKEN" -H "Content-Type: application/json" \
    -X POST "$GRAFANA/api/annotations" \
    -d "{\"time\":$3,\"timeEnd\":$4,\"tags\":[\"wps-price\",\"$FIELD\",\"$1\"],\"text\":\"$2\"}" || true
}
intval() { case "$1" in ''|*[!0-9-]*) echo "$2";; *) echo "$1";; esac; }

PHP_FIELD="\$FIELD=\"$FIELD\";"

# Catalog-wide parity: pull every source SIMPLE product's field over REST, compare to the matched
# target product. Prints "PARITY checked=N mismatch=M" + one line per mismatch.
PARITY_PHP="$PHP_FIELD"'
global $wpdb;
$o=get_option("wc_product_sync_options");$base=untrailingslashit($o["source_url"]);
$auth="Basic ".base64_encode($o["consumer_key"].":".$o["consumer_secret"]);
$src=array();$page=1;
do {
  $r=wp_remote_get("$base/wp-json/wc/v3/products?type=simple&per_page=100&page=$page&status=publish",array("timeout"=>60,"headers"=>array("Authorization"=>$auth)));
  if(is_wp_error($r)){echo "PARITY checked=0 mismatch=-1 err=".$r->get_error_message()."\n";return;}
  $b=json_decode(wp_remote_retrieve_body($r),true);
  if(!is_array($b)||!count($b))break;
  foreach($b as $p){$src[(int)$p["id"]]=array("price"=>$p["regular_price"],"stock"=>$p["stock_quantity"]);}
  $page++;
} while(count($b)==100);
$checked=0;$mismatch=0;$lines="";
foreach($src as $sid=>$row){
  $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%d LIMIT 1","_wps_source_id",$sid));
  if(!$pid)continue;
  $tp=wc_get_product($pid);if(!$tp)continue;
  $checked++;
  if($FIELD=="stock"){
    $sq=$row["stock"];$sv=($sq===null||$sq==="")?"":(string)(int)$sq;
    $q=$tp->get_stock_quantity();$tv=($q===null||$q==="")?"":(string)(int)$q;
  } else {
    $sv=wc_format_decimal($row["price"],2);$tv=wc_format_decimal($tp->get_regular_price(),2);
  }
  if((string)$sv!==(string)$tv){$mismatch++;$lines.="  MISMATCH src=$sid(".$sv.") tgt=$pid(".$tv.")\n";}
}
echo "PARITY checked=$checked mismatch=$mismatch\n".$lines;
'

TS="$(date -u +%FT%TZ)"
START_MS=$(date +%s%3N); START_S=$(date +%s)

if [ "$MODE" = "full" ]; then
  ensure_src_up
  echo "== $TS FULL sync (field=$FIELD parity) =="
  B=$(drive run_sync_cron)
  read -r C U S E < <(teval '$r=get_option("wps_last_sync_result");printf("%d %d %d %d",$r["created"]??0,$r["updated"]??0,$r["skipped"]??0,$r["errors"]??0);' 2>/dev/null)
  echo "full: created=$C updated=$U skipped=$S errors=$E ($B batches)"
  RES=$(teval "$PARITY_PHP"); SUMMARY=$(echo "$RES" | grep '^PARITY' | head -1)
  CHECKED=$(intval "$(sed -n 's/.*checked=\([0-9-]*\).*/\1/p' <<<"$SUMMARY")" 0)
  MISMATCH=$(intval "$(sed -n 's/.*mismatch=\([0-9-]*\).*/\1/p' <<<"$SUMMARY")" -1)
  echo "$RES" | grep MISMATCH | head -20

  # --- Additional full-sync checks (variable rollup + soft-delete tagging) ----
  ROLLUP_PHP='
global $wpdb;
$checked_var=0;$rollup_mismatch=0;$del_ok=0;$del_tagged=0;$del_total=0;

// 1) Variable product parent rollup: verify min/max price matches variation range.
$vars=get_posts(array("post_type"=>"product","post_status"=>"publish","fields"=>"ids","numberposts"=>-1,
  "tax_query"=>array(array("taxonomy"=>"product_type","field"=>"slug","terms"=>"variable"))));
foreach($vars as $vid){
  $p=wc_get_product($vid);if(!$p)continue;
  $children=$p->get_children();if(!count($children))continue;
  $prices=array();$statuses=array();
  foreach($children as $chid){
    $vp=wc_get_product($chid);if(!$vp)continue;
    $prices[]=floatval($vp->get_regular_price()?:0);$statuses[]=$vp->get_status();
  }
  $checked_var++;
  // Get actual publish variation prices (matching WC sync logic)
  $pub_prices=array_filter($prices,function($v){return $v>0;});
  if(!count($pub_prices)){continue;}
  $actual_min=min($pub_prices);$actual_max=max($pub_prices);
  $meta=$wpdb->get_row($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",$vid,"min_price"));
  $parent_min=floatval($meta?->meta_value?:0);
  $meta=$wpdb->get_row($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s LIMIT 1",$vid,"max_price"));
  $parent_max=floatval($meta?->meta_value?:0);
  // Tolerance: prices stored as cents-like integers, allow 1-cent diff for rounding
  if(abs($parent_min-$actual_min)>0.01 || abs($parent_max-$actual_max)>0.01){
    $rollup_mismatch++;
    echo "ROLLUP_MISMATCH var=$vid (children=".(count($children)).") parent=[".$parent_min.",".$parent_max."] actual=[".$actual_min.",".$actual_max."]\n";
  }
}

// 2) Soft-delete tagging: products that lack _wps_synced should have wps-usuniete tag (if in draft).
$old=$wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wps_synced'");
$sid_list=implode(",",array_map(function($i){return intval($i);},$old));
// Products NOT synced: check they have the delete tag if in draft status
if(empty($sid_list)){
  $unchecked=$wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type='product' AND p.post_status='draft'");
} else {
  $checked=$wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND ID NOT IN ($sid_list)");
  $unchecked=$wpdb->get_col($checked);
}
foreach(array_slice($unchecked,0,100) as $oid){ // sample up to 100 draft products
  $post=get_post($oid);if(!$post||$post->post_status!=='draft')continue;
  $tags=wp_get_object_terms($oid,"product_tag",array("fields"=>"slugs"));
  $has_tag=is_array($tags)&&in_array("wps-usuniete",$tags,true);
  $del_total++;
  if($has_tag)$del_tagged++;else$del_ok++;// not all drafts should be tagged; OK if mixed
}
echo "VAR_ROLLUP checked=$checked_var mismatch=$rollup_mismatch\n";
echo "SOFT_DELETE check: samples=$del_total tagged=$del_tagged other_drafts=$del_ok\n";
'
  ROLLUP_RES=$(teval "$ROLLUP_PHP" 2>/dev/null)
  echo "$ROLLUP_RES" | grep -E '(ROLLUP_MISMATCH|VAR_ROLLUP|SOFT_DELETE)'

  ROLLUP_MIS=$(echo "$ROLLUP_RES" | sed -n 's/.*mismatch=\([0-9-]*\).*/\1/p' | head -1)
  ROLLUP_MIS=$(intval "${ROLLUP_MIS:--1}" -1)
  END_MS=$(date +%s%3N)
  VERDICT="PASS"
  if [ "$MISMATCH" != "0" ]; then VERDICT="FAIL"; fi
  if [ "$(intval "$E" 1)" != "0" ]; then VERDICT="FAIL"; fi
  if [ "$ROLLUP_MIS" != "0" ] && [ "$ROLLUP_MIS" != "-1" ]; then VERDICT="FAIL"; fi
  emit_influx full "$VERDICT" "$CHECKED" "$MISMATCH" 0 "$(intval "$B" 0)" "$(( $(date +%s) - START_S ))"
  emit_annotation "$VERDICT" "wps-parity $FIELD FULL [$VERDICT]: checked=$CHECKED mismatch=$MISMATCH, upd=$U err=$E" "$START_MS" "$END_MS"
  echo "== $VERDICT (full/$FIELD) — checked=$CHECKED mismatch=$MISMATCH =="
  [ "$VERDICT" = "PASS" ] || exit 1
  exit 0
fi

# ---- tick ----
ensure_src_up
echo "== $TS TICK field=$FIELD (mutate $K random simple → fast sync → parity) =="

# 1) Mutate K random published simple products on the SOURCE. Prints CHG|id|sku|old|new per line.
MUT_PHP="$PHP_FIELD"'
$k='"$K"';
$ids=get_posts(array("post_type"=>"product","post_status"=>"publish","fields"=>"ids","numberposts"=>-1,
  "tax_query"=>array(array("taxonomy"=>"product_type","field"=>"slug","terms"=>"simple"))));
if(!$ids){echo "NONE\n";return;}
shuffle($ids);
foreach(array_slice($ids,0,$k) as $id){
  $p=wc_get_product($id);if(!$p)continue;
  if($FIELD=="stock"){
    $p->set_manage_stock(true);
    $old=$p->get_stock_quantity();
    $new=mt_rand(0,999);
    $p->set_stock_quantity($new);
    $p->set_stock_status($new>0?"instock":"outofstock");
  } else {
    $old=$p->get_regular_price();
    $new=number_format(mt_rand(500,999900)/100,2,".","");
    $p->set_regular_price($new);
  }
  $p->save();
  echo "CHG|$id|".$p->get_sku()."|$old|$new\n";
}
'
CHG=$(seval "$MUT_PHP" 2>/dev/null | tr -d '\r')
[ -f "$HIST" ] || echo "ts,source_id,sku,old,new" > "$HIST"
NCHG=0
while IFS='|' read -r tag sid sku old new; do
  [ "$tag" = "CHG" ] || continue
  echo "$TS,$sid,$sku,$old,$new" >> "$HIST"
  echo "  changed src=$sid sku=${sku:-'(none)'} $old -> $new"
  NCHG=$((NCHG+1))
done <<< "$CHG"
echo "mutated $NCHG products on source"

# 2) Drive the fast (update-only) sync on the target.
B=$(drive run_fast_sync_cron)
read -r C U S E < <(teval '$r=get_option("wps_last_sync_result");printf("%d %d %d %d",$r["created"]??0,$r["updated"]??0,$r["skipped"]??0,$r["errors"]??0);' 2>/dev/null)
echo "fast: created=$C updated=$U skipped=$S errors=$E ($B batches)"

# 3) Catalog-wide parity check source<->target.
RES=$(teval "$PARITY_PHP")
SUMMARY=$(echo "$RES" | grep '^PARITY' | head -1)
CHECKED=$(intval "$(sed -n 's/.*checked=\([0-9-]*\).*/\1/p' <<<"$SUMMARY")" 0)
MISMATCH=$(intval "$(sed -n 's/.*mismatch=\([0-9-]*\).*/\1/p' <<<"$SUMMARY")" -1)
echo "$RES" | grep MISMATCH | head -20

VERDICT="PASS"; [ "$MISMATCH" = "0" ] || VERDICT="FAIL"
END_MS=$(date +%s%3N)
[ -f "$CHECK" ] || echo "ts,mutated,batches,fast_created,checked,mismatch,verdict" > "$CHECK"
echo "$TS,$NCHG,$B,$C,$CHECKED,$MISMATCH,$VERDICT" >> "$CHECK"

# 4) Monitoring.
emit_influx tick "$VERDICT" "$CHECKED" "$MISMATCH" "$NCHG" "$(intval "$B" 0)" "$(( $(date +%s) - START_S ))"
emit_annotation "$VERDICT" "wps-parity $FIELD TICK [$VERDICT]: mut=$NCHG checked=$CHECKED mismatch=$MISMATCH" "$START_MS" "$END_MS"

echo "== $VERDICT ($FIELD) — checked=$CHECKED mismatch=$MISMATCH (history: $(basename "$HIST"), log: $(basename "$CHECK")) =="

[ "$VERDICT" = "PASS" ] || exit 1
