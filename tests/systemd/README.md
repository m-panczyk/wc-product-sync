# Sync-parity integrity test — scheduling

Drives the intended production cadence on the rig (the QNAP target has no wp-cron, so the plugin's
scheduled jobs must be driven externally). All runs share one lock (`/tmp/wps-sync-parity.lock`) so
they never overlap on the target.

- **`wps-price-full.timer`** → `12:00` daily → `sync-parity-test.sh full` (the daily full sync + price parity).
- **`wps-price-tick.timer`** → hourly at `:30` → `TEST_FIELD=price sync-parity-test.sh tick`.
- **`wps-stock-tick.timer`** → hourly at `:00` → `TEST_FIELD=stock sync-parity-test.sh tick`.

Each tick mutates `PARITY_TEST_K` (default 5) random SIMPLE products' field on the SOURCE
(`regular_price` for price, `manage_stock`+`stock_quantity` for stock), drives the fast update-only
sync on the TARGET, then verifies **every** simple product's field matches source↔target.

Outputs (gitignored): `metrics/<field>-history.csv` (every change) + `metrics/<field>-check.csv`
(one PASS/FAIL row per run). A mismatch makes the run exit non-zero → the systemd unit is marked
failed (`systemctl --user --failed`) and the Grafana alert fires.

The plugin must have `fast_sync_fields` including the tested field(s) — set to `price,stock`.

## Install (systemd --user)

```sh
cp tests/systemd/wps-*.{service,timer} ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now wps-price-tick.timer wps-stock-tick.timer wps-price-full.timer
loginctl enable-linger "$USER"          # run timers without an active login
```

Paths in the units are absolute (`/home/seth/Projekty/wpwc-prod-sync`) — adjust if the checkout moves.

## Watch

```sh
systemctl --user list-timers 'wps-*'
journalctl --user -u wps-stock-tick.service -f
tail -f metrics/stock-check.csv metrics/price-check.csv
```

## Manual run

```sh
TEST_FIELD=price tests/sync-parity-test.sh tick     # or full
TEST_FIELD=stock PARITY_TEST_K=10 tests/sync-parity-test.sh tick
```

Requires `tests/perf.env` with `QNAP_*` and `SRC_*` entries (see the file; it's gitignored).

## Monitoring

Each run emits (best-effort, never fails the test):
- **InfluxDB** metric `wps_price_check` (bucket `tests`, org `mppcc`): fields
  `checked/mismatch/mutated/batches/duration/fail` (`fail`=0/1), tags `mode`+`field`+`verdict`.
- **Grafana** region annotation (tags `wps-price`+`<field>`+verdict) over the run window.

The perf dashboard (`grafana-dashboard.json`, uid `wps-perf`) has two parity panels + a
`wps-price` annotation overlay. Apply to live Grafana:

```sh
tests/apply-dashboard.sh
```

(Self-contained bash — works from any shell, incl. fish. Do NOT `source perf.env` directly
from fish: it's bash syntax and will fail silently.)

## Alert

Grafana alert rule **"wps price-sync parity FAIL / stale"** (folder `wps-alerts`) fires on
`wps_price_check.fail > 0` (any field) or no data in 95 min (a missed timer), routed to the
`grafana-default-email` contact point. Also detectable via `systemctl --user --failed` and the
`metrics/*-check.csv` logs.
