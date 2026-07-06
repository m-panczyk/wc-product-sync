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
