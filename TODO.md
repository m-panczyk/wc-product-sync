# wc-product-sync — Fix TODO (for AI agent)

Current version: **0.9.0** (1.0-candidate: soft-delete under batching, cron-health fallback, N1/N6)

## v0.9.0 — 1.0-readiness fixes (2026-07-04)

Implemented after the 1.0 review; all verified on the docker WP+WC bed.

### #1 BLOCKER — soft-delete silently disabled under batching — **FIXED**
Defaults (`per_page=100`, `batch_limit=200`) mean any catalog >200 runs batched, and the old guard
(`$is_first_batch`) meant soft-delete never ran. Now source keys (SKU/name) are **accumulated across
all batches** in transient `wps_sync_source_keys` (+count, +had_error), and soft-delete runs on the
FINAL batch against the whole-catalog view. Skipped if any batch had a fetch error (incomplete view).
Dry runs stay single-pass (in-memory keys). **Verified:** keys 99→199→469 across batches; a true
orphan was drafted+tagged while an early-batch product survived; transient cleared after.
NOTE: grouped-child resolution across batches (B3 remainder) is still open — separate from soft-delete.

### #2 BLOCKER — manual run silently does nothing if WP-Cron doesn't fire — **FIXED**
Added an `updated_at` heartbeat to the progress transient and a stall detector in the UI: if no batch
holds the sync lock AND there's been no heartbeat for >150s, show a warning + a "Kontynuuj teraz
ręcznie (bez WP-Cron)" button (new `admin_post_wc_product_sync_step` → runs one batch synchronously).
`cancel_sync()` now also un-sticks the `running` result flag. **Verified:** warning shows only when
stalled; suppressed when fresh or while a batch holds the lock.

### N6 — attribute-fetch failure could wipe variable-product attributes — **FIXED**
`fetch_source_attributes()` now sets `$attributes_fetch_failed`; `run_sync_inner()` aborts the run
BEFORE touching any product (sets `fetch_had_error`, retries on resume) instead of rebuilding variable
products with no attributes.

### N1 — HTTP source URL leaks API keys — **FIXED**
`source_url_is_insecure()` flags http:// on public hosts (allows localhost/RFC1918/.local/.test);
`sanitize_options()` shows a settings warning. **Verified** against 5 URL cases.

**Remaining for a full 1.0:** grouped children across batches (B3), cancel mid-batch doesn't stop an
in-flight batch, B7 atomic lock, images-on-update, N3/N5/N7/N9/N12, PHPCS-WP pass, `readme.txt`.
Docker test-bed WP-Cron loopback was fixed separately via a wpcron sidecar (see wp-docker-test repo).
Target file: `wc-product-sync.php` (single-file WordPress plugin).

General rules for the agent:
- Preserve existing code style: tabs, WordPress PHP coding standards, Polish log/UI strings.
- Bump plugin `Version` header after applying fixes.
- After edits, verify: `php -l wc-product-sync.php` if PHP available; otherwise check brace balance and that each modified method is defined exactly once.
- Do not change the public behavior contract except where a task says so.

**Already done — do not redo** (see git history for details): C1–C4, M1, M2, M3, M4, M5, N8, N11.

---

## v0.8.3 UX — background manual run + live progress (2026-07-04)

**Problem reported by owner:** clicking "Synchronizuj teraz" gave no feedback — `handle_manual_run()`
ran the whole sync synchronously in the request, blocking the browser for minutes (image sideload)
before redirecting, and the settings-page progress notice was only reachable afterwards and static.

**Fix (chosen: background + live progress):**
- Real run no longer runs in the request. `handle_manual_run()` seeds a "starting" progress transient,
  resets the cumulative result, schedules an immediate one-off `CRON_HOOK` event, calls `spawn_cron()`,
  and redirects instantly to `?started=1`. Dry run stays synchronous (its point is the count report).
- New `seed_sync_progress()` (current_page=0 = fresh first batch), `reset_run_result()` /
  `accumulate_run_result()` (cumulative counts across batches in option `wps_last_sync_result`).
- `run_sync_inner()`: force_full and single-pass soft-delete now key off `$is_first_batch`
  (`empty($progress) || current_page < 1`) so the seed transient doesn't disable them.
- Progress notice: "starting" spinner state before page 1, real bar afterwards, and an 8s JS
  auto-refresh emitted only while a sync is active (stops itself on completion).
- Completion notice (option-based) shows cumulative "utworzono/zaktualizowano/…" when the auto-refresh
  lands the user on the idle page.

**Verified live (docker):** starting state renders spinner + auto-refresh; cron batch 1 advances the
bar to `strona 1/5` with exact totals and `started_at` preserved; result accumulates across batches;
final batch clears progress, flips `running=false`, and the idle page shows
"Synchronizacja zakończona … Utworzono: 14, zaktualizowano: 78, …". Environment restored afterwards.

**Note:** relies on WP-Cron (loopback) to fire the kickoff — enabled here (`DISABLE_WP_CRON` unset). On
hosts with WP-Cron disabled the run starts on the next page load instead (seed keeps showing "Uruchamianie").
This also supersedes TODO **N2** for the run action (dry still uses a GET link).

**0.8.4 HOTFIX — render_admin_page DivisionByZeroError (critical error / WSOD):** the new "starting"
state has `products_processed = 0`; the ETA calc `remaining / ($processed/$elapsed)` divided by a zero
rate → fatal on PHP 8 once `elapsed > 5s`. (The ETA was also computed before the `$is_starting` branch,
so it ran even though the starting view doesn't show ETA.) Guarded with `$processed > 0` and rewrote as
`remaining * elapsed / processed`. Verified: starting state with `elapsed>5` renders; running-state ETA
computes; idle OK. The pre-existing latent bug never fired before because progress was only saved with
`processed >= per_page`.

---

## v0.8.2 LIVE REVIEW (2026-07-04) — findings from testing against real WP+WC

Tested on the docker test bed (`wp-docker-test`: WP + WooCommerce 10.9.1, source = live store,
500 products / 5 pages, `per_page=100`, `batch_limit=50`). PHP 8.3 lint clean.

### L1. CRITICAL — "Dry run" armed a REAL, writing sync — **FIXED in v0.8.2**
**Where:** `run_sync_inner()` batch-limit branch.
**Problem:** the batch-limit check saved a progress transient and scheduled `wps_sync_resume`
regardless of `$dry_run`. `run_resume_batch()` calls `run_sync_inner(false, …)` (non-dry), so a
dry run against any catalog larger than `batch_limit` queued a real sync that fired on the next
WP-cron tick and started creating/updating/soft-deleting products. Reproduced live: a dry run
left a progress transient (`products_processed:100`) and a `wps_sync_resume` event +9s.
**Fix:** wrapped progress-save + batch-limit scheduling in `if ( ! $dry_run )`. Dry runs now
process every page in one pass and persist nothing.
**Verified:** dry run → created=422/updated=47/skipped=23, NO transient, NO scheduled resume, 47
products unchanged.

### L2. MAJOR — grouped products dropped at the batch boundary — **FIXED in v0.8.2**
**Where:** grouped-drain loop after the page loop.
**Problem:** `$break_from_limit` was set `true` inside the page-loop limit branch, then
unconditionally reset to `false` on the line immediately after the loop, destroying the flag. The
grouped-drain limit check therefore always ran, so grouped products buffered on the page where the
limit hit were skipped — and resume restarts at the NEXT page, so they were never processed.
Also `$batch_limit_hit_at_page` was set but never read.
**Fix:** removed `$break_from_limit` / `$batch_limit_hit_at_page`; the grouped buffer is now always
fully drained (its items were already counted and belong to already-fetched pages). Added a clean
`$hit_batch_limit` flag handled once after the loop. NOTE: cross-batch grouped *child* resolution
(children on later, not-yet-synced pages) is still the open part of B3 below.

### L3. MAJOR — resume completion had an always-false sub-condition — **FIXED in v0.8.2**
**Where:** `run_resume_batch()` completion check.
**Problem:** `$processed !== $new['products_processed'] && $new['products_processed'] === $progress['products_processed']`
is a contradiction (`$processed` == `$progress['products_processed']`), so the stall-detection
branch never fired — only `current_page >= total_pages` did.
**Fix:** rewrote as: complete iff transient cleared, OR (no fetch error AND (past last page OR no
forward progress)). The fetch-error guard prevents a transient network error from being mistaken
for a stall and aborting the whole sync.

### L4. MINOR — elapsed time / ETA reset every page — **FIXED in v0.8.2**
**Where:** `save_sync_progress()`.
**Problem:** `'started_at' => time()` was written on every page save, so the admin UI's "Czas pracy"
and ETA measured time-since-last-page, not since sync start.
**Fix:** preserve the existing transient's `started_at` across saves.

### L5. HOUSEKEEPING — version header reconciled — **DONE in v0.8.2**
Commit `9930a67` claimed a bump to 0.8.2 but the file header still read `0.8.1`. Header now `0.8.2`.

### L6. MINOR — B4 UI polish — **FIXED in v0.8.2**
- Progress now shows `strona X/Y` (real `total_pages` from the transient) instead of `strona X/?`.
- Capture the exact `X-WP-Total` header → store `total_items`; progress % uses it so the bar reaches
  100% instead of overcounting the last partial page via `total_pages × per_page`.
- Removed the dead `foreach_product()` method (was unused since `run_sync_inner()` got its own loop).
**Verified:** transient shows `total_items:492` (exact) and the UI renders `100 / 492 (20.3%) — strona 1/5`.

### Live end-to-end verification (real WP+WC, source = 500 products / 5 pages)
- Dry run: full catalog simulated, NO progress transient, NO scheduled resume, 0 DB writes (L1).
- Real batch 1 → 2: `current_page` 1→2, `products_processed` 100→200, `started_at` **unchanged**
  across the boundary (L4), resume rescheduled each time (L2/L3 — no restart, no early stop).
- Completion: advancing to the last page clears the progress transient and schedules NO new resume
  (no infinite loop); firing a resume with no progress is a harmless no-op (L3).
- Idempotency: a second consecutive run reports `created=0, updated=92` (unchanged source).
- NOTE: the source pages 1–2 contained no `grouped` products, so L2's grouped-drain fix is verified
  by code inspection (buffer is always drained) but was not empirically triggered.

---

## v0.8.0 BATCHING REVIEW (uncommitted changes) — highest priority

Findings from review of the auto-batching / WP-Cron resume work in `wc-product-sync.php`.
No `php` binary in this environment — verify with a multi-batch run (catalog > `2 × batch_limit`).
Do B1 first: one-line fix, largest impact, independent.

### B1. COMPLETED — sync stops early after the 2nd batch (`total_pages` lost on resume)
**Fixed in v0.8.1:** When loading `total_pages` from saved progress, also restore `$this->last_total_pages`.

### B2. COMPLETED — products skipped when `batch_limit` is not a multiple of `per_page`
**Fixed in v0.8.1:** Removed mid-page progress saves (every 10 products). Progress now only saved at page boundaries after all products on a page are processed. Resume always picks up from next fully-processed page (idempotent upserts safe). Batch limit check moved AFTER processing each product.

### B3. MAJOR — grouped products & soft-delete silently disabled under batching
**Where:** grouped buffer `run_sync_inner()`; soft-delete guard L921.
**Problem:** both are whole-catalog operations assuming a single pass. Grouped products are buffered in `$grouped_buf` and only processed after the page loop; a batch returning early at the limit discards the buffer and resume starts at a later page, so grouped items on already-fetched pages are never processed. Soft-delete is guarded by `empty( $progress )` so it never runs on a resumed batch (protective — a partial `$source_keys` would wrongly soft-delete most of the catalog).
**Note from Codex round 2:** grouped products increment `products_processed` count twice (once in main loop when added to buffer, once when processed in grouped loop), inflating progress numbers. Soft-delete uses `$source_keys` rebuilt per PHP process, so on final resume batch it only has keys from that single batch — risk of incorrectly soft-deleting products from earlier batches.
**Fix (needs design decision — flag to owner):** accumulate `$source_keys` + grouped/child-SKU maps in the persisted progress transient and run grouped processing + soft-delete only on the final batch using the full set; OR run them in a dedicated final cron step after all pages; OR (minimum) document in the settings UI that grouped products and soft-delete are unsupported while batching is enabled, and skip them without corrupting data.
**Acceptance:** with batching enabled, grouped products sync correctly and soft-delete either runs on the complete set or is clearly documented as disabled.
**Update (v0.8.2, see L2):** the grouped *drop* bug is fixed — buffered groupeds are no longer discarded at the batch boundary. STILL OPEN: grouped children on later, not-yet-synced pages won't resolve mid-batch, and soft-delete is still skipped on resumed batches (`empty( $progress )` guard). Persist `$source_keys` + child-SKU map in the progress transient and run grouped/soft-delete on the final batch, OR document as unsupported while batching.

### B4. MINOR — cleanups (PARTIALLY DONE)
- **DONE:** No lock during resume → FIXED in v0.8.2: `run_resume_batch()` sets sync lock around `run_sync_inner()`, bails if manual sync active.
- **REMAINING:** Remove dead `foreach_product()` (L636) — no longer called; `run_sync_inner()` has its own loop.
- **REMAINING:** Progress UI shows `strona X/?` — `render_admin_page()` hard-codes `esc_html('?')` though `total_pages` is in the progress transient. Display the real value.
- **REMAINING:** Progress bar never reaches 100% — total is `total_pages * per_page`, overcounting the last partial page. Use the real count on the final page.
- **DONE:** Unused vars in `run_resume_batch()` cleaned up during Codex round 2 fixes.

## v0.8.2 BATCH LOOP FIXES (Codex Round 2 findings)

### B5. CRITICAL — infinite resume loop on empty page completion
**Fixed in v0.8.2:** Empty-page `$count === 0` no longer saves progress (no work done). `run_resume_batch()` completion detection enhanced: if `products_processed` unchanged after resume AND we expected more pages, clear stale progress as completed.

### B6. MAJOR — soft-delete never runs with batching
**Related to B3:** When batch limit hits, `$progress` exists → soft-delete skipped forever (only cleared when all pages done and `run_sync()` clears it). With multi-batch syncs, if the catalog changes between batches or API errors mid-run, soft-delete may never execute. **Document in settings.**

### B7. MAJOR — no atomic sync lock
**Codex finding:** `get_transient()` / `set_transient()` is not atomic. Race window exists where two WP-Cron invocations can both pass the lock check and run concurrently. WordPress cron doesn't parallelize by default, but under heavy load with multiple servers, this could happen. **Fix: use `add_option()` + transients for compare-and-set behavior.**

---

## MAJOR (remaining)

### M2b. Full Action Scheduler backgrounding
**Where:** sync orchestration.
**Note:** partially superseded by the v0.8.0 WP-Cron batching, but Action Scheduler is more robust (retries, admin visibility, no reliance on site traffic). Consider whether to migrate the resume loop onto `as_enqueue_async_action` per-page batches (fetch page N, upsert, enqueue N+1; final action runs soft-delete). Only pursue after B1–B3 are fixed, since it overlaps that code.

---

## MINOR / HARDENING (remaining)

### N1. Enforce HTTPS for source URL
Basic auth credentials travel in a header; plain HTTP leaks them. In `sanitize_options()` and `is_configured()`, reject/flag non-`https://` source URLs (allow `http://` only when host is localhost/RFC1918 — lab use case). Show admin notice.

### N2. State-changing actions via GET
`handle_manual_run` uses GET links (nonce-protected). Convert both run/dry buttons to small `<form method="post">` with `admin-post.php` + nonce field; keep `mode` as hidden input. Update `check_admin_referer` accordingly.

### N3. Variation attribute term-name vs slug mismatch
`build_variation_attributes()` falls back to `sanitize_title( option )` when the term lookup by *name* fails — Polish diacritics can transliterate differently than WP did when creating the term. Fix: `ensure_term()` first (create if missing), then use the term's actual `slug`. Reuse `ensure_term()` from the parent-attribute path.

### N4. Signature matching robustness
`signature()` compares built attrs vs `WC_Product_Variation::get_attributes()`. Normalize both sides: lowercase keys and values, `wc_sanitize_taxonomy_name()` on keys, before `ksort`+hash. Add a comment with an example.

### N5. Type-change orphan cleanup
`ensure_product_type()` switching variable→simple leaves orphaned variations. After a type switch away from `variable`, delete existing children (`$product->get_children()` on the OLD object) with logging.

### N6. `fetch_source_attributes()` failure should set `fetch_had_error`
A failed attributes fetch currently only logs a warning; variable products then silently drop all global attributes (map lookup returns null → `continue`). Either set `fetch_had_error = true` (skips soft-delete) AND skip the variable-product attribute rebuild that run, or abort the run entirely.

### N7. Image sideload dedup
`set_product_images()` re-downloads identical URLs across products. Optional: store source URL in attachment meta (`_wps_source_image_url`) and look it up before sideloading; reuse attachment ID.

### N9. Uninstall cleanup
Add `register_uninstall_hook` (or `uninstall.php`): delete option `wc_product_sync_options`, clear cron hooks (`CRON_HOOK`, `RESUME_HOOK`), delete progress/lock transients. Do NOT delete product meta/tag by default (data loss); mention manual cleanup in README.

### N10. i18n polish
Add `/* translators: ... */` comments for all `printf`-style translatable strings (several are missing). If distributed privately, add `load_plugin_textdomain` on `init`.

### N12. readme.txt
Create `readme.txt` (plugin header format) documenting: install on TARGET, credentials via constants, dry-run-first workflow, soft-delete semantics (`_wps_synced`, `_wps_soft_deleted_at`, tag `wps-usuniete`), batch-limit/resume behavior, permanent-deletion warning, known limitations (flat categories, no cross-sells/upsells/meta, images only on create, grouped/soft-delete vs batching per B3).

---

## Suggested execution order for the agent
1. **B1** (one-line critical fix) → 2. **B2** → 3. **B4** cleanups → 4. **B3** (needs design decision) → 5. N6 → 6. N1 → 7. N2 → 8. N3 → 9. N5 → 10. N4/N7/N9/N10/N12 opportunistically → 11. M2b last (largest, overlaps B-work).

## Verification checklist after fixes
- [ ] Brace balance / `php -l` clean.
- [ ] Multi-batch sync (catalog > `2 × batch_limit`): all pages processed, progress not cleared early (B1).
- [ ] Non-aligned `batch_limit` vs `per_page`: no products skipped (B2).
- [ ] Dry run on fresh site: zero DB writes (no terms, no meta, no posts).
- [ ] Manual product with foreign SKU survives sync with soft-delete enabled.
- [ ] Kill source mid-run (simulate WP_Error): no soft-deletes, no variation deletions.
- [ ] Second consecutive run: created=0 for unchanged source (idempotency), including grouped without SKU.
- [ ] Settings save with constants defined does not erase DB values.
- [ ] Two simultaneous runs: second aborts on lock (incl. manual during pending resume, B4).
