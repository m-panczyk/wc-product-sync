# WC Product Sync — TODO (current: v0.9.17 → next tagged = 1.0)

## ✅ Completed

### Security (round 1, commit 7576887)
- N1: SQL injection in force-full query — `$wpdb->prepare()` for `META_SYNCED` constant (#38)

### Fixes (v0.9.12, commit 2e7ab38)
- N3: Variation attribute diacritics — `build_variation_attributes()` now calls `ensure_term()` (creating the missing term so the stored slug resolves) instead of emitting a bare `sanitize_title()` slug that dangled (#44)
- N5: Orphaned variation handling — detect and remove type-changed variations that no longer match any source child (#110)
- N9: Missing `WC_Product_Sync::uninstall()` hook + wp_clear_scheduled_hook cleanup on uninstall (#129)
- N10: Textdomain loader (`load_plugin_textdomain`) added at `init` action (#92)

---

## ✅ Completed (v0.9.13 — Round 3, commit 18de28f)

### Performance & correctness (Round 3)
- P1: Removed `WC_Product_Variable::sync()` from UPDATE path (perf) — **partially reverted in v0.9.14, see below**
- P2: Added source keys cap (20k max) to accumulate_source_keys() — prevents OOM on large catalogs
- P4: Added error logging for `@set_time_limit()` failures in all 3 locations (was silently swallowing errors)

---

## ✅ Completed (v0.9.14, commit 7a70495)

- P1-fix: Unconditionally dropping the parent rollup (v0.9.13) left `wc_product_meta_lookup`
  min/max_price stale when a variation's price/stock changed → wrong catalog sort-by-price and
  broken price-filter widget for variable products (display range was fine; lookup table was not).
  `sync_variations()` now returns whether a rollup-relevant change occurred (variation
  added/removed, or a `regular_price`/`sale_price`/`stock_*` change via `get_changes()`), and the
  UPDATE path calls `WC_Product_Variable::sync()` only then. Keeps the v0.9.13 perf win on no-op
  updates. Verified on rig end-to-end (MAT-108 35→3035 rolled up correctly; 492/492, ~40s).
- Version header bumped 0.9.13 → 0.9.14 (deployed to QNAP target).

---

## ✅ Completed (v0.9.15)

- P1-followup: `sync_variations()` rollup detection missed variation **status** changes. WC's
  `sync_price` query only counts `publish` variations, so a source variation flipped
  publish↔private (or draft) changes which children feed the parent min/max price — but the
  v0.9.14 `$rollup_props` allowlist omitted `status`, leaving `wc_product_meta_lookup` stale.
  Added `status` to `$rollup_props` (and dropped the dead `price` entry — it's a save-time-derived
  prop, never present in `get_changes()` beforehand). Surfaced by `/code-review`.
  Verified source-driven E2E on rig: flipped source var 6689 (price 2900) → private, real plugin
  sync → target parent 4088 max_price rolled 2900→350; reverted cleanly back to 2900. 492/492,
  0 errors, ~40s each run (fast path intact).
- Version header bumped 0.9.14 → 0.9.15 (deployed to QNAP target).

---

## ✅ Completed (v0.9.16 — Fast field-refresh sync)

- **Feature: cyclical "fast sync"** — a lightweight recurring run that refreshes only volatile
  fields (default price + stock, configurable) on a free-form minute interval (floored to 15 to
  avoid hammering the source), separate from the daily full sync. **Update-only**: never creates
  or deletes products/variations — those stay with the daily sync. Implemented by reusing the full
  pipeline via a run-scoped `$fast_mode` flag rather than a parallel loop:
  - `field_on()` switches to `fast_sync_fields` in fast mode; `deletion_enabled()` returns false;
    `create_new_product()` returns `skipped`; `sync_variations()` skips variation add/remove;
    grouped final pass and `force_full` are bypassed.
  - Own cron event `FAST_CRON_HOOK` on a dynamic `cron_schedules` interval; reconciled on
    settings-save / activate (reschedules when interval changes), cleared on deactivate/uninstall.
  - Mutual exclusion with the daily sync via progress/lock guards; `fast` flag persisted in the
    progress transient so multi-batch fast runs stay fast across resumes.
  - New options: `fast_sync_enabled`, `fast_sync_interval_min`, `fast_sync_fields` + admin UI row.
  - Verified E2E on rig: source price 190→260 refreshed on target while description (not selected)
    preserved; new source product SKIPPED by fast (created=0) but CREATED by full sync; cron
    scheduled at 900s. 492/492, 0 errors, ~40s.
- Version header bumped 0.9.15 → 0.9.16.

---

## ✅ Completed (v0.9.17)

- **Auto-refresh admina wyłączony domyślnie:** strona postępu przeładowywała się w całości co 8 s
  (`location.reload()`), co destabilizowało UI (przeskok scrolla, migotanie). Teraz bramkowane
  ustawieniem `admin_auto_refresh` (domyślnie OFF) + zawsze dostępny ręczny przycisk „Odśwież postęp".
- **Test parytetu stanów magazynowych + uogólnienie harnessu:** `tests/price-sync-test.sh` →
  `tests/sync-parity-test.sh` z parametrem `TEST_FIELD=price|stock`. Nowy timer systemd
  `wps-stock-tick` (godzinowo @:00) mutuje losowe `stock_quantity` na źródle → szybki sync → parytet
  `stock_quantity` źródło↔cel; price tick pozostaje @:30, full @12:00 (wspólny lock). `fast_sync_fields`
  ustawione na `price,stock`. Metryka InfluxDB `wps_price_check` dostała tag `field`; alert łapie oba.
  Zweryfikowane: stock tick PASS (checked=433, mismatch=0).
- **Dokumentacja wydajności:** `docs/PERFORMANCE.md` — czasy (492 prod: pełny bez obrazów ~127 s,
  pierwszy z obrazami ~858–892 s, inkrementalny bez zmian ~40 s, szybki ~40 s), analiza wąskich gardeł
  (sideload obrazów ≈85 %), mechanizmy (batching/resume, mapa obrazów, szybki sync, rollup na żądanie),
  strojenie i ograniczenia.
- Version header bumped 0.9.16 → 0.9.17.

---

## v0.9.17+ — Remaining open items

### Known limitation (from P2):
- Source keys cap (20k) interacts with soft-delete: on catalogs >20k with soft-delete enabled,
  products beyond the cap look "absent from source" and could be soft-deleted. Per-run deletion
  safety cap limits blast radius. Add a code comment noting this; revisit if >20k catalogs are
  a real target (e.g. raise cap or exempt soft-delete from the cap).

### Nice-to-have / optional:
- N7: Global image dedup by source URL (cross-product) — reduce duplicate attachments when multiple products share images
- readme.txt: Plugin repo metadata for WordPress.org submission
- PHPCS-WP linting pass: Code style compliance with WooCommerce/WordPress standards
