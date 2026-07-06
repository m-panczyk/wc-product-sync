# Price-sync integrity test — scheduling

Drives the intended production cadence on the rig (the QNAP target has no wp-cron, so the plugin's
scheduled jobs must be driven externally):

- **`wps-price-full.timer`** → `12:00` daily → `price-sync-test.sh full` (the daily full sync).
- **`wps-price-tick.timer`** → hourly at `:30` → `price-sync-test.sh tick`: mutate `PRICE_TEST_K`
  (default 5) random SIMPLE-product prices on the SOURCE, drive the fast (price-only) sync on the
  TARGET, then verify **every** simple product's `regular_price` matches source↔target.

Outputs (gitignored): `metrics/price-history.csv` (every change) and `metrics/price-check.csv`
(one PASS/FAIL row per tick). A mismatch makes the tick exit non-zero → the systemd unit is marked
failed (`systemctl --user --failed`).

## Install (systemd --user)

```sh
cp tests/systemd/wps-price-*.{service,timer} ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now wps-price-tick.timer wps-price-full.timer
loginctl enable-linger "$USER"          # run timers without an active login
```

Paths in the units are absolute (`/home/seth/Projekty/wpwc-prod-sync`) — adjust if the checkout moves.

## Watch

```sh
systemctl --user list-timers 'wps-price-*'
journalctl --user -u wps-price-tick.service -f
tail -f metrics/price-check.csv
```

## Manual run

```sh
tests/price-sync-test.sh tick     # or: full
PRICE_TEST_K=10 tests/price-sync-test.sh tick
```

Requires `tests/perf.env` with `QNAP_*` and `SRC_*` entries (see the file; it's gitignored).
