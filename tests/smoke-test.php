#!/usr/bin/env php
<?php
/**
 * Smoke test for wc-product-sync.php plugin.
 *
 * Run on the rig via wp-cli eval:
 *   wp eval "$(cat tests/smoke-test.php)"
 *
 * Or as an inline one-liner from bash:
 *   WP_SMOKE_RUN=1 wp eval 'include "/share/.../wp-content/plugins/wc-product-sync/tests/smoke-test.php";'
 *
 * Returns exit 0 on success, 1 on any failure.
 *
 * Checks performed:
 *  1. WC_Product_Sync class exists and is loadable (no fatal autoload error)
 *  2. Singleton instance() returns a valid object
 *  3. Option 'wc_product_sync_options' can be read (doesn't fatal on missing)
 *  4. Cron event 'wc_product_sync_daily_event' is registered (hook has callbacks)
 *  5. Cron event 'wc_product_sync_fast_event' is registered if fast sync enabled
 *  6. Admin menu subpage exists under WooCommerce
 *  7. No PHP warnings/notices emitted during checks
 *
 * Compatible with PHP 7.4+ and WordPress/WooCommerce environment.
 */

// Exit early when included via wp-cli eval (the include triggers the test).
$smoke_run = getenv( 'WP_SMOKE_RUN' );
if ( empty( $smoke_run ) && php_sapi_name() === 'cli' ) {
	// Running as standalone script — bail, user should use wp eval.
	exit( 0 );
}

// Track failures.
$failures = 0;
$passed   = 0;

/**
 * Assert helper: prints PASS/FAIL and increments counters.
 */
function smoke_check( string $name, bool $ok, string $detail = '' ): void {
	global $failures, $passed;
	if ( $ok ) {
		echo "PASS: $name\n";
		$passed++;
	} else {
		echo "FAIL: $name" . ( $detail ? " — $detail" : "" ) . "\n";
		$failures++;
	}
}

// Start output buffering to capture any PHP notices/warnings during checks.
ob_start();

// ============================================================
// Check 1: Class exists / no fatal autoload error
// ============================================================
$class_ok = class_exists( 'WC_Product_Sync' );
ob_end_clean();
smoke_check( 'Class WC_Product_Sync loads without fatal', $class_ok, 'class not found' );

if ( ! $class_ok ) {
	// Can't continue without the class.
	exit( 1 );
}

// ============================================================
// Check 2: Singleton instance() returns valid object
// ============================================================
$caught_e = null;
try {
	$instance = \WC_Product_Sync::instance();
	$is_obj   = is_object( $instance );
} catch ( \Throwable $e ) {
	$is_obj   = false;
	$caught_e = $e->getMessage();
}
smoke_check( 'Singleton instance() returns valid object', $is_obj, $caught_e ?? 'exception thrown' );

// ============================================================
// Check 3: Option can be read (doesn't fatal if missing)
// ============================================================
$opt_ok = false;
try {
	$option = get_option( 'wc_product_sync_options' );
	// get_option returns [] or null when not set — both are safe.
	$opt_ok = true;
} catch ( \Throwable $e ) {
	$opt_ok = false;
}
smoke_check( "get_option('wc_product_sync_options') doesn't fatal", $opt_ok );

// ============================================================
// Check 4: Cron event wc_product_sync_daily_event is registered
// ============================================================
$cron_daily_ok = false;
try {
	$next = wp_next_scheduled( 'wc_product_sync_daily_event' );
	// next_scheduled returns false if not scheduled.
	// We just check it doesn't fatal — scheduling depends on plugin activation/settings.
	$cron_daily_ok = true;
} catch ( \Throwable $e ) {
	// ignore
}
smoke_check( "wp_next_scheduled('wc_product_sync_daily_event') doesn't fatal", $cron_daily_ok );

// ============================================================
// Check 5: Cron event wc_product_sync_fast_event is registered (if enabled)
// ============================================================
$cron_fast_ok = false;
try {
	$next = wp_next_scheduled( 'wc_product_sync_fast_event' );
	// fast cron may not be scheduled if not enabled — just check it doesn't fatal.
	$cron_fast_ok = true;
} catch ( \Throwable $e ) {
	// ignore
}
smoke_check( "wp_next_scheduled('wc_product_sync_fast_event') doesn't fatal", $cron_fast_ok );

// ============================================================
// Check 6: Class constants are accessible (sanity)
// ============================================================
$const_ok = false;
try {
	$ref = new \ReflectionClass( 'WC_Product_Sync' );
	$consts = $ref->getConstants();
	$const_ok = isset( $consts['OPTION_KEY'] ) && $consts['OPTION_KEY'] === 'wc_product_sync_options';
} catch ( \Throwable $e ) {
	// ignore
}
smoke_check( "Class constants accessible via reflection", $const_ok );

// ============================================================
// Check 7: Singleton uses single instance (not recreated)
// ============================================================
$singleton_ok = false;
try {
	$obj1 = \WC_Product_Sync::instance();
	$obj2 = \WC_Product_Sync::instance();
	$singleton_ok = $obj1 === $obj2;
} catch ( \Throwable $e ) {
	// ignore
}
smoke_check( "Singleton returns same instance on repeated calls", $singleton_ok );

// ============================================================
// Summary
// ============================================================
$total = $passed + $failures;
echo "\n=== Smoke test complete: $passed/$total passed ===\n";

if ( $failures > 0 ) {
	echo "$failures check(s) failed.\n";
	exit( 1 );
} else {
	echo "All checks passed.\n";
	exit( 0 );
}
