#!/usr/bin/env bash
#
# apply-dashboard.sh — push tests/grafana-dashboard.json to the live Grafana (uid wps-perf).
# Self-contained bash so it works regardless of the interactive shell (e.g. fish, where
# `source perf.env`'s bash syntax fails). Run it yourself:  ! tests/apply-dashboard.sh
#
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
source "$DIR/perf.env"

python3 - "$DIR/grafana-dashboard.json" > /tmp/wps-di.json <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
x = d["dashboard"]
x.pop("id", None)                      # import by uid; let Grafana keep it
json.dump({"dashboard": x, "overwrite": True, "message": "price-parity panels + wps-price annotations"}, sys.stdout)
PY

code=$(curl -s -o /tmp/wps-di-resp.json -w '%{http_code}' \
  -H "Authorization: Bearer $GRAFANA_TOKEN" -H "Content-Type: application/json" \
  -X POST "$GRAFANA/api/dashboards/db" --data-binary @/tmp/wps-di.json)
echo "grafana POST /api/dashboards/db -> http=$code"
python3 -c 'import json;d=json.load(open("/tmp/wps-di-resp.json"));print("status=%s version=%s url=%s"%(d.get("status"),d.get("version"),d.get("url")))' 2>/dev/null \
  || { echo "response body:"; cat /tmp/wps-di-resp.json; }
[ "$code" = "200" ] || { echo "!! push failed (see body above)"; exit 1; }
echo "OK — open ${GRAFANA}/d/wps-perf"
