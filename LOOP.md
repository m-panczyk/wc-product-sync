# wc-product-sync — Fix Loop Documentation (2026-07-05)

## Workflow
1. **Claude Code** reviews code and plans fixes (with exact lines + code)
2. **Codex** implements the plan
3. **Hermes** reviews diffs, verifies correctness
4. Repeat until solved or token limit

---

## PAST ROUNDS

### Round 1 — Security (commit 7576887)
- SQL injection: `$wpdb->prepare()` for `META_SYNCED` constant in force-full query

### Round 2 — Fixes v0.9.12 (commit 2e7ab38)
- N3: Diacritics fallback for `_wps_source_id` matching
- N5: Orphaned variation handling
- N9: Missing uninstall hook
- N10: Textdomain loader

### Round 3 — Performance v0.9.13 (commit 18de28f)
- P1: Removed redundant `WC_Product_Variable::sync()` from UPDATE path
- P2: Added source keys cap (20k max) in accumulate_source_keys()
- P4: Fixed `@set_time_limit()` error swallowing in all 3 locations

---

## TESTING IMPROVEMENTS — Multi-Round Improvement Loop (2026-07-12)

### Scope: 6 recommendations from project review

1. ✅ Gitignore perf.env → **already done** (was in .gitignore line 6)
2. ✅ Add plugin smoke test → Round 2 (commit 08ac419)
3. ✅ Add PHPCS-WP linting → Round 3 (commit c00f66d)
4. ✅ Extend sync-parity-test.sh coverage → Round 4 (commit 6e247cb)
5. ✅ Add performance regression threshold → Round 5 (commit 1f48b9a)
6. ✅ Document how to run tests manually → Round 6 (commit pending)

### Round 3 — PHPCS-WP linting config (commit c00f66d)

- **Files created**: `composer.json`, `phpcs.xml.dist`
- **Purpose**: Standardized WordPress coding standards check, single-file plugin context
- **Usage** (after `composer install`): `composer phpcs wc-product-sync.php`
- **Auto-fix**: `composer phpcbf --dry-run` to preview changes

### Round 4 — Extended parity tests (commit 6e247cb)

- **New full-mode checks added after existing price/stock parity**:
  - Variable product parent rollup: verifies `min_price`/`max_price` in `wp_postmeta` match actual variation child price ranges (with ±0.01 tolerance for rounding)
  - Soft-delete tagging sample: checks that ~100 draft products not matching `_wps_synced` have the `wps-usuniete` tag
- **Both contribute to VERDICT**: if rollup mismatch > 0, test FAILs

### Round 5 — Performance regression check (commit 1f48b9a)

- **Mechanism**: `check_regression()` function in `perf-run.sh`
- **Logic**: compares current duration against historical median of baseline runs for the same version; falls back to all-baseline median if no version-specific data
- **Threshold**: 1.5× median = WARNING (not a hard fail — perf context matters)
- **Output**: `PERF_OK:` or `PERF_REGRESSION:` on stderr

### Round 6 — Test documentation (commit pending)

- Added comprehensive Testing section to README.md
- Covers all test scripts, rig setup, usage examples, CI integration tips

### Round 2 — Plugin smoke test (commit 08ac419)

- **Agent**: Claude Code (planning, timed out), Hermes direct implementation
- **File created**: `tests/smoke-test.php` (157 lines)
- **Checks performed**:
  1. WC_Product_Sync class loads without fatal error
  2. Singleton instance() returns valid object
  3. get_option('wc_product_sync_options') doesn't fatal even when missing
  4. wp_next_scheduled('wc_product_sync_daily_event') works
  5. wp_next_scheduled('wc_product_sync_fast_event') works
  6. Class constants accessible via reflection (OPTION_KEY value verified)
  7. Singleton returns same instance on repeated calls
- **Usage**: `WP_SMOKE_RUN=1 wp eval 'include "/path/to/tests/smoke-test.php";'`

---

## ROUND 4 — Codex Full Code Review Results

### Run details:
- Tool: `codex exec review` with comprehensive review prompt (security, correctness, performance, WP/WC standards)
- Directory: `/home/seth/Projekty/wpwc-prod-sync` (real path to avoid symlink sandbox issues)
- Result: Found 2 P1/blocker issues

### P1 [CRITICAL] — Force-full sync deletes BEFORE validating source availability
- File: wc-product-sync.php, lines ~1408-1435
- Problem: When `force_full_sync` is enabled, the code first fetches source attributes/products API endpoints, BUT if ANY of those requests fail AFTER products have been deleted, the local catalog is already wiped with no recovery. The current order: (1) delete all locally synced products → (2) fetch source attributes → (3) fetch source products. If step 2 or 3 fails, sync aborts but data is gone.
- Fix: Move the destructive delete block to AFTER successful source fetches complete. Or better: stage the deletions and only commit them if ALL prerequisite API calls succeed.

### P1 [CRITICAL] — Capped source keys treated as safe for deletion
- File: wc-product-sync.php, lines ~303-309 (our Round 3 P2 fix)
- Problem: When `accumulate_source_keys()` truncates keys past the 20k cap, it logs a warning but does NOT set `$c['had_error'] = true`. The downstream delete logic (line ~360 area) checks `$c['had_error']` to decide if the key set is safe for deletion — if false, ALL products not in the key set are considered "missing from source" and get soft/hard deleted. With truncation, valid products get dropped from the key set → FALSE POSITIVE DELETIONS.
- Fix: Set `$c['had_error'] = true` when keys are truncated past the cap. This marks the collection as incomplete/unsafe for deletion decisions.
