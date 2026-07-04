# wc-product-sync — Fix TODO (for AI agent)

Current version: **0.8.2** (auto-batching v0.8.1 + batch loop fixes)
Target file: `wc-product-sync.php` (single-file WordPress plugin).

General rules for the agent:
- Preserve existing code style: tabs, WordPress PHP coding standards, Polish log/UI strings.
- Bump plugin `Version` header after applying fixes.
- After edits, verify: `php -l wc-product-sync.php` if PHP available; otherwise check brace balance and that each modified method is defined exactly once.
- Do not change the public behavior contract except where a task says so.

**Already done — do not redo** (see git history for details): C1–C4, M1, M2, M3, M4, M5, N8, N11.

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
