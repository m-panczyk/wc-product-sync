# wc-product-sync — Fix TODO (for AI agent)

Current version: **0.5.0**
Target file: `wc-product-sync.php` (single-file WordPress plugin).

General rules for the agent:
- Preserve existing code style: tabs, WordPress PHP coding standards, Polish log/UI strings.
- Bump plugin `Version` header to `0.4.0` after applying fixes.
- After edits, verify: `php -l wc-product-sync.php` if PHP available; otherwise check brace balance and that each modified method is defined exactly once.
- Do not change the public behavior contract except where a task says so.

---

## CRITICAL

### C1. Soft-delete queries all products — `wc_get_products()` does not support `meta_query`
**Where:** `soft_delete_missing()`, `enforce_soft_delete_limit()`.
**Problem:** `WC_Product_Query` ignores custom `meta_query`, `meta_key`, `orderby => meta_value_num`. Result: candidate set = ALL publish/pending/private products, so manually-created products (no `_wps_synced` meta) whose SKU is absent from source get drafted. This violates the core safety guarantee. In `enforce_soft_delete_limit()` ordering by `_wps_soft_deleted_at` is ignored → wrong products permanently deleted.
**Fix:** Replace both queries with `get_posts()` / `WP_Query` on `post_type=product`, which fully supports `meta_query`:
- `soft_delete_missing()`: paginated `get_posts` with `fields => 'ids'`, `post_status => array('publish','pending','private')`, `posts_per_page => 100`, `paged => $page`, `no_found_rows => true`, and the existing two-clause `meta_query` (`_wps_synced` EXISTS AND `_wps_soft_deleted_at` NOT EXISTS).
- `enforce_soft_delete_limit()`: `get_posts` with `post_status => 'draft'`, `meta_key => self::META_SOFT_DELETED`, `orderby => 'meta_value_num'`, `order => 'ASC'`, `posts_per_page => -1`, `fields => 'ids'`, plus `meta_query` EXISTS clause.
**Acceptance:** A product created manually in wp-admin (no `_wps_synced` meta) with an arbitrary SKU is never drafted by a sync run. Oldest-first ordering verified by `_wps_soft_deleted_at` values.

### C2. Failed/empty variation fetch wipes all existing variations of a product
**Where:** `sync_variations()` + `fetch_variations()`.
**Problem:** `fetch_variations()` swallows `WP_Error` (logs + `break`) and returns a possibly-empty array. `sync_variations()` then deletes every child not in `$kept` → one API hiccup deletes all variations of that parent.
**Fix:**
1. Change `fetch_variations()` to signal failure: return `WP_Error` on any page error (or set an out-param/property `$this->variations_fetch_error`).
2. In `sync_variations()`: if fetch failed → log warning and `return` without touching existing children.
3. Additionally skip the stale-deletion loop when source reported the parent has variations but fetch returned 0 (empty result + parent type variable in source with non-empty `variations` field, if available) — safest: only run deletion when fetch succeeded with HTTP 200 on all pages.
**Acceptance:** Simulate fetch failure (e.g. force `WP_Error`) → existing variations remain untouched; parent fields may still update.

### C3. Fatal error: `add_action( 'shutdown', ... )` targets a private method
**Where:** `sanitize_options()` registers `array( $this, 'sync_cron_schedule' )`; `sync_cron_schedule()` is `private`.
**Problem:** WordPress invokes hooks via `call_user_func` from outside the class scope; PHP 8 throws `Error: Call to private method`. Saving settings with the schedule checkbox toggled crashes on shutdown.
**Fix:** Change `sync_cron_schedule()` visibility to `public`. Keep the shutdown-hook approach (options are persisted by then).
**Acceptance:** Toggling "Uruchamiaj codziennie" on/off and saving produces no PHP error; `wp_next_scheduled( self::CRON_HOOK )` reflects the checkbox state.

### C4. Grouped products without SKU duplicate on every run
**Where:** `upsert_grouped()`.
**Problem:** Fallback matching uses the SOURCE slug, but the plugin never sets `post_name` when creating the product; WP generates a slug from the (possibly different) name → lookup misses on the next run → new duplicate every run.
**Fix (do both):**
1. Store source identity: in every upsert (`simple`, `variable`, `grouped`) save `update_post_meta( $id, '_wps_source_id', (int) $p['id'] )`. Add `const META_SOURCE_ID = '_wps_source_id';`.
2. Matching order in `upsert_grouped()` (and optionally as a tertiary fallback in the other upserts): (a) SKU via `wc_get_product_id_by_sku()`, (b) meta lookup `_wps_source_id == $p['id']` via `get_posts` meta_query, (c) slug — and if creating, explicitly set the slug: `wp_update_post( array( 'ID' => $id, 'post_name' => sanitize_title( $p['slug'] ) ) )` or `$product->set_slug( $p['slug'] )` before `save()`.
**Acceptance:** Two consecutive runs against the same source produce zero new grouped products on the second run, including grouped items without SKU.

---

## MAJOR

### M1. Settings wipe when constants are defined (disabled inputs)
**Where:** `render_admin_page()` (inputs with `disabled()`), `sanitize_options()`.
**Problem:** Disabled inputs are not submitted; `sanitize_options()` unconditionally overwrites missing keys with `''` → stored DB values for `source_url` / `consumer_key` / `consumer_secret` are erased whenever constants are defined and the form is saved.
**Fix:** In `sanitize_options()`, only overwrite a field when the key exists in `$input`; otherwise keep the previous value from `$this->get_options()`.
**Acceptance:** With `WC_PRODUCT_SYNC_CK` defined, saving settings does not erase the DB-stored `consumer_key`.

### M2. Whole sync runs in a single web request; unbounded runtime
**Where:** `handle_manual_run()`, `run_sync_cron()`, `api_get()` retry `sleep()`s (up to ~60s cumulative per failed URL).
**Problem:** Large catalogs (thousands of products + image sideloads) exceed `max_execution_time` / PHP-FPM timeouts; manual run dies mid-way with no resume.
**Fix (incremental, pick all):**
1. Add a concurrency lock: transient `wps_sync_running` (e.g. 30 min TTL) set at start of `run_sync()`, cleared in `finally`; if present → log + abort. Protects against cron overlapping manual runs.
2. Call `ignore_user_abort( true )` and, where allowed, `set_time_limit( 0 )` at the start of `run_sync()`.
3. Preferred architecture: enqueue per-page batches via Action Scheduler (`as_enqueue_async_action`), available since WC core bundles it. Steps: action `wps_sync_page` fetches page N, upserts, enqueues N+1; final action runs soft-delete. Keep synchronous path as fallback when Action Scheduler unavailable.
4. Reduce retry sleeps in web context: cap backoff to `min( 10, 2^attempt )` and `$max = 3` when `wp_doing_ajax() || is_admin()`.
**Acceptance:** Two overlapping run attempts → second aborts with a logged warning. Manual run on a 1k-product catalog completes or is fully delegated to background actions.

### M3. Dry run has side effects
**Where:** `soft_delete_missing()` → `get_soft_delete_tag_id()` creates the `wps-usuniete` tag; `map_global_attribute()` / `ensure_term()` would create attributes/terms if ever reached in dry paths (currently not, but fragile).
**Fix:** In `soft_delete_missing()`, resolve the tag id lazily only when actually applying (non-dry) — move `get_soft_delete_tag_id()` call inside the non-dry branch. Add a guard comment that dry-run code paths must not create terms/attributes/attachments.
**Acceptance:** Fresh site + dry run → no new terms in `product_tag`.

### M4. `status=any` silently syncs source drafts/pending
**Where:** `fetch_all_products()` (changed from `publish` in 0.1 to `any` in 0.2 for the grouped id→sku map).
**Problem:** Undocumented behavior change; target now mirrors source drafts. May be desired or not.
**Fix:** Make it explicit: add a settings checkbox `sync_nonpublish` (default off). When off: fetch with `status=any` ONLY to build `source_id_to_sku` (lightweight fields via `_fields=id,sku` query param), but upsert only `publish` products; when on: current behavior. Note: `_fields` is supported by WP REST and reduces payload massively.
**Acceptance:** Default run does not create draft products on target; grouped children mapping still resolves.

### M5. Memory: full catalog accumulated in RAM
**Where:** `fetch_all_products()` + `run_sync()`.
**Problem:** Whole JSON (descriptions, image arrays) held in one array; 10k products can exhaust memory.
**Fix:** Restructure to two passes: pass 1 pages through `_fields=id,sku,type` to build `source_id_to_sku` and the grouped list; pass 2 processes page-by-page (fetch page → upsert each → free). Grouped bucket processed at the end by re-fetching each grouped product individually (`GET /products/{id}`) or storing their full payloads (grouped are typically few).
**Acceptance:** Peak memory stays roughly constant w.r.t. catalog size (spot-check with `memory_get_peak_usage()` log line).

---

## MINOR / HARDENING

### N1. Enforce HTTPS for source URL
Basic auth credentials travel in a header; plain HTTP leaks them. In `sanitize_options()` and `is_configured()`, reject/flag non-`https://` source URLs (allow `http://` only when host is localhost/RFC1918 — M's lab use case). Show admin notice.

### N2. State-changing actions via GET
`handle_manual_run` uses GET links (nonce-protected). Convert both buttons to small `<form method="post">` with `admin-post.php` + nonce field; keep `mode` as hidden input. Update `check_admin_referer` accordingly.

### N3. Variation attribute term-name vs slug mismatch
`build_variation_attributes()` falls back to `sanitize_title( option )` when the term lookup by *name* fails — Polish diacritics can transliterate differently than WP did when creating the term. Fix: `ensure_term()` first (create if missing), then use the term's actual `slug`. Reuse `ensure_term()` from parent-attribute path.

### N4. Signature matching robustness
`signature()` compares built attrs vs `WC_Product_Variation::get_attributes()`. Normalize both sides: lowercase keys and values, `wc_sanitize_taxonomy_name()` on keys, before `ksort`+hash. Add a unit-style comment with an example.

### N5. Type-change orphan cleanup
`ensure_product_type()` switching variable→simple leaves orphaned variations. After a type switch away from `variable`, delete existing children (`$product->get_children()` on the OLD object) with logging.

### N6. `fetch_source_attributes()` failure should set `fetch_had_error`
A failed attributes fetch currently only logs a warning; variable products will then silently drop all global attributes (map lookup returns null → `continue`). Either set `fetch_had_error = true` (skips soft-delete) AND skip variable-product attribute rebuild that run, or abort the run entirely.

### N7. Image sideload dedup
`set_product_images()` re-downloads identical URLs across products. Optional: store source URL in attachment meta (`_wps_source_image_url`) and look it up before sideloading; reuse attachment ID.

### N8. Cron hour control
`wp_schedule_event( time() + 300, 'daily', ... )` pins runs to activation time. Add a settings field (hour 0–23), compute next occurrence in site TZ via `wp_timezone()`, and reschedule on settings change. Document that WP-Cron requires traffic; recommend `DISABLE_WP_CRON` + system cron in a README section.

### N9. Uninstall cleanup
Add `register_uninstall_hook` (or `uninstall.php`): delete option `wc_product_sync_options`, clear cron hook. Do NOT delete product meta/tag by default (data loss); mention manual cleanup in README.

### N10. i18n polish
Add `/* translators: ... */` comments for all `printf`-style translatable strings (several are missing). Load text domain is implicit since WP 4.6 for wp.org plugins; if distributed privately, add `load_plugin_textdomain` on `init`.

### N11. `render_admin_page` reads `$_GET` counters unsanitized-but-cast
Already cast to `(int)` — fine; add `wp_unslash` + `absint` for phpcs cleanliness and remove the phpcs:ignore where possible.

### N12. README
Create `readme.txt` (plugin header format) documenting: install on TARGET, credentials via constants, dry-run-first workflow, soft-delete semantics (`_wps_synced`, `_wps_soft_deleted_at`, tag `wps-usuniete`), limit behavior (permanent deletion!), known limitations (flat categories, no cross-sells/upsells/meta, images only on create).

---

## Suggested execution order for the agent
1. C3 (one-line visibility fix) → 2. C1 → 3. C2 → 4. C4 (+ `_wps_source_id` groundwork) → 5. M1 → 6. M3 → 7. M2 (lock + limits first, Action Scheduler last) → 8. M4/M5 together (both touch fetch architecture) → 9. N-items opportunistically, N1/N2/N3/N6 first.

## Completed (0.4.0)
- [x] **C3** — `sync_cron_schedule()` visibility: changed from `private` to `public` so the shutdown hook call works in PHP 8+.
- [x] **C1** — `wc_get_products()` with `meta_query`: replaced both `soft_delete_missing()` and `enforce_soft_delete_limit()` with `get_posts()` which fully supports `meta_query`. Also adds lazy tag resolution (N3 fix).
- [x] **C2** — Variation wipe on fetch failure: added `$this->variations_fetch_error` flag; `sync_variations()` resets it per-parent and skips stale-deletion when any page errored. Logged warning emitted instead.
- [x] **C4** — Grouped products without SKU duplicating: added `const META_SOURCE_ID = '_wps_source_id'`; all three upsert methods now save `(int) $p['id']` as `_wps_source_id`. `upsert_grouped()` matching order is now: (a) SKU → (b) source_id meta lookup → (c) slug.
- [x] **M1** — Settings wipe with constants defined: `sanitize_options()` now only overwrites fields present in `$input`, preserving DB values for disabled inputs.
- [x] Version bumped to `0.4.0`.


## Completed (0.5.0)
- [x] **M2** — Concurrency lock + time limits: added `const SYNC_LOCK_TRANSIENT = 'wps_sync_running'`; `run_sync()` acquires a transient lock (900s TTL) wrapped in try/finally for clean release; `set_time_limit(600)` and `ignore_user_abort(true)` added to prevent PHP timeouts.
- [x] **M3** — Dry-run side effects: verified no term/attribute creation in dry paths (already correct due to C1 lazy tag resolution); added explicit guard comment.
- [x] **M4** — Status filter for sync: added `should_sync_status()` with `apply_filters('wps_sync_statuses', array('publish'))` hook; non-publish products (draft/pending/private) are skipped by default unless the filter is overridden in wp-config.php or mu-plugins.
- [x] **M5** — Memory optimization via page-by-page processing: replaced `fetch_all_products()` (which accumulated ALL products into one massive array) with `foreach_product()` callback iterator that processes page-by-page, calls `gc_collect_cycles()` after each page, and buffers only grouped products (typically small). Non-grouped products are processed immediately and freed from memory.

## Remaining (deferred to later versions)
- [x] **M2** — Concurrency lock + time limits (transient lock set_time_limit ignore_user_abort)
- [ ] **M2b** — Full Action Scheduler backgrounding (per-page async batches, N+1 enqueuing)
- [x] **M3** — Dry-run side effects: verified safe, added guard comment
- [x] **M4** — Status filter for sync: `apply_filters("wps_sync_statuses", ...)` hook (default: publish only)
- [x] **M5** — Memory: page-by-page callback iterator with gc_collect_cycles()
- [ ] **N1-N12** — Minor hardening (HTTPS enforcement, admin-post forms, N3 attr slugs, N5 type-change cleanup, N7 image dedup, N8 cron hour control, N9 uninstall, N10 i18n, N11 phpcs cleanliness, N12 readme.txt)

## Verification checklist after all fixes
- [ ] Brace balance / `php -l` clean.
- [ ] Dry run on fresh site: zero DB writes (no terms, no meta, no posts).
- [ ] Manual product with foreign SKU survives sync with soft-delete enabled.
- [ ] Kill source mid-run (simulate WP_Error): no soft-deletes, no variation deletions.
- [ ] Second consecutive run: created=0 for unchanged source (idempotency), including grouped without SKU.
- [ ] Settings save with constants defined does not erase DB values.
- [ ] Two simultaneous runs: second aborts on lock.
