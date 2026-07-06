#!/usr/bin/env bash
#
# price-sync-test.sh — price-sync integrity test for wc-product-sync.
#
# Models the intended production cadence on the rig (QNAP has no wp-cron, so we DRIVE the plugin's
# scheduled runs externally):
#   full   — drive a full sync (the daily 12:00 job).
#   tick   — mutate K random SIMPLE-product prices on the SOURCE, drive the fast (price-only) sync
#            on the TARGET, then verify EVERY simple product's price matches source<->target.
#            Appends each change to metrics/price-history.csv and each verdict to
#            metrics/price-check.csv. Exit code != 0 on any mismatch (so systemd marks it failed).
#
# Usage:  tests/price-sync-test.sh [tick|full]     (default: tick)
#         PRICE_TEST_K=5  — how many products to mutate per tick (default 5)
#
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
source "$DIR/perf.env"
MODE="${1:-tick}"
K="${PRICE_TEST_K:-5}"
HIST="$DIR/../metrics/price-history.csv"
CHECK="$DIR/../metrics/price-check.csv"

# Serialize runs — a long full sync must not overlap the next hourly tick.
exec 9>"/tmp/wps-price-test.lock"
if ! flock -n 9; then
  echo "$(date '+%F %T') [$MODE] another run holds the lock — skipping."
  exit 0
fi

SSHOPTS=(-o BatchMode=yes -o ConnectTimeout=10 -o ControlMaster=auto -o ControlPath=/tmp/wps-price-%r@%h -o ControlPersist=180)
qssh() { ssh "${SSHOPTS[@]}" "$QNAP_SSH" "$@"; }
sssh() { ssh "${SSHOPTS[@]}" "$SRC_SSH" "$@"; }
CE="$QNAP_DOCKER compose -f $QNAP_PROJECT/docker-compose.yml --project-directory $QNAP_PROJECT exec -T -u 33 cli wp"
# Run PHP via base64 so no shell layer mangles $vars/quotes (same trick as perf-run.sh).
teval() { local b; b=$(base64 -w0 <<<"$1"); qssh "$CE eval 'eval(base64_decode(\"$b\"));'"; }
seval() { local b; b=$(base64 -w0 <<<"$1"); sssh "docker exec -u 33 $SRC_CONTAINER wp eval 'eval(base64_decode(\"$b\"));'"; }
tprog() { qssh "$CE transient get wps_sync_progress --format=json 2>/dev/null"; }

ensure_src_up() { sssh "cd $SRC_PROJECT && docker compose up -d >/dev/null 2>&1 || true"; }

# Drive a sync to completion. $1 = the batch-1 entry method. Echoes the batch count.
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

# Compares EVERY source simple product's regular_price against the matched target product.
# Runs entirely on the TARGET: it pulls source prices over the (read-only) REST API and compares
# to the local synced products. Prints "PARITY checked=N mismatch=M" + one line per mismatch.
PARITY_PHP='
global $wpdb;
$o=get_option("wc_product_sync_options");$base=untrailingslashit($o["source_url"]);
$auth="Basic ".base64_encode($o["consumer_key"].":".$o["consumer_secret"]);
$src=array();$page=1;
do {
  $r=wp_remote_get("$base/wp-json/wc/v3/products?type=simple&per_page=100&page=$page&status=publish",array("timeout"=>60,"headers"=>array("Authorization"=>$auth)));
  if(is_wp_error($r)){echo "PARITY checked=0 mismatch=-1 err=".$r->get_error_message()."\n";return;}
  $b=json_decode(wp_remote_retrieve_body($r),true);
  if(!is_array($b)||!count($b))break;
  foreach($b as $p){$src[(int)$p["id"]]=$p["regular_price"];}
  $page++;
} while(count($b)==100);
$checked=0;$mismatch=0;$lines="";
foreach($src as $sid=>$sprice){
  $pid=(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%d LIMIT 1","_wps_source_id",$sid));
  if(!$pid)continue;
  $tp=wc_get_product($pid);if(!$tp)continue;
  $checked++;
  $sv=wc_format_decimal($sprice,2);$tv=wc_format_decimal($tp->get_regular_price(),2);
  if((string)$sv!==(string)$tv){$mismatch++;$lines.="  MISMATCH src=$sid(".$sv.") tgt=$pid(".$tv.")\n";}
}
echo "PARITY checked=$checked mismatch=$mismatch\n".$lines;
'

TS="$(date -u +%FT%TZ)"

if [ "$MODE" = "full" ]; then
  ensure_src_up
  echo "== $TS FULL sync =="
  B=$(drive run_sync_cron)
  read -r C U S E < <(teval '$r=get_option("wps_last_sync_result");printf("%d %d %d %d",$r["created"]??0,$r["updated"]??0,$r["skipped"]??0,$r["errors"]??0);' 2>/dev/null)
  echo "full: created=$C updated=$U skipped=$S errors=$E ($B batches)"
  RES=$(teval "$PARITY_PHP")
  echo "$RES" | head -1
  exit 0
fi

# ---- tick ----
ensure_src_up
echo "== $TS TICK (mutate $K random simple prices → fast sync → parity) =="

# 1) Mutate K random published simple products on the SOURCE. Prints CHG|id|sku|old|new per line.
MUT_PHP='
$k='"$K"';
$ids=get_posts(array("post_type"=>"product","post_status"=>"publish","fields"=>"ids","numberposts"=>-1,
  "tax_query"=>array(array("taxonomy"=>"product_type","field"=>"slug","terms"=>"simple"))));
if(!$ids){echo "NONE\n";return;}
shuffle($ids);
foreach(array_slice($ids,0,$k) as $id){
  $p=wc_get_product($id);if(!$p)continue;
  $old=$p->get_regular_price();
  $new=number_format(mt_rand(500,999900)/100,2,".","");
  $p->set_regular_price($new);$p->save();
  echo "CHG|$id|".$p->get_sku()."|$old|$new\n";
}
'
CHG=$(seval "$MUT_PHP" 2>/dev/null | tr -d '\r')
[ -f "$HIST" ] || echo "ts,source_id,sku,old_price,new_price" > "$HIST"
NCHG=0
while IFS='|' read -r tag sid sku old new; do
  [ "$tag" = "CHG" ] || continue
  echo "$TS,$sid,$sku,$old,$new" >> "$HIST"
  echo "  changed src=$sid sku=${sku:-'(none)'} $old -> $new"
  NCHG=$((NCHG+1))
done <<< "$CHG"
echo "mutated $NCHG products on source"

# 2) Drive the fast (price-only) sync on the target.
B=$(drive run_fast_sync_cron)
read -r C U S E < <(teval '$r=get_option("wps_last_sync_result");printf("%d %d %d %d",$r["created"]??0,$r["updated"]??0,$r["skipped"]??0,$r["errors"]??0);' 2>/dev/null)
echo "fast: created=$C updated=$U skipped=$S errors=$E ($B batches)"

# 3) Catalog-wide parity check source<->target.
RES=$(teval "$PARITY_PHP")
SUMMARY=$(echo "$RES" | grep '^PARITY' | head -1)
CHECKED=$(sed -n 's/.*checked=\([0-9-]*\).*/\1/p' <<<"$SUMMARY")
MISMATCH=$(sed -n 's/.*mismatch=\([0-9-]*\).*/\1/p' <<<"$SUMMARY")
echo "$RES" | grep MISMATCH | head -20

VERDICT="PASS"; [ "${MISMATCH:-1}" = "0" ] || VERDICT="FAIL"
[ -f "$CHECK" ] || echo "ts,mutated,batches,fast_created,checked,mismatch,verdict" > "$CHECK"
echo "$TS,$NCHG,$B,$C,${CHECKED:-?},${MISMATCH:-?},$VERDICT" >> "$CHECK"
echo "== $VERDICT — checked=${CHECKED:-?} mismatch=${MISMATCH:-?} (history: $(basename "$HIST"), log: $(basename "$CHECK")) =="

[ "$VERDICT" = "PASS" ] || exit 1
