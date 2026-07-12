# WC Product Sync — TODO (current: v0.9.18 → next tagged = 1.0)

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

## ✅ Completed (v0.9.18)

- **Fix: `$fast_mode` leaked across WP-Cron hooks in one request.** WP-Cron runs all due hooks in a
  single request on the singleton; `run_fast_sync_cron` set `$fast_mode=true` but never reset it, so
  if the hourly fast event ran before the daily `CRON_HOOK` in the same request, the daily FULL sync
  inherited fast mode and silently degraded to update-only (no create/delete, no images/desc/etc.).
  Common on low-traffic sites (both events overdue → one wp-cron.php run). Fix: `run_sync_cron` now
  sets `$fast_mode=false` authoritatively before running, and `run_fast_sync_cron` resets it in a
  `finally` (`run_resume_batch` re-derives mode from saved progress, so multi-batch fast runs are
  unaffected). Surfaced by `/code-review`. Verified on rig via reflection (leaked true → false at both
  entry points) + green price/stock parity ticks.
- Version header bumped 0.9.17 → 0.9.18.

---

## ✅ Completed (v0.9.22 — private update server token)

- **Token auth for the updater.** `WC_PRODUCT_SYNC_UPDATE_TOKEN` (constant / `wps_update_token` filter)
  lets the updater reach a private Forgejo/Gitea repo. The JSON fetch adds `Authorization: token …`
  directly; the ZIP download (performed by WP core, not our code) is authorized via an
  `http_request_args` filter **scoped strictly to the UPDATE_URL host** — the token never leaks to any
  other server and never clobbers a pre-set Authorization header. Empty token → unchanged anonymous
  behavior.

---

## ✅ Completed (v0.9.21 — self-hosted updates)

- **Self-hosted JSON updater** (opt-in via `WC_PRODUCT_SYNC_UPDATE_URL`). Filters
  `pre_set_site_transient_update_plugins` (inject available update / mark up-to-date) + `plugins_api`
  (version-details modal), fed by a cached JSON endpoint. Result: one-click updates in the WP Plugins
  screen — no re-upload. Undefined constant → updater fully inert (no HTTP). Metadata cached 12h on
  success / 2h negative on failure; flushed after any plugin update; transient cleaned on uninstall.
- `build.sh` now emits `dist/update.json` alongside the zip (fields derived from the plugin header;
  host via `WPS_UPDATE_BASE_URL`). README documents enabling + hosting + publish flow.
- Lightweight/self-contained (no bundled update-checker library) to keep the single-file distribution.

---

## ✅ Completed (v0.9.20 — force-full fix)

- **[CRITICAL]: Force-full sync either no-op'd or wiped the fresh catalog.** The delete was gated on
  `$is_first_batch` but ran only in the completion path. On batched catalogs completion lands in a
  resume batch (`is_first_batch=false`) → force-full **never ran**. On single-batch catalogs it ran on
  the first batch and the query (`WHERE meta_key='_wps_synced'`, no value filter) deleted **all** synced
  products — including the ones just created/updated in the same run.
- **Fix:** dropped the `$is_first_batch` gate so force-full runs on whichever batch completes the sync,
  and changed the delete to `... AND CAST(meta_value AS UNSIGNED) < <run_start>`. Every product synced
  this run is re-stamped `_wps_synced = time()` (on any batch), so only products carrying a timestamp
  older than the run start (gone from source) are removed. `run_start` comes from
  `wps_last_sync_result['started_at']`, which persists across resume batches.
- **Fail-safe:** if no reliable `run_start`, the wipe is skipped entirely. Deletions are recorded in the
  `hard_deleted` report bucket. UI label/description corrected (was "wipe before running").
- **Verified on rig** (492 products, batch_limit=200 → 3 batches, `force_full_sync=1`): force-full ran on
  the completing resume batch and deleted **0** products at full source parity (`updated=492 errors=0`,
  price parity `mismatch=0`), confirming both that it now runs on batched catalogs and that it no longer
  deletes freshly-synced products. Old plugin backed up on target as `wc-product-sync.php.bak-0918`.

---

## ✅ Completed (v0.9.19 — Round 4: Codex full code review)

- **P1 [CRITICAL]: Force-full sync delete moved from BEFORE fetches to AFTER processing.**
  Previously deleted ALL synced local products immediately on first batch, before any API fetching.
  If source attributes/products endpoint failed afterwards → everything permanently wiped with no recovery.
  Fix: move deletion block to after grouped product processing + right before soft-delete cleanup,
  guarded by `! $this->fetch_had_error`. Sync completes its fetch cycle; only then does it remove old
  products, guaranteeing the source list is validated first.

- **P2 [CRITICAL]: Capped source keys now marked unsafe for deletion.**
  Our v0.9.13 P2 fix added a 20k key cap to `accumulate_source_keys()` but didn't set `$c['had_error']`
  when truncation occurred. Downstream delete logic checks `had_error` to decide if the key set is
  complete — false meant "safe to delete." With truncation, valid products dropped from the capped
  set would be falsely considered "missing from source" and soft/hard-deleted. Fix: now sets
  `$c['had_error'] = true` on cap hit, preventing deletion of incomplete key sets. Updated warning log
  explicitly states deletion is skipped.

- Codex performed a comprehensive `codex exec review` across security, correctness, performance,
  and WordPress/WC standards — identified these two data-loss risks that manual review had missed.

---

## v0.9.18+ — Remaining open items

### Resolved:
- **P2 (source keys cap → false-positive deletion):** Fixed in v0.9.19. Capped keys now set `had_error = true`, blocking safe/unsafe deletion decisions. No more accidental deletion of valid products when catalog > 20k.

### Nice-to-have / optional:
- N7: Global image dedup by source URL (cross-product) — reduce duplicate attachments when multiple products share images
- readme.txt: Plugin repo metadata for WordPress.org submission
- PHPCS-WP linting pass: Code style compliance with WooCommerce/WordPress standards
