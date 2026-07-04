#!/usr/bin/env bash
#
# perf-run.sh — timed full-sync perf run for wc-product-sync.
#
# Drives a complete sync on the QNAP TARGET (source = Proxmox docker host), times it,
# and marks the window in Grafana with a region annotation (label = plugin version + stats).
# Host metrics for both source & target flow into InfluxDB via Telegraf; the annotation lets
# you line up the sync with CPU/RAM/net/disk on the dashboard.
#
# Usage:  tests/perf-run.sh [label]      (label defaults to "manual")
#
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
source "$DIR/perf.env"
LABEL="${1:-manual}"
CSV="$DIR/../metrics/perf-history.csv"

SSHOPTS=(-o BatchMode=yes -o ControlMaster=auto -o ControlPath=/tmp/wps-perf-%r@%h -o ControlPersist=300)
qssh() { ssh "${SSHOPTS[@]}" "$QNAP_SSH" "$@"; }
CE="$QNAP_DOCKER compose -f $QNAP_PROJECT/docker-compose.yml --project-directory $QNAP_PROJECT exec -T -u 33 cli wp"
# Plain wp subcommand (no PHP $vars).
qwp()  { qssh "$CE $*"; }
# Run PHP via base64 so no shell layer (local/ssh/remote) mangles $vars or quotes.
qeval() { local b; b=$(printf '%s' "$1" | base64 -w0); qssh "$CE eval 'eval(base64_decode(\"$b\"));'"; }
progress_set() { qwp transient get wps_sync_progress --format=json 2>/dev/null | grep -q current_page; }

VERSION=$(qwp plugin get wc-product-sync --field=version 2>/dev/null | tr -d '\r')
echo "== perf-run: label='$LABEL'  plugin v$VERSION  $(date '+%F %T') =="

# Comparable baseline: force_full clears previously-synced products so every run creates from scratch.
qeval '$o=get_option("wc_product_sync_options");$o["force_full_sync"]=1;update_option("wc_product_sync_options",$o);' >/dev/null 2>&1
qwp transient delete wps_sync_progress   >/dev/null 2>&1
qwp transient delete wps_sync_running    >/dev/null 2>&1
qwp option   delete wps_last_sync_result >/dev/null 2>&1

START_MS=$(date +%s%3N); START_S=$(date +%s)
echo "-- batch 1 (force_full + create) ..."
qeval '$m=new ReflectionMethod("WC_Product_Sync","run_sync");$m->setAccessible(true);$m->invoke(WC_Product_Sync::instance(),false);' >/dev/null 2>&1

n=1
while progress_set; do
  n=$((n+1)); echo "-- resume batch $n ... ($(date +%T))"
  qeval '$m=new ReflectionMethod("WC_Product_Sync","run_resume_batch");$m->setAccessible(true);$m->invoke(WC_Product_Sync::instance());' >/dev/null 2>&1
  [ "$n" -gt 100 ] && { echo "!! too many batches, aborting"; break; }
done
END_MS=$(date +%s%3N); END_S=$(date +%s); DUR=$((END_S-START_S))

read -r C U S E < <(qeval '$r=get_option("wps_last_sync_result");printf("%d %d %d %d",$r["created"]??0,$r["updated"]??0,$r["skipped"]??0,$r["errors"]??0);' 2>/dev/null)
PROD=$(qwp post list --post_type=product --format=count 2>/dev/null | tr -d '\r')
echo "== done: created=$C updated=$U skipped=$S errors=$E  products=$PROD  ${DUR}s ($n batches) =="

# Grafana region annotation over the run window.
TEXT="wc-product-sync v$VERSION [$LABEL]: created=$C updated=$U skipped=$S err=$E — ${DUR}s, ${n} batchy"
curl -s -o /dev/null -w "grafana annotation http=%{http_code}\n" \
  -H "Authorization: Bearer $GRAFANA_TOKEN" -H "Content-Type: application/json" \
  -X POST "$GRAFANA/api/annotations" \
  -d "{\"time\":$START_MS,\"timeEnd\":$END_MS,\"tags\":[\"wps-sync\",\"v$VERSION\",\"$LABEL\"],\"text\":\"$TEXT\"}"

# Peak target CPU during the window (from InfluxDB).
flux() { curl -s --max-time 10 -XPOST "$INFLUX/api/v2/query?org=$INFLUX_ORG" \
  -H "Authorization: Token $INFLUX_TOKEN" -H 'Content-Type: application/vnd.flux' -H 'Accept: application/csv' --data-binary "$1" \
  | awk -F',' '{for(i=1;i<=NF;i++) if($i+0==$i && $i!="") v=$i} END{printf "%.1f", v}'; }
START_RFC=$(date -u -d "@$START_S" +%Y-%m-%dT%H:%M:%SZ); END_RFC=$(date -u -d "@$END_S" +%Y-%m-%dT%H:%M:%SZ)
PEAKCPU=$(flux "from(bucket:\"tests\")|>range(start:$START_RFC,stop:$END_RFC)|>filter(fn:(r)=>r.role==\"target\" and r._measurement==\"cpu\" and r._field==\"usage_active\")|>max()")
echo "target peak CPU during run: ${PEAKCPU:-n/a}%"

[ -f "$CSV" ] || echo "started_at,version,label,duration_s,batches,created,updated,skipped,errors,products,target_peak_cpu" > "$CSV"
echo "$START_RFC,$VERSION,$LABEL,$DUR,$n,$C,$U,$S,$E,$PROD,${PEAKCPU:-}" >> "$CSV"
echo "logged -> $CSV"
