# WC Product Sync — TODO (v0.9.12 → v0.9.13)

## ✅ Completed

### Security (round 1, commit 7576887)
- N1: SQL injection in force-full query — `$wpdb->prepare()` for `META_SYNCED` constant (#38)

### Fixes (v0.9.12, commit 2e7ab38)
- N3: Diacritics fallback for `_wps_source_id` matching when SKU is not set (#44)
- N5: Orphaned variation handling — detect and remove type-changed variations that no longer match any source child (#110)
- N9: Missing `WC_Product_Sync::uninstall()` hook + wp_clear_scheduled_hook cleanup on uninstall (#129)
- N10: Textdomain loader (`load_plugin_textdomain`) added at `init` action (#92)

---

## ✅ Completed (v0.9.13 — Round 3)

### Performance & correctness (Round 3)
- P1: Removed redundant `WC_Product_Variable::sync()` from UPDATE path → variable product updates now only call sync_variations() instead of also rebuilding term/price caches
- P2: Added source keys cap (20k max) to accumulate_source_keys() — prevents OOM on large catalogs
- P4: Added error logging for `@set_time_limit()` failures in all 3 locations (was silently swallowing errors)

---

## v0.9.14+ — Remaining open items

### Nice-to-have / optional:
- N7: Global image dedup by source URL (cross-product) — reduce duplicate attachments when multiple products share images
- readme.txt: Plugin repo metadata for WordPress.org submission
- PHPCS-WP linting pass: Code style compliance with WooCommerce/WordPress standards
