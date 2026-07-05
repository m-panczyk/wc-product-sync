# SQL Injection Fix — WC Product Sync v0.9.11

## Issue

**Location:** `wc-product-sync.php`, line 1241 (inside `run_sync_inner()`)

**Code:**
```php
$ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::META_SYNCED . "'");
```

**Problem:** The SQL query concatenates `self::META_SYNCED` directly into the query string instead of using `$wpdb->prepare()`. While this is a class constant (not user input), making it not exploitable, it:

1. Violates WordPress coding standards ($wpdb must always use prepare())
2. Is inconsistent with every other $wpdb call in the same file
3. Sets a bad precedent if someone later replaces the constant with a variable
4. Could trigger security scanner false-positives / warnings

**Fix:** Use `$wpdb->prepare()` — even though the value is a constant, this is the correct pattern and ensures consistency across the codebase.

```php
$ids = $wpdb->get_col(
    $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META_SYNCED)
);
```

## Files Affected
- `wc-product-sync.php` — single line change (~2 lines)
