<?php
/**
 * Plugin Name:       WC Product Sync (SKU)
 * Description:        Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce (źródło) do TEGO sklepu (cel). Dopasowanie po SKU (lub nazwie gdy brak SKU). Obsługa: simple, variable, grouped. Zapisy lokalnie przez WooCommerce CRUD.
 * Version:           0.9.27-rc5
 * Author:            Michał Pańczyk
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Requires Plugins:  woocommerce
 * Text Domain:       wc-product-sync
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * WC Product Sync (SKU) is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by the Free
 * Software Foundation, either version 2 of the License, or (at your option) any
 * later version. Distributed WITHOUT ANY WARRANTY. See the LICENSE file or
 * <https://www.gnu.org/licenses/gpl-2.0.html> for details.
 *
 * Instalacja na sklepie DOCELOWYM. Ze źródła czytamy przez REST, na cel zapisujemy lokalnie.
 *
 * Klucze API źródła – zalecane w wp-config.php (mają pierwszeństwo nad bazą):
 *   define( 'WC_PRODUCT_SYNC_SOURCE_URL', 'https://zrodlo.pl' );
 *   define( 'WC_PRODUCT_SYNC_CK', 'ck_xxx' );
 *   define( 'WC_PRODUCT_SYNC_CS', 'cs_xxx' );
 *
 * Aktualizacje z własnego serwera (opcjonalne): wskaż URL do metadanych JSON, aby aktualizacje
 * pojawiały się w panelu WordPress (Wtyczki → Aktualizuj). Bez tej stałej updater jest wyłączony.
 *   define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://twoj-serwer.pl/wc-product-sync/update.json' );
 * Prywatne repozytorium (Forgejo/Gitea) — dodaj token dostępu; jest dołączany jako nagłówek
 * `Authorization: token …` wyłącznie do żądań na host z UPDATE_URL (do update.json i pobrania ZIP-a):
 *   define( 'WC_PRODUCT_SYNC_UPDATE_TOKEN', 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Product_Sync {

	const OPTION_KEY      = 'wc_product_sync_options';
	const CRON_HOOK        = 'wc_product_sync_daily_event';
	const RESUME_HOOK      = 'wps_sync_resume'; // batch continuation via cron
	const ADOPT_HOOK       = 'wps_adopt_event'; // background reconcile (adopt existing products)
	const ADOPT_STATE      = 'wps_adopt_state';  // transient: batched adopt progress + accumulated plan
	const ADOPT_RESULT     = 'wps_adopt_result'; // option: last adopt run summary for the admin notice
	const FAST_CRON_HOOK   = 'wc_product_sync_fast_event'; // frequent field-refresh (price/stock)
	const FAST_SCHEDULE    = 'wps_fast_interval';          // dynamic cron schedule (interval = configured minutes)
	const LOG_SOURCE       = 'wc-product-sync';
	const NONCE_ACTION     = 'wc_product_sync_run';
	const SYNC_LOCK_TRANSIENT  = 'wps_sync_running';
	const SYNC_PROGRESS_TRANSIENT = 'wps_sync_progress';
	const SYNC_LAST_RESULT     = 'wps_last_sync_result'; // Cumulative result of the last run (for UI feedback)
	const SYNC_KEYS_TRANSIENT  = 'wps_sync_source_keys'; // Accumulated source SKUs/names across batches (for soft-delete)
	const SYNC_LAST_REPORT     = 'wps_last_sync_report'; // Per-item report of the last run (what/how/why)
	const REPORT_BUCKET_CAP    = 500;                    // Max items stored per bucket (counts stay exact)
	const UPDATE_TRANSIENT     = 'wps_update_info';       // cached self-hosted update metadata (JSON)
	/** Public release channel, used when WC_PRODUCT_SYNC_UPDATE_URL is not defined. A moving
	 *  metadata pointer — the ZIP it names is an immutable versioned release. Define the constant
	 *  to point elsewhere, or to '' to switch the updater off entirely. */
	const DEFAULT_UPDATE_URL   = 'https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/latest/update.json';
	/** Prerelease (release-candidate) channel, selected by the 'update_channel' = 'rc' setting. */
	const RC_UPDATE_URL        = 'https://git.panczyk.cc/mpanczyk/wc-product-sync/releases/download/latest-beta/update.json';

	// Soft-delete
	const META_SYNCED       = '_wps_synced';
	const META_SOFT_DELETED = '_wps_soft_deleted_at';
	const TAG_SLUG          = 'wps-usuniete';
	const TAG_NAME          = 'Usunięte (sync)';
	const META_SOURCE_ID    = '_wps_source_id';
	/** Timestamp of the run that CREATED this product (= that run's started_at). Lets
	 *  "undo last sync" trash exactly the products a given run created, and nothing it
	 *  merely updated or that pre-dated the plugin. */
	const META_CREATED_RUN  = '_wps_created_run';
	const META_IMAGE_MAP    = '_wps_image_map'; // JSON: source image key => local attachment id (incremental sync)

	/** @var WC_Product_Sync|null */
	private static $instance = null;

	/** Cache: źródłowe atrybuty globalne  id => ['name','slug'] */
	private $source_attributes = array();
	/** Cache mapowania atrybutu: bare_slug => ['taxonomy','attribute_id'] */
	private $attr_map_cache = array();
	/** Mapa źródłowe product_id => sku (dla grouped) */
	private $source_id_to_sku = array();
	/** Ostatnie total_pages pobrane z nagłówka X-WP-TotalPages */
	private $last_total_pages = 0;
	/** Ostatnia dokładna liczba produktów z nagłówka X-WP-Total */
	private $last_total_items = 0;
	/** Czy pobieranie źródła napotkało błąd (blokada soft-delete) */
	private $fetch_had_error = false;
	/** Czy pobieranie wariacji napotkało błąd (blokada usunięć) */
	private $variations_fetch_error = false;
	/** Czy pobieranie definicji atrybutów globalnych się nie powiodło (blokada, by nie zniszczyć wariantów) */
	/** Czy w tym przebiegu próbowano już fallbacku na /products/attributes (maks. raz) */
	private $attributes_fetch_tried = false;
	/** Bufor raportu bieżącego batcha: bucket => lista wpisów (scalany do opcji po batchu) */
	private $run_report = array();
	/** Jak dopasowano ostatni produkt: 'SKU' | 'source_id' | 'nazwa' | '' (nowy) */
	private $last_match_method = '';
	/** Powód pominięcia ostatniego produktu (dla raportu) */
	private $last_skip_reason = '';
	/** Czy przy ostatnim produkcie nie udało się pobrać obrazu (→ liczone jako błąd) */
	private $last_image_failed = false;
	/** Czy przy ostatnim produkcie poległa którakolwiek wariacja (→ liczone jako błąd) */
	private $last_variation_failed = false;
	/** Ile wariacji faktycznie zsynchronizowano dla ostatniego produktu zmiennego (0 = puste/awaria) */
	private $last_variation_count = 0;
	/** Lokalne ID ostatnio zapisanego produktu (create lub update) — do znakowania _wps_created_run */
	private $last_saved_id = 0;
	/** started_at bieżącego przebiegu; ten sam we wszystkich batchach (z SYNC_LAST_RESULT) */
	private $run_started_at = 0;
	/** Czy trwa AKTUALIZACJA (true) czy TWORZENIE (false) — pola bramkujemy tylko przy aktualizacji */
	private $writing_update = false;
	/** Szybka synchronizacja: tylko wybrane pola (fast_sync_fields), tylko aktualizacja istniejących
	 *  (bez tworzenia i usuwania). Ustawiane per-przebieg (cron/resume). */
	private $fast_mode = false;
	/** Dry-run (simulation) mode for the CURRENT batch. Carried across resume batches in the
	 *  progress transient (like $fast_mode), so a backgrounded dry run stays a simulation on every
	 *  batch instead of writing on a resume. */
	private $dry_mode = false;
	/** "Total sync" (mirror-the-source) run: for THIS run only, force force_full + hard delete,
	 *  regardless of the saved settings. Carried across batches in the progress transient. */
	private $total_sync = false;
	/** Ostatnie nagłówki odpowiedzi REST API (X-WP-TotalPages) */
	private $last_api_headers = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wc_product_sync_run', array( $this, 'handle_manual_run' ) );
		add_action( 'admin_post_wc_product_sync_cancel', array( $this, 'handle_cancel_sync' ) );
		add_action( 'admin_post_wc_product_sync_step', array( $this, 'handle_step_sync' ) );
		add_action( 'admin_post_wc_product_sync_undo', array( $this, 'handle_undo' ) );
		add_action( 'admin_post_wc_product_sync_adopt', array( $this, 'handle_adopt' ) );
		add_action( 'admin_post_wc_product_sync_total', array( $this, 'handle_total_sync' ) );
		add_action( self::ADOPT_HOOK, array( $this, 'run_adopt_event' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_sync_cron' ) );
		add_action( self::RESUME_HOOK, array( $this, 'run_resume_batch' ) );
		add_action( self::FAST_CRON_HOOK, array( $this, 'run_fast_sync_cron' ) );
		add_filter( 'cron_schedules', array( $this, 'register_fast_schedule' ) );
		add_action( 'admin_notices', array( $this, 'maybe_wc_missing_notice' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		// Allow image sideloading from the configured source host even if it's a private/LAN
		// IP: WP's SSRF guard (wp_http_validate_url) otherwise blocks download_url() for
		// RFC1918 hosts, so products would sync without images against a LAN/staging source.
		add_filter( 'http_request_host_is_external', array( $this, 'allow_source_host' ), 10, 2 );
		// Self-hosted updates (opt-in via WC_PRODUCT_SYNC_UPDATE_URL): surface a new release in the
		// WordPress Plugins screen + "View details" modal, so updating is one click — no re-upload.
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'update_details' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_update_cache' ), 10, 2 );
		// Authenticate the ZIP download (done by WP core, not our code) for private update servers.
		add_filter( 'http_request_args', array( $this, 'authorize_update_request' ), 10, 2 );
	}

	/** Load the plugin's translations from /languages. */
	public static function load_textdomain() {
		load_plugin_textdomain( 'wc-product-sync', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/** Treat the admin-configured source host as external so its images can be sideloaded. */
	public function allow_source_host( $is_external, $host ) {
		$src = wp_parse_url( $this->cfg_source_url(), PHP_URL_HOST );
		if ( $src && strtolower( (string) $host ) === strtolower( (string) $src ) ) {
			return true;
		}
		return $is_external;
	}

	/* =====================================================================
	 *  Aktywacja / dezaktywacja / cron
	 * ================================================================== */

	public static function activate() {
		$opts = get_option( self::OPTION_KEY, array() );
		if ( ! empty( $opts['schedule_enabled'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_cron_at_time();
		}
		// Fast field-refresh cron uses a dynamic schedule (registered on the instance), so route
		// scheduling through the instance reconciler rather than duplicating interval logic here.
		if ( ! empty( $opts['fast_sync_enabled'] ) ) {
			self::instance()->reconcile_fast_cron();
		}
	}

	private static function schedule_cron_at_time() {
		$opts   = get_option( self::OPTION_KEY, array() );
		$hour   = isset( $opts['cron_hour'] ) ? (int) $opts['cron_hour'] : 3;
		$minute = isset( $opts['cron_minute'] ) ? (int) $opts['cron_minute'] : 0;
		wp_schedule_event( self::compute_next_run_ts( $hour, $minute ), 'daily', self::CRON_HOOK );
	}

	/** UTC timestamp of the next $hour:$minute in the SITE's timezone (not UTC). WordPress runs PHP in
	 *  UTC, so mktime()/strtotime() would read the chosen hour as UTC and land the run at the wrong
	 *  wall-clock time on any non-UTC site — the "shows 03:00 for 01:00" bug. Anchoring to wp_timezone()
	 *  makes the configured hour mean what the UI label ("Domena czasu WordPress") promises. */
	private static function compute_next_run_ts( $hour, $minute ) {
		$tz     = wp_timezone();
		$now    = new DateTimeImmutable( 'now', $tz );
		$target = $now->setTime( (int) $hour, (int) $minute, 0 );
		if ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::FAST_CRON_HOOK );
	}

	public function sync_cron_schedule() {
		$enabled = ! empty( $this->get_options()['schedule_enabled'] );
		$next    = wp_next_scheduled( self::CRON_HOOK );
		if ( ! $enabled ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
		} elseif ( ! $next ) {
			$this->schedule_next_run();
		} elseif ( ! $this->cron_time_matches( $next ) ) {
			// The daily event exists but at a different time than the saved hour/minute — a changed
			// setting. Move it (clear + reschedule); without this, editing the time never took effect
			// on an already-scheduled event, which is the "settings do nothing" half of the bug.
			wp_clear_scheduled_hook( self::CRON_HOOK );
			$this->schedule_next_run();
			$this->log( 'info', 'Zmieniono godzinę harmonogramu — przeplanowano codzienną synchronizację.' );
		}
		$this->reconcile_fast_cron();
	}

	/** True when the scheduled event's LOCAL wall-clock time equals the configured hour:minute. */
	private function cron_time_matches( $ts ) {
		$opts   = $this->get_options();
		$hour   = isset( $opts['cron_hour'] ) ? (int) $opts['cron_hour'] : 3;
		$minute = isset( $opts['cron_minute'] ) ? (int) $opts['cron_minute'] : 0;
		$local  = ( new DateTimeImmutable( '@' . (int) $ts ) )->setTimezone( wp_timezone() );
		return (int) $local->format( 'G' ) === $hour && (int) $local->format( 'i' ) === $minute;
	}

	/** Register the dynamic cron schedule used by the fast field-refresh sync. Its interval always
	 *  reflects the currently-configured minutes (floored to a safe minimum so we never hammer the
	 *  source). WP-Cron re-reads this on each reschedule. */
	public function register_fast_schedule( $schedules ) {
		$min = $this->fast_interval_minutes();
		$schedules[ self::FAST_SCHEDULE ] = array(
			'interval' => $min * MINUTE_IN_SECONDS,
			'display'  => sprintf( __( 'Co %d min (WC Product Sync)', 'wc-product-sync' ), $min ),
		);
		return $schedules;
	}

	/** Configured fast-sync interval in minutes, floored to 15 (anti-hammer) and capped at 1 day. */
	private function fast_interval_minutes() {
		return max( 15, min( 1440, (int) $this->get_options()['fast_sync_interval_min'] ) );
	}

	/** (Re)schedule or clear the fast field-refresh event to match current settings. Reschedules
	 *  when the interval changed so a saved settings update takes effect. Safe to call repeatedly. */
	public function reconcile_fast_cron() {
		$enabled = ! empty( $this->get_options()['fast_sync_enabled'] );
		$next    = wp_next_scheduled( self::FAST_CRON_HOOK );
		if ( ! $enabled ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::FAST_CRON_HOOK );
			}
			return;
		}
		$want = $this->fast_interval_minutes() * MINUTE_IN_SECONDS;
		if ( $next ) {
			$ev = function_exists( 'wp_get_scheduled_event' ) ? wp_get_scheduled_event( self::FAST_CRON_HOOK ) : null;
			if ( $ev && (int) $ev->interval === $want ) {
				return; // already scheduled at the right cadence
			}
			wp_clear_scheduled_hook( self::FAST_CRON_HOOK ); // interval changed → reschedule
		}
		wp_schedule_event( time() + $want, self::FAST_SCHEDULE, self::FAST_CRON_HOOK );
		$this->log( 'info', sprintf( 'Zaplanowano szybką synchronizację co %d min.', $want / MINUTE_IN_SECONDS ) );
	}

	private function schedule_next_run() {
		$hour   = (int) $this->get_options()['cron_hour'];
		$minute = (int) $this->get_options()['cron_minute'];
		$ts     = self::compute_next_run_ts( $hour, $minute );
		wp_schedule_event( $ts, 'daily', self::CRON_HOOK );
		$this->log( 'info', sprintf( 'Zaplanowano sync: %s (czas lokalny).', wp_date( 'Y-m-d H:i', $ts ) ) );
	}

	/* =====================================================================
	 *  Progress tracking (batching + resume)
	 * ================================================================== */

private function save_sync_progress( $current_page, $products_processed, $total_products, $page_offset = 0 ) {
		// Preserve the original start time across page/batch saves so the admin UI's
		// elapsed-time and ETA reflect the whole sync, not just since the last page.
		$existing   = get_transient( self::SYNC_PROGRESS_TRANSIENT );
		$started_at = ( is_array( $existing ) && ! empty( $existing['started_at'] ) ) ? (int) $existing['started_at'] : time();
		set_transient( self::SYNC_PROGRESS_TRANSIENT, array(
			'current_page'     => $current_page,
			'page_offset'      => (int) $page_offset,       // Items already done on the IN-PROGRESS page (current_page+1)
			'products_processed' => $products_processed,
			'total_products'   => $total_products,
			'total_pages'      => $this->last_total_pages, // Store for resume calculations
			'total_items'      => $this->last_total_items, // Exact count for accurate progress %
			'per_page'         => $this->cfg_per_page(),    // Store per_page for resume
			'started_at'       => $started_at,
			'updated_at'       => time(),                   // Heartbeat — used to detect a stalled/no-cron sync
			'fast'             => $this->fast_mode ? 1 : 0, // Resume batches must keep fast (field-refresh) mode
			'dry'              => $this->dry_mode ? 1 : 0,  // Resume batches must stay a simulation
			'total'            => $this->total_sync ? 1 : 0, // Resume batches must keep mirror (force_full+hard)
		), 3600 ); // Persist for 1 hour (enough for all batches to finish)
	}

	private function get_sync_progress() {
		return get_transient( self::SYNC_PROGRESS_TRANSIENT );
	}

	/** Seed a "starting" progress transient so the admin UI shows activity immediately,
	 *  before the first page (and its X-WP-Total header) has been fetched. current_page=0
	 *  marks this as the first batch of a fresh run (see $is_first_batch in run_sync_inner). */
	private function seed_sync_progress() {
		set_transient( self::SYNC_PROGRESS_TRANSIENT, array(
			'current_page'       => 0,
			'page_offset'        => 0,
			'products_processed' => 0,
			'total_products'     => 0,
			'total_pages'        => 0,
			'total_items'        => 0,
			'per_page'           => $this->cfg_per_page(),
			'started_at'         => time(),
			'updated_at'         => time(),
			'dry'                => $this->dry_mode ? 1 : 0,
			'total'              => $this->total_sync ? 1 : 0,
		), 3600 );
	}

	/** Reset the cumulative run-result tracker at the start of a manual/scheduled run.
	 *  $dry marks the run as a simulation so the completion notice can say so. */
	private function reset_run_result( $dry = false ) {
		update_option( self::SYNC_LAST_RESULT, array(
			'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0,
			'running' => true, 'started_at' => time(), 'finished_at' => 0,
			'dry'     => $dry ? 1 : 0,
		), false );
	}

	/** Add a batch's stats to the cumulative run result; mark finished when complete.
	 *  Called after each batch so the completion notice reflects the WHOLE sync, not
	 *  just the final batch (each cron/resume invocation has its own local $stats). */
	private function accumulate_run_result( array $stats, $complete ) {
		$r = get_option( self::SYNC_LAST_RESULT, array() );
		if ( ! is_array( $r ) ) {
			$r = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'started_at' => time() );
		}
		foreach ( array( 'created', 'updated', 'skipped', 'errors' ) as $k ) {
			$r[ $k ] = (int) ( $r[ $k ] ?? 0 ) + (int) ( $stats[ $k ] ?? 0 );
		}
		$r['running'] = ! $complete;
		if ( $complete ) {
			$r['finished_at'] = time();
		}
		update_option( self::SYNC_LAST_RESULT, $r, false );
	}

	/* ---- Per-run report (what was updated/how, what was skipped/why) ---- */

	/** Record one item into the in-memory report buffer for the current batch. */
	private function report_add( $bucket, array $entry ) {
		$this->run_report[ $bucket ][] = $entry;
	}

	/** Reset the stored report at the start of a fresh run. */
	private function reset_run_report() {
		$this->run_report = array();
		delete_option( self::SYNC_LAST_REPORT );
	}

	/** Merge this batch's buffered report into the persisted option (capped per bucket). */
	private function append_run_report( $dry_run = false ) {
		$r = get_option( self::SYNC_LAST_REPORT, array() );
		if ( ! is_array( $r ) ) {
			$r = array();
		}
		$r['dry'] = (bool) $dry_run;
		if ( empty( $this->run_report ) ) {
			update_option( self::SYNC_LAST_REPORT, $r, false );
			return;
		}
		foreach ( $this->run_report as $bucket => $entries ) {
			if ( ! isset( $r[ $bucket ] ) ) {
				$r[ $bucket ] = array();
			}
			foreach ( $entries as $e ) {
				if ( count( $r[ $bucket ] ) < self::REPORT_BUCKET_CAP ) {
					$r[ $bucket ][] = $e;
				} else {
					$r['_truncated'] = true; // counts remain exact via SYNC_LAST_RESULT
				}
			}
		}
		update_option( self::SYNC_LAST_REPORT, $r, false );
		$this->run_report = array();
	}

	private function clear_sync_progress() {
		delete_transient( self::SYNC_PROGRESS_TRANSIENT );
	}

	private function cancel_sync() {
		delete_transient( self::SYNC_LOCK_TRANSIENT );
		$this->clear_sync_progress();
		delete_transient( self::SYNC_KEYS_TRANSIENT );
		wp_clear_scheduled_hook( self::RESUME_HOOK );
		// Un-stick the result tracker so the UI doesn't keep showing "running".
		$r = get_option( self::SYNC_LAST_RESULT, array() );
		if ( is_array( $r ) && ! empty( $r['running'] ) ) {
			$r['running']     = false;
			$r['finished_at'] = time();
			update_option( self::SYNC_LAST_RESULT, $r, false );
		}
		$this->log( 'info', 'Synchronizacja anulowana przez użytkownika.' );
	}

	/** Accumulate the source keys (SKUs/names) seen this batch so soft-delete on the FINAL
	 *  batch can compare against the WHOLE catalog, not just one batch. Also records the total
	 *  count and whether any fetch failed (which makes the collected set unsafe for deletion). */
	private function accumulate_source_keys( array $keys, $count, $had_error ) {
		$c = get_transient( self::SYNC_KEYS_TRANSIENT );
		if ( ! is_array( $c ) ) {
			$c = array( 'keys' => array(), 'count' => 0, 'had_error' => false );
		}
		foreach ( $keys as $k ) {
			if ( '' !== $k ) {
				$c['keys'][ $k ] = true; // assoc = dedup across batches (pages may be re-fetched on resume)
			}
		}
		// Cap key count to prevent transient overflow on large catalogs.
		// When capped, mark as unsafe for deletion — truncated key set is incomplete
		// and products dropped from it would be falsely deleted as "missing from source".
		$cap = self::REPORT_BUCKET_CAP * 40; // 20000 keys (~400KB max serialized)
		if ( count( $c['keys'] ) > $cap ) {
			$before = count( $c['keys'] );
			$c['keys'] = array_flip( array_slice( array_keys( $c['keys'] ), 0, $cap ) );
			$this->log( 'warning', sprintf( 'Source keys cap reached (%d→%d). Key set incomplete — soft/hard delete SKIPPED for safety.', $before, $cap ) );
			$c['had_error'] = true; // mark collection unsafe for deletion decisions
		}
		$c['count']    += (int) $count;
		$c['had_error'] = $c['had_error'] || (bool) $had_error;
		set_transient( self::SYNC_KEYS_TRANSIENT, $c, 3600 );
	}

	/** Resume batch: called by WP-Cron to continue sync from saved progress. */
	public function run_resume_batch() {
		$progress = $this->get_sync_progress();
		if ( ! $progress ) {
			$this->log( 'info', 'No progress found for resume — nothing to do.' );
			return;
		}
		// Restore fast (field-refresh) mode for this batch so a multi-batch fast sync stays
		// update-only/restricted-fields across resumes instead of falling back to a full sync.
		$this->fast_mode  = ! empty( $progress['fast'] );
		$this->dry_mode   = ! empty( $progress['dry'] );  // keep a backgrounded dry run a simulation on resume
		$this->total_sync = ! empty( $progress['total'] ); // keep mirror (force_full+hard) across resume

		// Check if a manual sync is already running (Codex #6 fix: sync lock)
		$lock = get_transient( self::SYNC_LOCK_TRANSIENT );
		if ( false !== $lock ) {
			$this->log( 'info', sprintf( 'Sync lock active (%ds ago) — deferring resume.', time() - $lock ) );
			return;
		}

		// Resume runs via WP-Cron, often in a web (wp-cron.php) request bound by the default 30s
		// max_execution_time — image sideloading/resizing (Imagick) easily exceeds it and the batch
		// dies mid-way. Raise the limit like the initial run does (run_sync).
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) && ! @set_time_limit( 900 ) ) {
			$this->log( 'warning', 'set_time_limit(900) failed — sync may hit PHP timeout.' );
		}

		$total = $progress['total_products'];
		$processed = $progress['products_processed'];
		$current_page = $progress['current_page'];

		// Recompute true total from stored total_pages × per_page (more reliable than running count)
		if ( isset( $progress['total_pages'], $progress['per_page'] ) && $progress['total_pages'] > 0 ) {
			$total = $progress['total_pages'] * $progress['per_page'];
		}

		$this->log( 'info', sprintf( 'Resuming batch: %d/%d products, strona %d...', $processed, $total, $current_page + 1 ) );
		$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 );

		// Set sync lock for this resume batch (Codex #6 fix)
		set_transient( self::SYNC_LOCK_TRANSIENT, time(), 1800 );
		try {
			$this->run_sync_inner( $this->dry_mode, $stats, $progress );
		} finally {
			delete_transient( self::SYNC_LOCK_TRANSIENT );
		}

		// Completion check. run_sync_inner() clears the progress transient when the sync is
		// truly done, so an empty transient means complete. If it kept the transient we treat
		// the batch as complete only when there was NO fetch error AND either we are past the
		// last page or this resume made no forward progress (a stall — e.g. exhausted pages).
		// The fetch-error guard is essential: without it a transient network error would be
		// mistaken for a stall and abort the whole sync.
		$new_progress  = $this->get_sync_progress();
		$sync_complete = empty( $new_progress );

		if ( ! $sync_complete && ! $this->fetch_had_error ) {
			$made_progress  = $new_progress['products_processed'] > $processed;
			$past_last_page = ! empty( $new_progress['total_pages'] )
				&& $new_progress['current_page'] >= $new_progress['total_pages'];
			if ( $past_last_page || ! $made_progress ) {
				$this->clear_sync_progress();
				$sync_complete = true;
			}
		}

		// Fold this batch into the cumulative result + per-item report shown on the settings page.
		$this->append_run_report( $this->dry_mode );
		$this->accumulate_run_result( $stats, $sync_complete );

		if ( $sync_complete ) {
			$this->log( 'info', 'Resumed batch completed successfully.' );
		} else {
			// Schedule next resume using same 30s interval as initial batch (Codex #7 fix: consistency)
			$pages_total = $new_progress['total_pages'] ?: 0;
			$per_page    = $new_progress['per_page'] ?: $this->cfg_per_page();
			$remaining   = max( 0, ( $pages_total - $new_progress['current_page'] ) * $per_page );

			if ( wp_next_scheduled( self::RESUME_HOOK ) ) {
				wp_clear_scheduled_hook( self::RESUME_HOOK );
			}
			wp_schedule_single_event( time() + 30, self::RESUME_HOOK ); // Consistent 30s interval (Codex #7 fix)
			$this->log( 'info', sprintf( 'Batch finished. %d products remaining — scheduled next batch.', $remaining ) );
		}
	}

	private function format_duration( $seconds ) {
		if ( $seconds < 60 ) return floor( $seconds ) . 's';
		$minutes = floor( $seconds / 60 );
		if ( $minutes < 60 ) return $minutes . 'm' . ($seconds % 60 > 0 ? ' ' . ($seconds % 60) . 's' : '');
		$hours = floor( $minutes / 60 );
		$min = $minutes % 60;
		return $hours . 'h ' . $min . 'm';
	}

	/* =====================================================================
	 *  Konfiguracja
	 * ================================================================== */

	private function get_options() {
$defaults = array(
		'source_url'          => '',
		'consumer_key'        => '',
		'consumer_secret'     => '',
		'per_page'            => 100,
		'sync_batch_limit'    => 200, // 0 = no limit (legacy), recommended: 200-500 products
		'max_batch_seconds'   => 20,  // time budget per batch — stop+resume before any PHP timeout (0 = off)
		'schedule_enabled'    => 0,
		'deletion_mode'       => 'none',  // 'none' = nie ruszaj; 'soft' = szkic+tag; 'hard' = trwałe usunięcie
		'soft_delete_limit'   => 50,
		'hard_delete_max'     => 50,       // bezpiecznik: maks. trwałych usunięć na przebieg (0 = bez limitu)
		'cron_hour'           => 3,
		'cron_minute'         => 0,
		'force_full_sync'     => 0,
		// Price modifier applied to every synced price relative to the source (regular + sale, simple
		// + variations). new = source * (1 + pct/100) + fixed, then rounded. Defaults are a no-op
		// (0% + 0, 2-decimal rounding = the source price unchanged). Only applied when 'price' sync is on.
		'price_markup_pct'    => 0,          // percentage, may be negative (e.g. -10 = 10% cheaper)
		'price_markup_fixed'  => 0,          // fixed amount added after the percentage, may be negative
		'price_rounding'      => 'standard', // standard (2 dp) | integer | charm (.99) | none
		// Update channel: 'stable' (latest) or 'rc' (latest-beta). Overridden by the
		// WC_PRODUCT_SYNC_UPDATE_URL constant when it is defined.
		'update_channel'      => 'stable',
		// What to sync (defaults preserve legacy behaviour: everything, publish only).
		'sync_types'          => array( 'simple', 'variable', 'grouped' ),
		'sync_statuses'       => array( 'publish' ),
		'sync_fields'         => array( 'description', 'price', 'stock', 'images', 'categories', 'attributes', 'dimensions' ),
		// Fast field-refresh (frequent, update-only): which volatile fields to refresh and how often.
		'fast_sync_enabled'      => 0,
		'fast_sync_interval_min' => 60,                      // minutes (floored to 15 at use)
		'fast_sync_fields'       => array( 'price', 'stock' ),
		// Admin progress page: full-page auto-reload while a sync runs. OFF by default — it makes the
		// UI unstable (scroll jumps/flicker). Manual "Odśwież postęp" button is always available.
		'admin_auto_refresh'     => 0,
	);
		$raw = get_option( self::OPTION_KEY, array() );
		// Migration: pre-0.9.5 used a separate soft_delete_enabled checkbox. Map it onto
		// deletion_mode so upgrades keep behaving the same.
		if ( is_array( $raw ) && ! isset( $raw['deletion_mode'] ) && ! empty( $raw['soft_delete_enabled'] ) ) {
			$raw['deletion_mode'] = 'soft';
		}
		$opts = wp_parse_args( $raw, $defaults );
		// Ensure the array-typed options are always arrays (older saved rows may lack them).
		foreach ( array( 'sync_types', 'sync_statuses', 'sync_fields', 'fast_sync_fields' ) as $ak ) {
			if ( ! is_array( $opts[ $ak ] ) ) {
				$opts[ $ak ] = $defaults[ $ak ];
			}
		}
		return $opts;
	}

	private function cfg_source_url() {
		if ( defined( 'WC_PRODUCT_SYNC_SOURCE_URL' ) && WC_PRODUCT_SYNC_SOURCE_URL ) {
			return untrailingslashit( WC_PRODUCT_SYNC_SOURCE_URL );
		}
		return untrailingslashit( $this->get_options()['source_url'] );
	}

	private function cfg_ck() {
		if ( defined( 'WC_PRODUCT_SYNC_CK' ) && WC_PRODUCT_SYNC_CK ) {
			return WC_PRODUCT_SYNC_CK;
		}
		return $this->get_options()['consumer_key'];
	}

	private function cfg_cs() {
		if ( defined( 'WC_PRODUCT_SYNC_CS' ) && WC_PRODUCT_SYNC_CS ) {
			return WC_PRODUCT_SYNC_CS;
		}
		return $this->get_options()['consumer_secret'];
	}

	private function cfg_per_page() {
		return max( 1, min( 100, (int) $this->get_options()['per_page'] ) );
	}

	private function is_configured() {
		return $this->cfg_source_url() && $this->cfg_ck() && $this->cfg_cs();
	}

	/** Is this product type enabled for sync? (types to sync — settings) */
	private function type_enabled( $type ) {
		return in_array( $type, (array) $this->get_options()['sync_types'], true );
	}

	/**
	 * Apply the configured price modifier to one raw source price and return it as a string ready for
	 * set_regular_price()/set_sale_price(). An empty source price stays empty (no phantom price). The
	 * defaults (0% + 0, standard rounding) leave the price unchanged, so this is a no-op until the
	 * operator configures a markup. Result is floored at 0 — a modifier can't make a price negative.
	 */
	private function modify_price( $raw ) {
		if ( '' === $raw || null === $raw ) {
			return '';
		}
		$opts  = $this->get_options();
		$pct   = (float) ( $opts['price_markup_pct'] ?? 0 );
		$fixed = (float) ( $opts['price_markup_fixed'] ?? 0 );
		$mode  = $opts['price_rounding'] ?? 'standard';
		// True no-op (the default): return the source price string verbatim so an unmodified sync stays
		// byte-identical to the source, e.g. "13.60" is not reformatted to "13.6".
		if ( 0.0 === $pct && 0.0 === $fixed && 'standard' === $mode ) {
			return (string) $raw;
		}
		$val   = (float) $raw;
		if ( 0.0 !== $pct ) {
			$val *= ( 1 + $pct / 100 );
		}
		$val += $fixed;
		if ( $val < 0 ) {
			$val = 0;
		}
		switch ( $mode ) {
			case 'integer':
				return (string) (int) round( $val );
			case 'charm': // .99 ending (e.g. 23.40 -> 23.99), a common retail price point. The 1e-6
				// nudge keeps a value that float-math left at e.g. 109.9999999 from flooring to 109.
				return (string) ( floor( $val + 1e-6 ) + 0.99 );
			case 'none':
				return wc_format_decimal( $val ); // trim to WC's price precision without extra rounding
			case 'standard':
			default:
				return (string) round( $val, 2 );
		}
	}

	/** Should this data field be written? On CREATE everything is imported; on UPDATE a field
	 *  disabled in settings is skipped, so local edits to it are preserved (#2). */
	private function field_on( $field ) {
		if ( ! $this->writing_update ) {
			return true; // creating a new product → import all fields
		}
		// Fast field-refresh runs write only the configured fast set (e.g. price/stock); the daily
		// full sync uses the main sync_fields set.
		$fields = $this->fast_mode
			? $this->get_options()['fast_sync_fields']
			: $this->get_options()['sync_fields'];
		return in_array( $field, (array) $fields, true );
	}

	/** Should products missing from the source be handled at all? ('none' = leave them). */
	private function deletion_enabled() {
		if ( $this->fast_mode ) {
			return false; // fast field-refresh never creates or deletes products
		}
		if ( $this->total_sync ) {
			return true; // mirror always deletes what the source no longer has
		}
		return 'none' !== ( $this->get_options()['deletion_mode'] ?? 'none' );
	}

	/** Effective deletion mode for THIS run: hard when mirroring, else the saved setting. */
	private function effective_deletion_mode() {
		if ( $this->total_sync ) {
			return 'hard';
		}
		return ( 'hard' === ( $this->get_options()['deletion_mode'] ?? 'none' ) ) ? 'hard' : 'soft';
	}

	/** N1: HTTP source on a public host leaks the Basic-auth API keys in cleartext.
	 *  Returns true when the URL is http:// AND the host is not local/private. */
	private function source_url_is_insecure( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || 0 === stripos( $url, 'https://' ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		if ( in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '::1' ), true )
			|| preg_match( '/\.(local|test|localhost)$/i', $host )
			|| preg_match( '/^(10\.|127\.|192\.168\.|169\.254\.|172\.(1[6-9]|2\d|3[01])\.)/', $host ) ) {
			return false; // local/private lab host — http is acceptable
		}
		return true;
	}

	/* =====================================================================
	 *  Ustawienia (Settings API)
	 * ================================================================== */

	public function register_settings() {
		register_setting( 'wc_product_sync_group', self::OPTION_KEY, array( $this, 'sanitize_options' ) );
	}

	public function sanitize_options( $input ) {
		$out = $this->get_options();
		if ( isset( $input['source_url'] ) ) {
			$out['source_url']       = esc_url_raw( trim( $input['source_url'] ) );
			if ( $this->source_url_is_insecure( $out['source_url'] ) ) {
				add_settings_error( self::OPTION_KEY, 'wps_insecure_url',
					__( 'Uwaga: URL źródła używa HTTP — klucze API są przesyłane jawnie. Użyj HTTPS.', 'wc-product-sync' ),
					'warning' );
			}
		}
		if ( isset( $input['consumer_key'] ) ) {
			$out['consumer_key']     = sanitize_text_field( trim( $input['consumer_key'] ) );
		}
		if ( isset( $input['consumer_secret'] ) ) {
			$out['consumer_secret']  = sanitize_text_field( trim( $input['consumer_secret'] ) );
		}
	if ( isset( $input['per_page'] ) ) {
			$out['per_page']         = max( 1, min( 100, (int) $input['per_page'] ) );
		}
		if ( isset( $input['sync_batch_limit'] ) ) {
			$out['sync_batch_limit'] = max( 0, (int) $input['sync_batch_limit'] ); // 0 = unlimited (legacy)
		}
		if ( isset( $input['max_batch_seconds'] ) ) {
			$out['max_batch_seconds'] = max( 0, min( 600, (int) $input['max_batch_seconds'] ) );
		}
		// Checkboxes: an unchecked box is absent from $input, so these must be set
		// unconditionally (no isset guard) — otherwise they can never be turned OFF.
		$out['schedule_enabled']    = empty( $input['schedule_enabled'] ) ? 0 : 1;
		$out['force_full_sync']     = empty( $input['force_full_sync'] ) ? 0 : 1;
		if ( isset( $input['soft_delete_limit'] ) ) {
			$out['soft_delete_limit']   = max( 0, (int) $input['soft_delete_limit'] );
		}
		$dm = isset( $input['deletion_mode'] ) ? $input['deletion_mode'] : 'none';
		$out['deletion_mode']   = in_array( $dm, array( 'none', 'soft', 'hard' ), true ) ? $dm : 'none';
		// Price modifier (may be negative for a markdown).
		if ( isset( $input['price_markup_pct'] ) ) {
			$out['price_markup_pct']   = (float) str_replace( ',', '.', (string) $input['price_markup_pct'] );
		}
		if ( isset( $input['price_markup_fixed'] ) ) {
			$out['price_markup_fixed'] = (float) str_replace( ',', '.', (string) $input['price_markup_fixed'] );
		}
		$pr = isset( $input['price_rounding'] ) ? $input['price_rounding'] : 'standard';
		$out['price_rounding']  = in_array( $pr, array( 'standard', 'integer', 'charm', 'none' ), true ) ? $pr : 'standard';
		$uc = isset( $input['update_channel'] ) ? $input['update_channel'] : 'stable';
		$out['update_channel']  = in_array( $uc, array( 'stable', 'rc' ), true ) ? $uc : 'stable';
		// Switching channel must take effect now, not after the 12h metadata cache — drop it so the
		// next admin load re-checks against the newly selected channel.
		$prev_channel = $this->get_options()['update_channel'] ?? 'stable';
		if ( $out['update_channel'] !== $prev_channel ) {
			delete_transient( self::UPDATE_TRANSIENT );
			delete_site_transient( 'update_plugins' );
		}
		if ( isset( $input['hard_delete_max'] ) ) {
			$out['hard_delete_max'] = max( 0, (int) $input['hard_delete_max'] );
		}
		if ( isset( $input['cron_hour'] ) ) {
			$out['cron_hour']         = max( 0, min( 23, (int) $input['cron_hour'] ) );
		}
		if ( isset( $input['cron_minute'] ) ) {
			$out['cron_minute']       = max( 0, min( 59, (int) $input['cron_minute'] ) );
		}
		// "What to sync" multi-selects (checkbox groups): keep only known values, in order.
		$out['sync_types']    = $this->sanitize_choice_set( $input['sync_types'] ?? array(), array( 'simple', 'variable', 'grouped' ) );
		$out['sync_statuses'] = $this->sanitize_choice_set( $input['sync_statuses'] ?? array(), array( 'publish', 'draft', 'pending', 'private' ) );
		$out['sync_fields']   = $this->sanitize_choice_set( $input['sync_fields'] ?? array(), array( 'description', 'price', 'stock', 'images', 'categories', 'attributes', 'dimensions' ) );
		// Fast field-refresh settings.
		$out['fast_sync_enabled']      = empty( $input['fast_sync_enabled'] ) ? 0 : 1;
		if ( isset( $input['fast_sync_interval_min'] ) ) {
			$out['fast_sync_interval_min'] = max( 15, min( 1440, (int) $input['fast_sync_interval_min'] ) );
		}
		$out['fast_sync_fields'] = $this->sanitize_choice_set( $input['fast_sync_fields'] ?? array(), array( 'price', 'stock' ) );
		$out['admin_auto_refresh'] = empty( $input['admin_auto_refresh'] ) ? 0 : 1;
		add_action( 'shutdown', array( $this, 'sync_cron_schedule' ) );
		return $out;
	}

	/** Keep only allowed values from a submitted checkbox group (preserves canonical order). */
	private function sanitize_choice_set( $input, array $allowed ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( $allowed as $v ) {
			if ( in_array( $v, $input, true ) ) {
				$out[] = $v;
			}
		}
		return $out;
	}

	/* =====================================================================
	 *  Admin UI
	 * ================================================================== */

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Synchronizacja produktów', 'wc-product-sync' ),
			__( 'Synchronizacja produktów', 'wc-product-sync' ),
			'manage_woocommerce',
			'wc-product-sync',
			array( $this, 'render_admin_page' )
		);
	}

	public function maybe_wc_missing_notice() {
		if ( ! $this->wc_active() && current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WC Product Sync wymaga aktywnego WooCommerce.', 'wc-product-sync' ) . '</p></div>';
		}
	}

	private function wc_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_product' );
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$opts    = $this->get_options();
		$next    = wp_next_scheduled( self::CRON_HOOK );
		$fast_next = wp_next_scheduled( self::FAST_CRON_HOOK );
		$run_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_run&mode=run' ), self::NONCE_ACTION );
		$dry_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_run&mode=dry' ), self::NONCE_ACTION );
		$undo_url        = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_undo' ), self::NONCE_ACTION . '_undo' );
		$adopt_prev_url  = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_adopt&mode=preview' ), self::NONCE_ACTION . '_adopt' );
		$adopt_apply_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_adopt&mode=apply' ), self::NONCE_ACTION . '_adopt' );
		$undo_count      = $this->last_run_undo_count();
		$progress = $this->get_sync_progress();

		// Render progress section if sync is in progress
		if ( $progress ) {
			$start_time = $progress['started_at'];
			$elapsed    = time() - $start_time;
			$processed  = $progress['products_processed'];
			$total       = max( $progress['total_products'], $processed );
			$page        = $progress['current_page'];
			$per_page    = (int) $this->cfg_per_page();
			$total_pages = isset( $progress['total_pages'] ) ? (int) $progress['total_pages'] : 0;
			$percent     = $total > 0 ? round( $processed / max( $total, 1 ) * 100, 1 ) : 0;

			// ETA: simple linear extrapolation. Requires $processed > 0 (the "starting" state
			// has 0 processed, which would divide by a zero rate → fatal on PHP 8).
			if ( $elapsed > 5 && $processed > 0 ) {
				$remaining   = max( 0, $total - $processed );
				$eta_seconds = (int) ( $remaining * $elapsed / $processed );
			} else {
				$eta_seconds = '?';
			}

			// "Starting" state: seeded transient before the first page has been fetched.
			$is_starting = ( 0 === (int) $page && 0 === (int) $processed );

			// Stall/no-cron detection: a batch holds the lock while it runs, so if no batch is
			// active AND there's been no heartbeat for a while, WP-Cron probably isn't firing.
			$active        = ( false !== get_transient( self::SYNC_LOCK_TRANSIENT ) );
			$last_update   = isset( $progress['updated_at'] ) ? (int) $progress['updated_at'] : $start_time;
			$maybe_stalled = ! $active && ( time() - $last_update ) > 150;

			echo '<div class="notice notice-info" style="padding:15px; margin-bottom:20px;">';
			echo '<h3 style="margin:0 0 10px;">' . esc_html__( 'Synchronizacja w toku', 'wc-product-sync' ) . '</h3>';
			if ( $is_starting ) {
				echo '<p><span class="spinner is-active" style="float:none; margin:0 6px 0 0;"></span>'
					. esc_html__( 'Uruchamianie — pobieranie danych ze źródła…', 'wc-product-sync' ) . '</p>';
			} else {
				echo '<div style="background:#e5e5e5; border-radius:4px; height:24px; margin-bottom:8px; overflow:hidden;">';
				echo '<div style="background:#2271b1; height:100%; width:' . esc_attr( $percent ) . '%; transition:width 0.3s;"></div>';
				echo '</div>';
				printf( '<p><strong>%d</strong> / <strong>%d</strong> produktów (%.1f%%) — strona %d/%s</p>',
					$processed, $total, $percent, $page, $total_pages > 0 ? (int) $total_pages : esc_html( '?' ) );
				echo '<p>Czas pracy: <strong>' . esc_html( $this->format_duration( $elapsed ) ) . '</strong>';
				if ( is_numeric( $eta_seconds ) ) {
					echo ', szacowany czas zakończenia: <strong>' . esc_html( $this->format_duration( $eta_seconds ) ) . '</strong>';
				}
				echo '</p>';
			}

			// Auto-batch info — no manual continue needed when WP-Cron works.
			echo '<p class="description" style="margin:8px 0 0;">';
			echo esc_html__( 'Synchronizacja jest automatycznie kontynuowana batch po batchu przez WP-Cron. Strona odświeża się sama; może być zamknięta.', 'wc-product-sync' );
			echo '</p>';

			// Recovery path when WP-Cron isn't firing (the sync would otherwise sit here forever).
			if ( $maybe_stalled ) {
				echo '<p style="color:#b32d2e; margin:10px 0 4px;"><strong>'
					. esc_html__( 'Uwaga: brak postępu od ponad 2 minut — WP-Cron może nie być uruchomiony, więc synchronizacja mogła się nie rozpocząć/zatrzymać.', 'wc-product-sync' )
					. '</strong></p>';
				printf( '<p><a href="%s" class="button button-primary">%s</a></p>',
					esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_step' ), self::NONCE_ACTION . '_step' ) ),
					esc_html__( 'Kontynuuj teraz ręcznie (bez WP-Cron)', 'wc-product-sync' ) );
			}

			printf( '<p><a href="%s" class="button button-link-danger" onclick="return confirm(\'Anulować synchronizację?\');">Anuluj</a>',
				wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_cancel' ), self::NONCE_ACTION . '_cancel' ) );
			printf( ' <a href="%s" class="button">%s</a>',
				esc_url( admin_url( 'admin.php?page=wc-product-sync' ) ),
				esc_html__( 'Odśwież postęp', 'wc-product-sync' ) );
			echo '</p>';
			// Progress auto-refresh is OFF by default: a full-page reload every few seconds makes the
			// admin UI unstable (scroll jumps, lost interaction, flicker). Opt in via the
			// "Auto-odświeżanie postępu" setting; otherwise use the manual "Odśwież postęp" button above.
			// The script is only emitted while the progress transient exists, so it stops once the sync
			// finishes.
			if ( ! empty( $this->get_options()['admin_auto_refresh'] ) ) {
				echo '<script>setTimeout(function(){ if(!document.hidden){ location.reload(); } }, 8000);</script>';
			}
			echo '</div>';
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Synchronizacja produktów WooCommerce', 'wc-product-sync' ); ?></h1>

			<?php
			if ( isset( $_GET['synced'] ) ) :
				$n_created = (int) ( $_GET['created'] ?? 0 );
				$n_updated = (int) ( $_GET['updated'] ?? 0 );
				$n_skipped = (int) ( $_GET['skipped'] ?? 0 );
				$n_errors  = (int) ( $_GET['errors'] ?? 0 );
				// A run that touched NOTHING is not a success, even with no counted error: it
				// means the source returned an empty catalog, which in practice means a broken
				// source URL or a status filter that matched nothing. Never paint that green.
				$nothing   = ( 0 === $n_created + $n_updated + $n_skipped );
				$css       = ( $n_errors > 0 || $nothing ) ? 'notice-error' : 'notice-success';
				?>
				<div class="notice <?php echo esc_attr( $css ); ?> is-dismissible"><p>
					<?php
					printf(
						esc_html__( 'Zakończono. Utworzono: %1$d, zaktualizowano: %2$d, pominięto: %3$d, błędy: %4$d. Szczegóły w logach WooCommerce.', 'wc-product-sync' ),
						$n_created,
						$n_updated,
						$n_skipped,
						$n_errors
					);
					if ( $n_errors > 0 || $nothing ) {
						echo '<br><strong>';
						esc_html_e( 'Ze źródła nie pobrano żadnych produktów.', 'wc-product-sync' );
						echo '</strong> ';
						esc_html_e( 'Najczęstsze przyczyny: błędne Consumer Key/Secret, klucz bez uprawnienia „Odczyt", albo źródło pod adresem http:// — WooCommerce przyjmuje klucze API tylko po HTTPS. Dokładny powód (np. HTTP 401) jest w logach: WooCommerce → Status → Logi, źródło „wc-product-sync".', 'wc-product-sync' );
					}
					?>
				</p></div>
			<?php elseif ( isset( $_GET['started'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p>
					<?php if ( isset( $_GET['dry'] ) ) : ?>
						<?php esc_html_e( 'Symulacja (dry run) uruchomiona w tle — nic nie jest zapisywane. Postęp i wynik pojawią się poniżej; użyj „Odśwież postęp".', 'wc-product-sync' ); ?>
					<?php else : ?>
						<?php esc_html_e( 'Synchronizacja uruchomiona w tle. Postęp pojawi się poniżej — użyj przycisku „Odśwież postęp", aby zaktualizować (auto-odświeżanie jest wyłączone).', 'wc-product-sync' ); ?>
					<?php endif; ?>
				</p></div>
			<?php elseif ( isset( $_GET['stepped'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p>
					<?php esc_html_e( 'Wykonano batch ręcznie. Jeśli pozostały produkty, kliknij ponownie „Kontynuuj teraz" lub napraw WP-Cron.', 'wc-product-sync' ); ?>
				</p></div>
			<?php elseif ( isset( $_GET['cancelled'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p>
					<?php esc_html_e( 'Synchronizacja anulowana. Postęp nie został zapisany — produkty przetwarzane w tym batchu mogą wymagać ponownego przetworzenia.', 'wc-product-sync' ); ?>
				</p></div>
			<?php elseif ( isset( $_GET['undone'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php printf( esc_html__( 'Cofnięto: %d produktów przeniesiono do kosza. Możesz je przywrócić w Produkty → Kosz.', 'wc-product-sync' ), (int) $_GET['undone'] ); ?>
				</p></div>
			<?php elseif ( isset( $_GET['adopt_started'] ) ) : ?>
				<div class="notice notice-info is-dismissible"><p>
					<?php echo 'apply' === $_GET['adopt_started']
						? esc_html__( 'Scalanie uruchomione w tle. Odśwież stronę za chwilę — wynik pojawi się poniżej.', 'wc-product-sync' )
						: esc_html__( 'Podgląd scalania uruchomiony w tle. Odśwież stronę za chwilę — plan pojawi się poniżej.', 'wc-product-sync' ); ?>
				</p></div>
			<?php elseif ( isset( $_GET['total_started'] ) ) : ?>
				<div class="notice notice-warning is-dismissible"><p>
					<?php esc_html_e( 'Total sync uruchomiony w tle: najpierw scalanie (dopasowanie po SKU i nazwie), potem synchronizacja lustrzana z TWARDYM usunięciem produktów, których nie ma na źródle. Odświeżaj stronę, aby śledzić postęp.', 'wc-product-sync' ); ?>
				</p></div>
			<?php elseif ( isset( $_GET['total_error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					switch ( $_GET['total_error'] ) {
						case 'backup':
							esc_html_e( 'Total sync przerwany: musisz potwierdzić, że masz własną kopię zapasową bazy danych. Wtyczka NIE robi kopii.', 'wc-product-sync' );
							break;
						case 'busy':
							esc_html_e( 'Total sync przerwany: inna synchronizacja lub scalanie już trwa. Poczekaj na zakończenie.', 'wc-product-sync' );
							break;
						case 'source_empty':
							esc_html_e( 'Total sync przerwany: źródło nie udostępnia żadnych produktów. Mirror wykasowałby cały sklep — anulowano.', 'wc-product-sync' );
							break;
						case 'source_err':
							esc_html_e( 'Total sync przerwany: nie udało się pobrać liczby produktów ze źródła (błąd połączenia/uprawnień). Sprawdź konfigurację i spróbuj ponownie.', 'wc-product-sync' );
							break;
						default:
							esc_html_e( 'Total sync przerwany.', 'wc-product-sync' );
					}
					?>
				</p></div>
			<?php endif; ?>

			<?php
			// Completion summary for a background run: shown when nothing is active but a run
			// finished recently. Auto-refresh lands the user here (no query args) once done.
			if ( ! $progress ) {
				$last = get_option( self::SYNC_LAST_RESULT, array() );
				if ( is_array( $last ) && empty( $last['running'] ) && ! empty( $last['finished_at'] )
					&& ( time() - (int) $last['finished_at'] ) < DAY_IN_SECONDS ) {
					printf(
						'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
						esc_html( sprintf(
							/* translators: 1: created, 2: updated, 3: skipped, 4: errors, 5: when */
							__( 'Synchronizacja zakończona (%5$s). Utworzono: %1$d, zaktualizowano: %2$d, pominięto: %3$d, błędy: %4$d.', 'wc-product-sync' ),
							(int) $last['created'], (int) $last['updated'], (int) $last['skipped'], (int) $last['errors'],
							human_time_diff( (int) $last['finished_at'] ) . ' temu'
						) )
					);
				}
				// Detailed report: what was created/updated (how) and skipped (why).
				$this->render_report_panel();
			}
			?>

			<form method="post" action="options.php">
				<?php settings_fields( 'wc_product_sync_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wps_source_url"><?php esc_html_e( 'URL źródła', 'wc-product-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[source_url]" id="wps_source_url" type="url" class="regular-text"
								placeholder="https://zrodlo.pl" value="<?php echo esc_attr( $opts['source_url'] ); ?>"
								<?php disabled( defined( 'WC_PRODUCT_SYNC_SOURCE_URL' ) ); ?> />
							<?php if ( defined( 'WC_PRODUCT_SYNC_SOURCE_URL' ) ) : ?>
								<p class="description"><?php esc_html_e( 'Nadpisane stałą w wp-config.php.', 'wc-product-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wps_ck"><?php esc_html_e( 'Consumer Key', 'wc-product-sync' ); ?></label></th>
						<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[consumer_key]" id="wps_ck" type="text" class="regular-text" autocomplete="off"
							value="<?php echo esc_attr( $opts['consumer_key'] ); ?>" <?php disabled( defined( 'WC_PRODUCT_SYNC_CK' ) ); ?> /></td>
					</tr>
					<tr>
						<th scope="row"><label for="wps_cs"><?php esc_html_e( 'Consumer Secret', 'wc-product-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[consumer_secret]" id="wps_cs" type="password" class="regular-text" autocomplete="off"
								value="<?php echo esc_attr( $opts['consumer_secret'] ); ?>" <?php disabled( defined( 'WC_PRODUCT_SYNC_CS' ) ); ?> />
							<p class="description"><?php esc_html_e( 'Zalecane: trzymaj klucze w wp-config.php, nie w bazie.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
<tr>
					<th scope="row"><label for="wps_pp"><?php esc_html_e( 'Produktów na stronę', 'wc-product-sync' ); ?></label></th>
					<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[per_page]" id="wps_pp" type="number" min="1" max="100" class="small-text"
						value="<?php echo esc_attr( $opts['per_page'] ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="wps_blim"><?php esc_html_e( 'Limit produktów na batch', 'wc-product-sync' ); ?></label></th>
					<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_batch_limit]" id="wps_blim" type="number" min="0" class="small-text"
						value="<?php echo esc_attr( $opts['sync_batch_limit'] ); ?>" />
<p class="description"><?php esc_html_e( 'Liczba produktów przetwarzana w jednym batchu przez WP-Cron. Sync kontynuuje automatycznie po zakończeniu każdego batcha (co 30s). 0 = bez limitu.', 'wc-product-sync' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><label for="wps_mbs"><?php esc_html_e( 'Limit czasu batcha (s)', 'wc-product-sync' ); ?></label></th>
					<td><input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[max_batch_seconds]" id="wps_mbs" type="number" min="0" max="600" class="small-text"
						value="<?php echo esc_attr( $opts['max_batch_seconds'] ?? 20 ); ?>" />
<p class="description"><?php esc_html_e( 'Batch zatrzymuje się i wznawia po tylu sekundach — zabezpiecza przed timeoutem PHP (postęp zapisywany co produkt, wznowienie od dokładnej pozycji). Ustaw poniżej limitu hosta (zwykle 30 s → 20 s). 0 = bez limitu czasu.', 'wc-product-sync' ); ?></p></td>
				</tr>
				<?php
				$wps_checkbox_group = function ( $field, $choices, $selected ) {
					foreach ( $choices as $val => $lbl ) {
						printf(
							'<label style="margin:0 14px 4px 0; display:inline-block;"><input type="checkbox" name="%1$s[%2$s][]" value="%3$s" %4$s /> %5$s</label>',
							esc_attr( self::OPTION_KEY ), esc_attr( $field ), esc_attr( $val ),
							checked( in_array( $val, (array) $selected, true ), true, false ), esc_html( $lbl )
						);
					}
				};
				?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Typy produktów', 'wc-product-sync' ); ?></th>
						<td>
							<?php $wps_checkbox_group( 'sync_types', array(
								'simple'   => __( 'Proste', 'wc-product-sync' ),
								'variable' => __( 'Wariacyjne', 'wc-product-sync' ),
								'grouped'  => __( 'Grupowane', 'wc-product-sync' ),
							), $opts['sync_types'] ); ?>
							<p class="description"><?php esc_html_e( 'Które typy synchronizować. Pozostałe są pomijane (z powodem w raporcie).', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Statusy w źródle', 'wc-product-sync' ); ?></th>
						<td>
							<?php $wps_checkbox_group( 'sync_statuses', array(
								'publish' => 'publish', 'draft' => 'draft', 'pending' => 'pending', 'private' => 'private',
							), $opts['sync_statuses'] ); ?>
							<p class="description"><?php esc_html_e( 'Produkty z jakim statusem w źródle synchronizować. Domyślnie tylko opublikowane.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pola do synchronizacji', 'wc-product-sync' ); ?></th>
						<td>
							<?php $wps_checkbox_group( 'sync_fields', array(
								'description' => __( 'Opis', 'wc-product-sync' ),
								'price'       => __( 'Cena', 'wc-product-sync' ),
								'stock'       => __( 'Stan magazynowy', 'wc-product-sync' ),
								'images'      => __( 'Obrazy', 'wc-product-sync' ),
								'categories'  => __( 'Kategorie', 'wc-product-sync' ),
								'attributes'  => __( 'Atrybuty', 'wc-product-sync' ),
								'dimensions'  => __( 'Waga i wymiary', 'wc-product-sync' ),
							), $opts['sync_fields'] ); ?>
							<p class="description"><?php esc_html_e( 'Odznaczone pola NIE są nadpisywane (przy tworzeniu i aktualizacji) — lokalne zmiany są zachowane. Nazwa, status i SKU są zawsze synchronizowane.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Modyfikator ceny', 'wc-product-sync' ); ?></th>
						<td>
							<?php $wps_round = $opts['price_rounding'] ?? 'standard'; ?>
							<label><?php esc_html_e( 'Procent:', 'wc-product-sync' ); ?>
								<input type="number" step="0.01" style="width:90px"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[price_markup_pct]"
									value="<?php echo esc_attr( $opts['price_markup_pct'] ?? 0 ); ?>" /> %</label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Kwota stała:', 'wc-product-sync' ); ?>
								<input type="number" step="0.01" style="width:100px"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[price_markup_fixed]"
									value="<?php echo esc_attr( $opts['price_markup_fixed'] ?? 0 ); ?>" /></label>
							&nbsp;&nbsp;
							<label><?php esc_html_e( 'Zaokrąglenie:', 'wc-product-sync' ); ?>
								<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[price_rounding]">
									<option value="standard" <?php selected( $wps_round, 'standard' ); ?>><?php esc_html_e( 'Standardowo (2 miejsca)', 'wc-product-sync' ); ?></option>
									<option value="integer"  <?php selected( $wps_round, 'integer' ); ?>><?php esc_html_e( 'Do pełnych', 'wc-product-sync' ); ?></option>
									<option value="charm"    <?php selected( $wps_round, 'charm' ); ?>><?php esc_html_e( 'Końcówka ,99', 'wc-product-sync' ); ?></option>
									<option value="none"     <?php selected( $wps_round, 'none' ); ?>><?php esc_html_e( 'Bez zaokrąglania', 'wc-product-sync' ); ?></option>
								</select></label>
							<p class="description">
								<?php esc_html_e( 'Cena na celu = cena źródła × (1 + procent/100) + kwota stała, potem zaokrąglenie. Dotyczy ceny regularnej i promocyjnej, produktów prostych i wariacji. Wartości ujemne obniżają cenę; wynik nigdy nie spada poniżej 0. Domyślnie (0% + 0) cena jest kopiowana bez zmian. Działa tylko, gdy pole „Cena" powyżej jest włączone.', 'wc-product-sync' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Harmonogram', 'wc-product-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[schedule_enabled]" value="1"
								<?php checked( ! empty( $opts['schedule_enabled'] ) ); ?> /> <?php esc_html_e( 'Uruchamiaj codziennie (WP-Cron)', 'wc-product-sync' ); ?></label>
							<p class="description">
								<?php
								if ( $next ) {
									printf( esc_html__( 'Następne uruchomienie: %s', 'wc-product-sync' ), esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i:s' ) ) );
								} else {
									esc_html_e( 'Brak zaplanowanego uruchomienia.', 'wc-product-sync' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Godzina sync', 'wc-product-sync' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Codziennie o:', 'wc-product-sync' ); ?>
								<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cron_hour]" id="wps_cron_h" type="number" min="0" max="23" class="small-text"
									value="<?php echo esc_attr( $opts['cron_hour'] ?? 3 ); ?>" style="width:60px;" /> :
								<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[cron_minute]" id="wps_cron_m" type="number" min="0" max="59" class="small-text"
									value="<?php echo esc_attr( $opts['cron_minute'] ?? 0 ); ?>" style="width:60px;" />
							</label>
							<p class="description"><?php esc_html_e( 'Domena czasu WordPress. Domyślnie 03:00.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Szybka synchronizacja (cykliczna)', 'wc-product-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fast_sync_enabled]" value="1"
								<?php checked( ! empty( $opts['fast_sync_enabled'] ) ); ?> /> <?php esc_html_e( 'Odświeżaj wybrane pola co', 'wc-product-sync' ); ?></label>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[fast_sync_interval_min]" type="number" min="15" max="1440" step="5" class="small-text"
								value="<?php echo esc_attr( $opts['fast_sync_interval_min'] ?? 60 ); ?>" style="width:70px;" /> <?php esc_html_e( 'minut', 'wc-product-sync' ); ?>
							<div style="margin-top:6px;">
								<?php $wps_checkbox_group( 'fast_sync_fields', array(
									'price' => __( 'Cena', 'wc-product-sync' ),
									'stock' => __( 'Stan magazynowy', 'wc-product-sync' ),
								), $opts['fast_sync_fields'] ); ?>
							</div>
							<p class="description">
								<?php esc_html_e( 'Lekki, częsty przebieg między codziennymi synchronizacjami — tylko AKTUALIZUJE istniejące produkty (bez tworzenia i usuwania), tylko zaznaczone pola. Minimum 15 minut.', 'wc-product-sync' ); ?>
								<br />
								<?php
								if ( $fast_next ) {
									printf( esc_html__( 'Następne odświeżenie: %s', 'wc-product-sync' ), esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $fast_next ), 'Y-m-d H:i:s' ) ) );
								} else {
									esc_html_e( 'Nie zaplanowano.', 'wc-product-sync' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-odświeżanie postępu', 'wc-product-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[admin_auto_refresh]" value="1"
								<?php checked( ! empty( $opts['admin_auto_refresh'] ) ); ?> /> <?php esc_html_e( 'Automatycznie przeładowuj tę stronę co 8 s podczas synchronizacji', 'wc-product-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'Domyślnie wyłączone — pełne przeładowanie strony destabilizuje UI (przeskok scrolla, migotanie). Zamiast tego użyj przycisku „Odśwież postęp" widocznego w trakcie synchronizacji.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pełna synchronizacja', 'wc-product-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[force_full_sync]" value="1"
								<?php checked( ! empty( $opts['force_full_sync'] ) ); ?> /> <?php esc_html_e( 'Trwale usuń lokalne produkty nieobecne w źródle', 'wc-product-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'UWAGA: po zakończeniu przebiegu trwale usuwa lokalne produkty oznaczone jako zsynchronizowane, których NIE odświeżono w tym przebiegu (zniknęły ze źródła). Produkty utworzone/zaktualizowane w tym przebiegu są zachowane. Pomijane przy błędzie pobierania ze źródła.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Produkty usunięte ze źródła', 'wc-product-sync' ); ?></th>
						<td>
							<?php $wps_dm = $opts['deletion_mode'] ?? 'none'; ?>
							<label style="display:block; margin-bottom:4px;"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deletion_mode]" value="none"
								<?php checked( $wps_dm, 'none' ); ?> /> <?php esc_html_e( 'Nie ruszaj (pozostają opublikowane)', 'wc-product-sync' ); ?></label>
							<label style="display:block; margin-bottom:4px;"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deletion_mode]" value="soft"
								<?php checked( $wps_dm, 'soft' ); ?> /> <?php esc_html_e( 'Ustaw jako szkic + tag (bezpieczne, odwracalne)', 'wc-product-sync' ); ?></label>
							<label style="display:block;"><input type="radio" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[deletion_mode]" value="hard"
								<?php checked( $wps_dm, 'hard' ); ?> /> <strong style="color:#b32d2e;"><?php esc_html_e( 'Usuń trwale — NIEODWRACALNE', 'wc-product-sync' ); ?></strong></label>
							<p class="description">
								<?php
								printf(
									esc_html__( 'Co zrobić z produktami, których nie ma już w źródle. Dotyczy tylko produktów synchronizowanych przez ten plugin (znacznik %1$s). „Szkic" oznacza je tagiem „%2$s"; „Usuń trwale" kasuje na stałe (z pominięciem kosza).', 'wc-product-sync' ),
									'<code>' . esc_html( self::META_SYNCED ) . '</code>',
									esc_html( self::TAG_NAME )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wps_sdl"><?php esc_html_e( 'Limit szkiców (tryb szkic)', 'wc-product-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[soft_delete_limit]" id="wps_sdl" type="number" min="0" class="small-text"
								value="<?php echo esc_attr( $opts['soft_delete_limit'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Ile szkiców soft-delete zachować. Najstarsze ponad limit są trwale usuwane. 0 = bez limitu.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wps_hdm"><?php esc_html_e( 'Limit trwałych usunięć / przebieg', 'wc-product-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[hard_delete_max]" id="wps_hdm" type="number" min="0" class="small-text"
								value="<?php echo esc_attr( $opts['hard_delete_max'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Bezpiecznik trybu „Trwałe usunięcie": maks. produktów usuwanych w jednym przebiegu. Chroni przed skasowaniem całego katalogu przy chwilowym błędzie źródła. 0 = bez limitu.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<?php $wps_ch = $opts['update_channel'] ?? 'stable'; $wps_ch_locked = defined( 'WC_PRODUCT_SYNC_UPDATE_URL' ); ?>
					<tr>
						<th scope="row"><label for="wps_channel"><?php esc_html_e( 'Kanał aktualizacji', 'wc-product-sync' ); ?></label></th>
						<td>
							<select name="<?php echo esc_attr( self::OPTION_KEY ); ?>[update_channel]" id="wps_channel" <?php disabled( $wps_ch_locked ); ?>>
								<option value="stable" <?php selected( $wps_ch, 'stable' ); ?>><?php esc_html_e( 'Stabilny (zalecany)', 'wc-product-sync' ); ?></option>
								<option value="rc" <?php selected( $wps_ch, 'rc' ); ?>><?php esc_html_e( 'Testowy (RC) — może zawierać błędy, nie na produkcję', 'wc-product-sync' ); ?></option>
							</select>
							<?php if ( $wps_ch_locked ) : ?>
								<p class="description"><?php esc_html_e( 'Wyłączone: adres aktualizacji jest ustawiony stałą WC_PRODUCT_SYNC_UPDATE_URL w wp-config.php, która ma pierwszeństwo nad tym wyborem.', 'wc-product-sync' ); ?></p>
							<?php else : ?>
								<p class="description"><?php esc_html_e( 'Stabilny pobiera wydania produkcyjne. Testowy (RC) pobiera kandydatów do wydania — do sprawdzenia przed publikacją, nie na sklep produkcyjny. Zmiana kanału odświeża sprawdzanie aktualizacji od razu.', 'wc-product-sync' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Zapisz ustawienia', 'wc-product-sync' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Uruchomienie ręczne', 'wc-product-sync' ); ?></h2>
			<?php if ( ! $this->is_configured() ) : ?>
				<p><?php esc_html_e( 'Uzupełnij konfigurację źródła, aby uruchomić synchronizację.', 'wc-product-sync' ); ?></p>
			<?php else : ?>
				<p>
					<a href="<?php echo esc_url( $dry_url ); ?>" class="button"><?php esc_html_e( 'Symulacja (dry run)', 'wc-product-sync' ); ?></a>
					<a href="<?php echo esc_url( $run_url ); ?>" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( 'Uruchomić synchronizację teraz?', 'wc-product-sync' ) ); ?>');">
						<?php esc_html_e( 'Synchronizuj teraz', 'wc-product-sync' ); ?></a>
				</p>

				<h3><?php esc_html_e( 'Scalanie i cofanie', 'wc-product-sync' ); ?></h3>
				<p class="description" style="max-width:60em">
					<?php esc_html_e( 'Gdy w sklepie są już produkty założone innym narzędziem, „Scal istniejące" nadaje im znacznik źródła po SKU lub jednoznacznej nazwie — dzięki temu kolejna synchronizacja je zaktualizuje, zamiast tworzyć duplikaty. „Cofnij ostatnią synchronizację" przenosi do kosza produkty utworzone w ostatnim przebiegu (odwracalne).', 'wc-product-sync' ); ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $adopt_prev_url ); ?>" class="button">
						<?php esc_html_e( 'Scal istniejące — podgląd', 'wc-product-sync' ); ?></a>
					<a href="<?php echo esc_url( $undo_url ); ?>" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Przenieść do kosza produkty utworzone w ostatniej synchronizacji?', 'wc-product-sync' ) ); ?>');">
						<?php printf( esc_html__( 'Cofnij ostatnią synchronizację (%d)', 'wc-product-sync' ), (int) $undo_count ); ?></a>
				</p>

				<?php
				// Adoption status/preview, driven by the backgrounded run (ADOPT_RESULT + preview transient).
				$adopt_res = get_option( self::ADOPT_RESULT );
				if ( is_array( $adopt_res ) ) :
					if ( ! empty( $adopt_res['running'] ) ) : ?>
						<div class="notice notice-info"><p>
							<?php esc_html_e( 'Scalanie w toku w tle… Odśwież stronę za chwilę, aby zobaczyć wynik.', 'wc-product-sync' ); ?>
						</p></div>
					<?php elseif ( ! empty( $adopt_res['apply'] ) ) : ?>
						<div class="notice notice-success"><p>
							<?php printf( esc_html__( 'Scalono: %d istniejących produktów oznaczono jako powiązane ze źródłem. Następna synchronizacja je zaktualizuje zamiast tworzyć duplikaty.', 'wc-product-sync' ), (int) $adopt_res['adopt'] ); ?>
						</p></div>
						<?php if ( ! empty( $adopt_res['mirror_skipped'] ) ) : ?>
							<div class="notice notice-error"><p>
								<?php esc_html_e( 'Total sync: faza lustrzana (usuwanie brakujących) NIE ruszyła, bo inna synchronizacja była w toku. Scalanie się wykonało — uruchom „Total sync" ponownie, gdy druga synchronizacja się zakończy.', 'wc-product-sync' ); ?>
							</p></div>
						<?php endif; ?>
					<?php else :
						$plan = get_transient( 'wps_adopt_preview' );
						if ( is_array( $plan ) ) :
							$n_adopt = count( $plan['adopt'] );
							?>
							<div class="notice notice-info"><p>
								<?php printf( esc_html__( 'Podgląd scalania: do scalenia %1$d, wieloznacznych (do ręcznego sprawdzenia) %2$d, już przypisanych %3$d.', 'wc-product-sync' ),
									$n_adopt, count( $plan['ambiguous'] ), (int) $plan['claimed'] ); ?>
								<?php if ( $n_adopt ) : ?>
									<a href="<?php echo esc_url( $adopt_apply_url ); ?>" class="button button-primary"
										onclick="return confirm('<?php echo esc_js( sprintf( __( 'Scalić %d produktów? Nada im znacznik źródła (odwracalne przez ponowne czyszczenie meta).', 'wc-product-sync' ), $n_adopt ) ); ?>');">
										<?php printf( esc_html__( 'Scal %d teraz', 'wc-product-sync' ), $n_adopt ); ?></a>
								<?php endif; ?>
							</p></div>
							<?php if ( $n_adopt ) : ?>
								<table class="widefat striped" style="max-width:60em"><thead><tr>
									<th><?php esc_html_e( 'Produkt (cel)', 'wc-product-sync' ); ?></th>
									<th>SKU</th><th><?php esc_html_e( 'Dopasowano po', 'wc-product-sync' ); ?></th>
								</tr></thead><tbody>
								<?php foreach ( array_slice( $plan['adopt'], 0, 100 ) as $a ) : ?>
									<tr>
										<td><?php echo esc_html( get_the_title( $a['local_id'] ) ); ?> <span class="description">#<?php echo (int) $a['local_id']; ?></span></td>
										<td><?php echo esc_html( $a['sku'] ?: '—' ); ?></td>
										<td><?php echo esc_html( $a['how'] ); ?></td>
									</tr>
								<?php endforeach; ?>
								</tbody></table>
								<?php if ( $n_adopt > 100 ) : ?><p class="description"><?php printf( esc_html__( '…i %d więcej.', 'wc-product-sync' ), $n_adopt - 100 ); ?></p><?php endif; ?>
							<?php endif; ?>
						<?php endif; ?>
					<?php endif; ?>
				<?php endif; ?>

				<hr />
				<h3 style="color:#b32d2e"><?php esc_html_e( 'Total sync — lustro źródła (nieodwracalne)', 'wc-product-sync' ); ?></h3>
				<div style="border:1px solid #b32d2e;border-left-width:4px;background:#fcf0f1;padding:12px 16px;max-width:60em">
					<p style="margin-top:0">
						<strong><?php esc_html_e( 'Uwaga: to nadpisuje sklep obrazem źródła.', 'wc-product-sync' ); ?></strong>
						<?php esc_html_e( 'Kolejno: (1) scala istniejące produkty po SKU i nazwie, (2) synchronizuje wszystkie produkty ze źródła, (3) TWARDO usuwa (bez kosza) każdy produkt na celu, którego nie ma na źródle. Działa tylko, gdy źródło udostępnia więcej niż zero produktów.', 'wc-product-sync' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Wtyczka NIE robi kopii zapasowej. Przed uruchomieniem zrób własny zrzut bazy danych (i katalogu uploads, jeśli używasz obrazów). Zalecane: najpierw „Scal istniejące — podgląd", aby zobaczyć, co zostanie dopasowane.', 'wc-product-sync' ); ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						onsubmit="return confirm('<?php echo esc_js( __( 'OSTATNIE OSTRZEŻENIE: total sync TWARDO usunie wszystkie produkty na celu, których nie ma na źródle. Tej operacji nie da się cofnąć bez kopii bazy. Kontynuować?', 'wc-product-sync' ) ); ?>');">
						<input type="hidden" name="action" value="wc_product_sync_total" />
						<?php wp_nonce_field( self::NONCE_ACTION . '_total' ); ?>
						<p><label>
							<input type="checkbox" name="wps_backup_ack" value="1"
								onchange="document.getElementById('wps-total-go').disabled = ! this.checked;" />
							<?php esc_html_e( 'Mam własną kopię zapasową bazy danych i rozumiem, że usunięcie jest nieodwracalne.', 'wc-product-sync' ); ?>
						</label></p>
						<p>
							<button type="submit" id="wps-total-go" class="button" disabled
								style="background:#b32d2e;border-color:#b32d2e;color:#fff">
								<?php esc_html_e( 'Uruchom total sync (lustro źródła)', 'wc-product-sync' ); ?></button>
						</p>
					</form>
				</div>
			<?php endif; ?>
			<p class="description">
				<?php printf( esc_html__( 'Logi: %s', 'wc-product-sync' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">WooCommerce → Status → Logi</a>' ); ?>
			</p>
		</div>
		<?php
	}

	/** Detailed per-run report: expandable sections with per-item "how"/"why". */
	private function render_report_panel() {
		$report = get_option( self::SYNC_LAST_REPORT, array() );
		if ( ! is_array( $report ) || empty( $report ) ) {
			return;
		}
		$buckets = array(
			'created'      => __( 'Utworzono', 'wc-product-sync' ),
			'updated'      => __( 'Zaktualizowano', 'wc-product-sync' ),
			'skipped'      => __( 'Pominięto', 'wc-product-sync' ),
			'soft_deleted' => __( 'Oznaczono jako usunięte (draft)', 'wc-product-sync' ),
			'hard_deleted' => __( 'Usunięto trwale (brak w źródle)', 'wc-product-sync' ),
			'warnings'     => __( 'Ostrzeżenia', 'wc-product-sync' ),
			'errors'       => __( 'Błędy', 'wc-product-sync' ),
		);
		$has_any = false;
		foreach ( $buckets as $k => $l ) {
			if ( ! empty( $report[ $k ] ) ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Raport ostatniej synchronizacji', 'wc-product-sync' )
			. ( ! empty( $report['dry'] ) ? ' <em>(' . esc_html__( 'symulacja', 'wc-product-sync' ) . ')</em>' : '' ) . '</h2>';
		if ( ! empty( $report['_truncated'] ) ) {
			printf( '<p class="description">%s</p>', esc_html( sprintf(
				/* translators: %d: per-bucket cap */
				__( 'Lista skrócona do %d pozycji na kategorię — liczniki powyżej są dokładne, pełne szczegóły w logach WooCommerce.', 'wc-product-sync' ),
				self::REPORT_BUCKET_CAP ) ) );
		}

		foreach ( $buckets as $key => $label ) {
			if ( empty( $report[ $key ] ) ) {
				continue;
			}
			$items    = $report[ $key ];
			$last_col = in_array( $key, array( 'created', 'updated' ), true )
				? __( 'Jak', 'wc-product-sync' ) : __( 'Powód', 'wc-product-sync' );
			printf( '<details style="margin:6px 0;"><summary style="cursor:pointer; font-weight:600; padding:4px 0;">%s (%d)</summary>',
				esc_html( $label ), count( $items ) );
			echo '<table class="widefat striped" style="margin:6px 0 12px;"><thead><tr>'
				. '<th>' . esc_html__( 'Produkt', 'wc-product-sync' ) . '</th><th>SKU</th>'
				. '<th>' . esc_html__( 'Typ', 'wc-product-sync' ) . '</th><th>' . esc_html( $last_col ) . '</th>'
				. '</tr></thead><tbody>';
			foreach ( $items as $it ) {
				printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $it['name'] ?? '' ),
					esc_html( $it['sku'] ?? '' ),
					esc_html( $it['type'] ?? '' ),
					esc_html( $it['how'] ?? ( $it['reason'] ?? '' ) ) );
			}
			echo '</tbody></table></details>';
		}
	}

	public function handle_manual_run() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'wc-product-sync' ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}

		$dry_run = isset( $_GET['mode'] ) && 'dry' === $_GET['mode'];

		// Both dry and real runs go to the background via WP-Cron and batch there. A dry run was
		// once synchronous ("it's quick"), but on a large catalog it processes everything in one
		// request and mod_fcgid kills it at its read-data timeout (31s on this host) — a generic
		// 500. Backgrounding + batching keeps every request well under that limit, the same reason
		// the real run is safe. The 'dry' flag rides in the progress transient so every batch stays
		// a simulation.
		if ( false === get_transient( self::SYNC_PROGRESS_TRANSIENT ) ) {
			$this->dry_mode = $dry_run;
			$this->reset_run_result( $dry_run );
			$this->seed_sync_progress();
			wp_schedule_single_event( time(), self::CRON_HOOK ); // Immediate one-off, separate from the daily event.
			spawn_cron(); // Nudge WP-Cron to fire the event now instead of on the next visitor.
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-product-sync&started=1' . ( $dry_run ? '&dry=1' : '' ) ) );
		exit;
	}

	public function handle_cancel_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'wc-product-sync' ) );
		}
		check_admin_referer( self::NONCE_ACTION . '_cancel' );

		$this->cancel_sync();
		wp_safe_redirect( admin_url( 'admin.php?page=wc-product-sync&cancelled=1' ) );
		exit;
	}

	/** Undo the last sync: trash the products that run created. Reversible (WooCommerce → Kosz). */
	public function handle_undo() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'wc-product-sync' ) );
		}
		check_admin_referer( self::NONCE_ACTION . '_undo' );
		$n = $this->undo_run();
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'wc-product-sync', 'undone' => (int) $n ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** Adopt existing products: preview shows the plan; confirm stamps _wps_source_id so the next
	 *  sync updates them instead of creating duplicates. mode=preview writes nothing. */
	public function handle_adopt() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'wc-product-sync' ) );
		}
		check_admin_referer( self::NONCE_ACTION . '_adopt' );
		$apply = ( ( $_GET['mode'] ?? 'preview' ) === 'apply' );

		// Background + batched, like the sync: a full reconcile over a large catalog would blow past
		// the FastCGI read timeout (31s on some hosts) if run in this request.
		$this->adopt_reset( $apply );
		delete_transient( 'wps_adopt_preview' );
		update_option( self::ADOPT_RESULT, array( 'running' => 1, 'apply' => $apply ? 1 : 0 ), false );
		wp_schedule_single_event( time(), self::ADOPT_HOOK );
		spawn_cron();
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'wc-product-sync', 'adopt_started' => $apply ? 'apply' : 'preview' ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/**
	 * Total sync (mirror the source). Sequence, all backgrounded and batched:
	 *   1. require the "I have a DB backup" confirmation (the plugin does NOT back up — hard delete),
	 *   2. guard: the source must expose > 0 products (never mirror an empty/broken source over the shop),
	 *   3. adopt (apply): match by SKU + name so shared products are UPDATED, not deleted,
	 *   4. on adopt completion, chain into the mirror sync (force_full + hard delete) which removes
	 *      whatever the source no longer has.
	 * The double confirmation (preview + typed intent) lives in the admin form/JS; this handler is the
	 * point of no return, so it re-checks the backup acknowledgement server-side.
	 */
	public function handle_total_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'wc-product-sync' ) );
		}
		check_admin_referer( self::NONCE_ACTION . '_total' );

		// The plugin does not make a backup — this is a hard, irreversible delete of everything the
		// source no longer has. Refuse unless the operator confirms they hold their own DB backup.
		if ( empty( $_POST['wps_backup_ack'] ) ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => 'wc-product-sync', 'total_error' => 'backup' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		// Refuse to run if another sync/adopt is already in flight.
		if ( false !== get_transient( self::SYNC_PROGRESS_TRANSIENT )
			|| ( is_array( get_option( self::ADOPT_RESULT ) ) && ! empty( get_option( self::ADOPT_RESULT )['running'] ) ) ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => 'wc-product-sync', 'total_error' => 'busy' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		// Guard: the source must actually have products. Mirroring an empty (or unreachable) source
		// would hard-delete the whole target catalog — exactly the disaster this check prevents.
		$src_count = $this->source_product_count();
		if ( $src_count < 1 ) {
			wp_safe_redirect( add_query_arg(
				array( 'page' => 'wc-product-sync', 'total_error' => is_wp_error( $src_count ) ? 'source_err' : 'source_empty' ),
				admin_url( 'admin.php' )
			) );
			exit;
		}

		// Start the adopt phase in apply mode with the total flag; run_adopt_event chains the mirror
		// sync once every product is linked.
		$this->adopt_reset( true, true );
		delete_transient( 'wps_adopt_preview' );
		update_option( self::ADOPT_RESULT, array( 'running' => 1, 'apply' => 1, 'total' => 1 ), false );
		wp_schedule_single_event( time(), self::ADOPT_HOOK );
		spawn_cron();
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'wc-product-sync', 'total_started' => 1 ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	/** Count products the source exposes to these credentials. Returns an int (0 if none/hidden by
	 *  permissions) or a WP_Error on transport failure — the caller must NOT treat an error as empty.
	 *  The only decision that rides on this is "empty vs non-empty" (the total-sync guard), so when the
	 *  X-WP-Total header is stripped by a proxy the per_page=1 body is enough: 0 rows → 0, ≥1 row → ≥1,
	 *  which is all the guard needs. The exact figure only matters when the header is present. */
	private function source_product_count() {
		$body = $this->api_get( '/wp-json/wc/v3/products', array( 'per_page' => 1, 'status' => 'any' ) );
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$total = 0;
		if ( isset( $this->last_api_headers['X-WP-Total'] ) ) {
			$total = absint( $this->last_api_headers['X-WP-Total'] );
		} elseif ( isset( $this->last_api_headers['x-wp-total'] ) ) {
			$total = absint( $this->last_api_headers['x-wp-total'] );
		}
		if ( $total > 0 ) {
			return $total;
		}
		// Header missing/zeroed on some hosts — fall back to counting the returned body.
		return is_array( $body ) ? count( $body ) : 0;
	}

	/** Fallback when WP-Cron isn't firing: run the pending batch synchronously in this request.
	 *  Blocks for one batch (user-initiated), then redirects back to watch/continue. */
	public function handle_step_sync() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'wc-product-sync' ) );
		}
		check_admin_referer( self::NONCE_ACTION . '_step' );
		if ( function_exists( 'ignore_user_abort' ) ) {
			@ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) && ! @set_time_limit( 900 ) ) {
			$this->log( 'warning', 'set_time_limit(900) failed — sync may hit PHP timeout.' );
		}
		$progress = $this->get_sync_progress();
		if ( $progress ) {
			if ( (int) ( $progress['current_page'] ?? 0 ) < 1 ) {
				$this->run_sync_cron();      // still the "starting" seed → run the first batch
			} else {
				$this->run_resume_batch();   // continue from where cron left off
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=wc-product-sync&stepped=1' ) );
		exit;
	}

	public function run_sync_cron() {
		// Authoritative: this is a FULL sync. Reset $fast_mode explicitly — WP-Cron runs all due
		// hooks in ONE request on this singleton, so a fast event processed earlier in the same
		// request would otherwise leave $fast_mode=true and silently degrade this run to update-only.
		$this->fast_mode = false;
		// Don't hijack an in-flight fast (field-refresh) sync's batched progress in full mode —
		// let it finish; the daily run will proceed on its next scheduled tick.
		$prog = $this->get_sync_progress();
		if ( $prog && ! empty( $prog['fast'] ) ) {
			$this->log( 'info', 'Codzienna synchronizacja odłożona — szybka synchronizacja w toku.' );
			return;
		}
		// A seeded dry run schedules THIS hook too; honor its flag so the first batch simulates.
		$this->dry_mode   = ! empty( $prog['dry'] );
		$this->total_sync = ! empty( $prog['total'] );
		$this->run_sync( $this->dry_mode );
	}

	/** Frequent field-refresh (e.g. hourly price/stock). Update-only, restricted to fast_sync_fields.
	 *  Reuses the full sync pipeline via $fast_mode; skips if any sync is already in progress. */
	public function run_fast_sync_cron() {
		if ( $this->get_sync_progress() || false !== get_transient( self::SYNC_LOCK_TRANSIENT ) ) {
			$this->log( 'info', 'Szybka synchronizacja pominięta — inna synchronizacja w toku.' );
			return;
		}
		if ( empty( (array) $this->get_options()['fast_sync_fields'] ) ) {
			$this->log( 'info', 'Szybka synchronizacja: brak wybranych pól — pomijam.' );
			return;
		}
		$this->fast_mode = true;
		$this->log( 'info', '=== Szybka synchronizacja (aktualizacja istniejących, wybrane pola) ===' );
		try {
			$this->run_sync( false );
		} finally {
			// Never leave fast mode set on the singleton: a later hook (e.g. the daily CRON_HOOK) in
			// the same WP-Cron request must not inherit it. run_resume_batch re-derives mode from the
			// saved progress, so clearing here is safe for multi-batch fast runs.
			$this->fast_mode = false;
		}
	}

	/* =====================================================================
	 *  REST – odczyt ze źródła
	 * ================================================================== */

	private function api_get( $path, array $query = array() ) {
		$url  = add_query_arg( $query, $this->cfg_source_url() . $path );
		$auth = 'Basic ' . base64_encode( $this->cfg_ck() . ':' . $this->cfg_cs() );
		$max  = 5;

		for ( $attempt = 1; $attempt <= $max; $attempt++ ) {
			$response = wp_remote_get( $url, array(
				'timeout' => 60,
				'headers' => array( 'Authorization' => $auth ),
			) );

			if ( is_wp_error( $response ) ) {
				if ( $attempt >= $max ) {
					return $response;
				}
				sleep( min( 30, pow( 2, $attempt ) ) );
				continue;
			}

			// Store headers for progress tracking (X-WP-TotalPages)
			$this->last_api_headers = wp_remote_retrieve_headers( $response );

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					return new WP_Error( 'wps_json', 'Niepoprawny JSON z ' . $url );
				}
				return is_array( $body ) ? $body : array();
			}

			if ( 429 === $code || $code >= 500 ) {
				if ( $attempt >= $max ) {
					return new WP_Error( 'wps_http', "HTTP $code z $url po $attempt próbach" );
				}
				sleep( min( 30, pow( 2, $attempt ) ) );
				continue;
			}

			return new WP_Error( 'wps_http', "HTTP $code z $url" );
		}
		return new WP_Error( 'wps_http', "Nieudane pobranie $url" );
	}

	private function fetch_product_page( int $page ) {
		return $this->api_get( '/wp-json/wc/v3/products', array(
			'per_page' => $this->cfg_per_page(),
			'page'     => $page,
			'status'   => 'any',
		) );
	}

	private function fetch_source_attributes() {
		$out  = array();
		$list = $this->api_get( '/wp-json/wc/v3/products/attributes', array( 'per_page' => 100 ) );
		if ( is_wp_error( $list ) ) {
			// Signal failure: without the global-attribute map, variable products would be
			// rebuilt with NO attributes (map lookup → null → skipped), silently wiping them.

			// Keep the underlying reason. Without it the admin only ever saw "could not fetch
			// attribute definitions", with no hint that it was an HTTP 401 — and this endpoint
			// fails for a DIFFERENT reason than /products: WooCommerce guards it with
			// `manage_product_terms`, not `read_private_products`, so a key whose user can list
			// products may still be unable to read attributes.
			$this->log( 'warning', 'Fallback /products/attributes nieudany: ' . $list->get_error_message()
				. ' — endpoint wymaga uprawnienia "manage_product_terms" (Administrator lub Kierownik sklepu),'
				. ' innego niż czytanie produktów. Mapa atrybutów jest odtwarzana z payloadów, więc zwykle nie jest potrzebny.' );
			return $out;
		}
		foreach ( $list as $a ) {
			if ( empty( $a['id'] ) ) {
				continue;
			}
			$slug = isset( $a['slug'] ) ? $a['slug'] : '';
			$out[ (int) $a['id'] ] = array(
				'name' => isset( $a['name'] ) ? $a['name'] : $slug,
				'slug' => preg_replace( '/^pa_/', '', $slug ),
			);
		}
		return $out;
	}

	private function fetch_variations( $source_parent_id ) {
		$variations = array();
		$page       = 1;
		$per_page   = $this->cfg_per_page();
		do {
			$batch = $this->api_get( "/wp-json/wc/v3/products/{$source_parent_id}/variations", array(
				'per_page' => $per_page,
				'page'     => $page,
			) );
			if ( is_wp_error( $batch ) ) {
				$this->variations_fetch_error = true;
				$this->log( 'warning', "Wariacje produktu {$source_parent_id}, strona {$page}: " . $batch->get_error_message() );
				break;
			}
			$count = count( $batch );
			if ( 0 === $count ) {
				break;
			}
			$variations = array_merge( $variations, $batch );
			$page++;
		} while ( $count === $per_page );
		return $variations;
	}

	/* =====================================================================
	 *  Orkiestracja
	 * ================================================================== */

	private function run_sync( $dry_run = false ) {
		$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0 );

		if ( ! $this->wc_active() ) {
			$this->log( 'error', 'WooCommerce nieaktywne – przerywam.' );
			return $stats;
		}
		if ( ! $this->is_configured() ) {
			$this->log( 'error', 'Brak konfiguracji źródła – przerywam.' );
			return $stats;
		}

		// Check if there's a pending batch resume (current_page>=1 = real continuation, not a seed)
		$progress = $this->get_sync_progress();
		if ( $progress && ! empty( $progress['current_page'] ) && empty( $_GET['mode'] ) ) {
			$this->log( 'info', sprintf( 'Wznowienie z %d/%d produktów, strona %d', $progress['products_processed'], $progress['total_products'], $progress['current_page'] ) );
		}

		if ( function_exists( 'set_time_limit' ) && ! @set_time_limit( 900 ) ) {
			$this->log( 'warning', 'set_time_limit(900) failed — sync may hit PHP timeout.' );
		}

		set_transient( self::SYNC_LOCK_TRANSIENT, time(), 1800 );
		$still_batching = false; // Track if we hit batch limit and need cron reschedule

		try {
			$result = $this->run_sync_inner( $dry_run, $stats, $progress, $still_batching );
			$this->append_run_report( $dry_run ); // persist this batch's per-item report
			// Accumulate for dry runs too: they now batch in the background, so the completion
			// counts must total across batches, not just the last one.
			$this->accumulate_run_result( $stats, ! $still_batching );
			return $result;
		} catch ( \Exception $e ) {
			$this->log( 'error', 'Wyjątek w sync: ' . $e->getMessage() );
			return $stats;
		} finally {
			delete_transient( self::SYNC_LOCK_TRANSIENT );
			// Don't clear progress if we're still batching — cron will handle it
			if ( ! $still_batching ) {
				$this->clear_sync_progress();
			}
		}
	}

	private function run_sync_inner( $dry_run, &$stats, $progress = null, &$still_batching = false ) {
		$this->log( 'info', '=== Start synchronizacji' . ( $dry_run ? ' [DRY RUN]' : '' ) . ' ===' );

		// First batch of a fresh run? A seeded "starting" transient has current_page=0, so an
		// absent progress OR current_page<1 both mean "first pass" — the only time force_full and
		// (single-pass) soft-delete may run. A real resume has current_page>=1.
		// First batch of a fresh run only when there's no prior page AND no mid-page offset. A
		// mid-page-1 resume saves current_page=0 with page_offset>0 — it must NOT be treated as
		// "first batch" (which would reset the result/report/keys and, with force_full, re-delete
		// products on every resume).
		$is_first_batch = empty( $progress )
			|| ( (int) ( $progress['current_page'] ?? 0 ) < 1 && (int) ( $progress['page_offset'] ?? 0 ) < 1 );

		// Fresh run: start collecting source keys + the per-item report from scratch, and reset
		// the cumulative result (#1 — needed for scheduled/cron runs, which never hit the manual
		// kickoff's reset, so counts would otherwise accumulate across daily runs).
		if ( $is_first_batch ) {
			$this->reset_run_report();
			if ( ! $dry_run ) {
				$this->reset_run_result();
				delete_transient( self::SYNC_KEYS_TRANSIENT );
			}
		}

		// Pełna synchronizacja – po zakończeniu CAŁEGO przebiegu usuń lokalne produkty, które nie
		// zostały odświeżone w tym przebiegu (znacznik _wps_synced starszy niż start przebiegu →
		// zniknęły ze źródła). Uruchamiane na batchu, który KOŃCZY sync (niekoniecznie pierwszym),
		// więc BEZ warunku $is_first_batch — z nim dla katalogów dzielonych na batche kasacja nigdy
		// by się nie wykonała (koniec przypada na batch wznowienia, gdzie is_first_batch=false).
		// Sama kasacja jest dodatkowo bramkowana brakiem błędu pobierania (patrz niżej).
		// Total sync (mirror) forces force_full for this run regardless of the saved setting.
		$force_full = ( ! empty( $this->get_options()['force_full_sync'] ) || $this->total_sync ) && ! $this->fast_mode;

		$this->attr_map_cache   = array();
		$this->source_id_to_sku = array();
		$this->fetch_had_error  = false;

		$per_page = $this->cfg_per_page();
		$batch_limit = (int) $this->get_options()['sync_batch_limit']; // 0 = unlimited
		$page = $progress ? $progress['current_page'] + 1 : 1; // Resume from NEXT page (current already processed)
		$products_processed = $progress ? $progress['products_processed'] : 0;
		$resume_offset      = $progress ? (int) ( $progress['page_offset'] ?? 0 ) : 0; // items already done on the in-progress page
		$first_page         = true;   // Apply resume_offset only to the first page fetched in THIS batch
		// Time budget: stop+resume before any PHP/proxy timeout. Applies to dry runs too — they are
		// now backgrounded and batched, so they must respect the budget or a large-catalog dry run
		// would run past the FastCGI read timeout (31s on some hosts) and 500. 0 = no budget.
		$max_secs           = (int) $this->get_options()['max_batch_seconds'];
		$deadline           = ( $max_secs < 1 ) ? PHP_FLOAT_MAX : microtime( true ) + $max_secs;
		$pages_fetched      = 0;      // Track successful pages in this run
		$fetch_had_error    = false;  // Track if we hit a fetch error during THIS run

		// For batched syncs, track how many we process in THIS batch vs overall
		$batch_start_count = $products_processed;

		// Load total_pages from saved progress (persists across PHP processes)
		$total_pages = 0;
		if ( $progress && isset( $progress['total_pages'] ) && $progress['total_pages'] > 0 ) {
			$total_pages   = absint( $progress['total_pages'] );
			$this->last_total_pages = $total_pages; // keep alive across resume batches (B1 fix)
		}
		if ( $progress && ! empty( $progress['total_items'] ) ) {
			$this->last_total_items = absint( $progress['total_items'] ); // keep exact count across batches
		}

		// NOTE: /products/attributes is NOT requested here — not up front, and normally not at all.
		//
		// Every attribute the plugin has to resolve arrives inline with the product and variation
		// payloads (`id` + `name` + `slug`), and those two strings were the entire contents of the
		// map that endpoint used to provide. Its other fields (type, order_by, has_archives) are
		// never read — they are hardcoded when an attribute is created. So calling it was pure
		// overhead, and worse: it is guarded by `manage_product_terms` while /products only needs
		// `read_private_products`, so on a perfectly valid key it could 401 and abort the run.
		//
		// It survives only as a LAST-RESORT fallback, fetched lazily by map_global_attribute() if a
		// payload ever turns up with an attribute id it cannot resolve (see there). On a normal run
		// that request is never made, so a key without `manage_product_terms` syncs cleanly and
		// silently — no request, no 401, no warning on every run.
		$this->source_attributes      = array();
		$this->attributes_fetch_tried = false;

		// Run-start token, identical across every resume batch (set once in reset_run_result,
		// preserved by accumulate_run_result). Stamped onto products this run creates.
		$run_result           = get_option( self::SYNC_LAST_RESULT, array() );
		$this->run_started_at = ( is_array( $run_result ) && ! empty( $run_result['started_at'] ) )
			? (int) $run_result['started_at'] : time();

		$total_counted    = 0;
		$source_keys      = array();
		$seen_source_ids  = array(); // Dedup: track processed product IDs (Codex R3 fix)
		$hit_batch_limit  = false;   // Set true when the page loop stops at the batch limit

		do {
			$batch = $this->fetch_product_page( $page );
			if ( is_wp_error( $batch ) ) {
				$this->fetch_had_error  = true;
				$fetch_had_error         = true;
				$count                   = 0; // Prevent undefined $count warning after break
				$msg = 'Pobieranie strony ' . $page . ': ' . $batch->get_error_message();
				// COUNT IT. Without this the run ends 0/0/0 with "błędy: 0" and a green
				// "Zakończono" notice — a 401 from the source (bad keys, or a plain-HTTP source,
				// which WooCommerce rejects for Basic auth) looked exactly like a successful sync
				// of an empty catalog. The operator had no way to tell the difference.
				$stats['errors']++;
				$this->report_add( 'errors', array(
					'name'   => '—',
					'sku'    => '—',
					'type'   => '—',
					'reason' => $msg,
				) );
				$this->log( 'error', $msg );
				break;
			}

			$count = count( $batch );
			if ( 0 === $count ) {
				// No more products on this page. This can happen when total_products is an exact multiple of per_page.
				// We need to process any remaining buffered grouped products and complete sync before clearing progress.
				break; // Exit do-while, fall through to grouped processing + soft-delete below
			}

			$pages_fetched++;

			// Capture total pages + exact item count from WC REST API headers on first page
			if ( 1 === $page ) {
				if ( isset( $this->last_api_headers['X-WP-TotalPages'] ) ) {
					$total_pages = absint( $this->last_api_headers['X-WP-TotalPages'] );
					$this->last_total_pages = $total_pages;
				} elseif ( isset( $this->last_api_headers['x-wp-totalpages'] ) ) {
					$total_pages = absint( $this->last_api_headers['x-wp-totalpages'] );
					$this->last_total_pages = $total_pages;
				}
				// X-WP-Total = exact product count → accurate progress % (bar reaches 100%).
				if ( isset( $this->last_api_headers['X-WP-Total'] ) ) {
					$this->last_total_items = absint( $this->last_api_headers['X-WP-Total'] );
				} elseif ( isset( $this->last_api_headers['x-wp-total'] ) ) {
					$this->last_total_items = absint( $this->last_api_headers['x-wp-total'] );
				}
			} else if ( ! $total_pages && $this->last_total_pages > 0 ) {
				// Subsequent pages use the total captured from first page
				$total_pages = $this->last_total_pages;
			}

			$total_counted += $count;
			$this->log( 'info', sprintf( 'Strona %d/%d (%d produktów)', $page, $total_pages ?: '?', $count ) );

			// Exact total for progress % (bar reaches 100%); fallback overcounts last partial page.
			$true_total = $this->last_total_items > 0
				? $this->last_total_items
				: ( $total_pages > 0 ? $total_pages * $per_page : $total_counted );

			// On a resumed batch, skip items already processed on this (in-progress) page.
			$skip = $first_page ? min( $resume_offset, $count ) : 0;
			$first_page = false;
			$idx  = 0;
			$stop = false;
			foreach ( $batch as $p ) {
				if ( $idx < $skip ) { $idx++; continue; } // already done in an earlier batch
				$idx++;

				// Dedup by source ID or parent_id for variations/grouped children (Codex R3 fix)
				$dedup_key = ! empty( $p['parent_id'] ) ? (int) $p['parent_id'] : (int) ( $p['id'] ?? 0 );
				if ( $dedup_key && empty( $seen_source_ids[ $dedup_key ] ) ) {
					$seen_source_ids[ $dedup_key ] = true;
					$products_processed++;
				}

				// Track source keys
				$sku = isset( $p['sku'] ) ? trim( $p['sku'] ) : '';
				if ( '' !== $sku ) {
					$this->source_id_to_sku[ (int) $p['id'] ] = $sku;
					$source_keys[]                             = $sku;
				} else {
					$name = isset( $p['name'] ) ? trim( $p['name'] ) : '';
					if ( '' !== $name ) {
						$source_keys[] = $name;
					}
				}

				// Grouped products are handled in a dedicated FINAL pass (after every product across
				// all batches is synced), so their children resolve no matter which batch they're on
				// and no matter the order (B3 fix). Their source key was already tracked above.
				if ( isset( $p['type'] ) && 'grouped' === $p['type'] ) {
					continue;
				}
				$this->process_single_product( $p, $dry_run, $stats );

				// Time-box + batch-limit at ITEM granularity. Save progress and stop+resume BEFORE any
				// PHP timeout, so no batch is ever killed and progress always advances. Applies to dry
				// runs too now — a backgrounded dry run is batched exactly like a real one, so it never
				// exceeds the FastCGI/proxy timeout on a large catalog. (Dry writes nothing; only the
				// progress transient is saved, which lets the run resume.)
				{
					$over = ( microtime( true ) >= $deadline )
						|| ( $batch_limit > 0 && ( $products_processed - $batch_start_count ) >= $batch_limit );
					if ( $over ) {
						if ( $idx >= $count ) {
							$this->save_sync_progress( $page, $products_processed, $true_total, 0 );      // page finished
						} else {
							$this->save_sync_progress( $page - 1, $products_processed, $true_total, $idx ); // mid-page (offset)
						}
						$still_batching  = true;
						$hit_batch_limit = true; // handled after the loop: keep progress, resume scheduled
						if ( wp_next_scheduled( self::RESUME_HOOK ) ) {
							wp_clear_scheduled_hook( self::RESUME_HOOK );
						}
						wp_schedule_single_event( time() + 30, self::RESUME_HOOK );
						$this->log( 'info', sprintf( 'Batch zatrzymany (strona %d, poz %d/%d, %d prod.) — wznowienie zaplanowane.',
							$page, $idx, $count, $products_processed - $batch_start_count ) );
						$stop = true;
						break;
					}
				}
			}

			unset( $batch );
			if ( $stop ) {
				break; // exit the page loop; grouped handled in the final pass, key accumulation below
			}

			// Page fully processed within the time budget → page-boundary save (offset 0), advance.
			if ( ! $dry_run ) {
				$this->save_sync_progress( $page, $products_processed, $true_total, 0 );
			}

		$page++;
		} while ( $count === $per_page );

		// AN EMPTY SOURCE IS NOT A SUCCESSFUL SYNC — IT IS A RED FLAG.
		//
		// HTTP 200 with an empty list is what you get when the API key belongs to a user who
		// cannot see products (WooCommerce answers "no products", not "forbidden"), when the
		// source URL points at the wrong site, or when the status filter matches nothing. All
		// three look identical to a genuinely empty catalog.
		//
		// Treating it as success is dangerous, not just unhelpful: a zero-product view of the
		// source means "everything was deleted upstream", so force_full_sync / deletion_mode=hard
		// would wipe the ENTIRE local catalog on the strength of a bad key. So: count it as an
		// error and mark the fetch unsafe, which blocks every deletion path (same guard used for
		// a failed fetch and for the source-key cap, v0.9.19).
		//
		// A legitimately empty source therefore syncs nothing and deletes nothing. That is the
		// correct trade: refusing to act on an empty view can be undone, deleting a catalog cannot.
		// `1 === $page` = a FRESH run that fetched nothing: the zero-count branch breaks before
		// $page++, and a resume batch always starts at page >= 2 (see $page init above). A resume
		// batch legitimately reading 0 past the end of a shrunken catalog must not trip this.
		if ( 0 === $total_counted && ! $fetch_had_error && 1 === $page ) {
			$this->fetch_had_error = true;
			$fetch_had_error       = true;
			$msg = 'Źródło zwróciło 0 produktów (HTTP 200). Nic nie zsynchronizowano i — dla bezpieczeństwa — nic nie usunięto. '
				. 'Najczęstsze przyczyny: klucz API należy do użytkownika bez dostępu do produktów, zły URL źródła, '
				. 'albo żaden produkt nie ma statusu „publish".';
			$stats['errors']++;
			$this->report_add( 'errors', array(
				'name'   => '—',
				'sku'    => '—',
				'type'   => '—',
				'reason' => $msg,
			) );
			$this->log( 'error', $msg );
		}

		// Record this batch's source keys so soft-delete on the final batch sees the whole
		// catalog. Persist for real runs before any early return below (dry runs are single-pass
		// and use the in-memory keys directly). $fetch_had_error marks the collected set unsafe.
		if ( ! $dry_run && $this->deletion_enabled() ) {
			$this->accumulate_source_keys( $source_keys, $total_counted, $fetch_had_error );
		}

		// Stopped at the batch limit — progress preserved, resume already scheduled.
		if ( $hit_batch_limit ) {
			$this->log( 'info', 'Batch zatrzymany (limit czasu/produktów) — postęp zapisany, wznowienie w toku.' );
			return $stats;
		}

// If we completed all pages, clear progress (full sync done)
		if ( $fetch_had_error && 0 !== $total_pages ) {
			// Fetch error during sync — preserve progress to resume from where we left off.
			$still_batching = true; // Prevent caller's finally from clearing progress (Codex R5 fix #2)
			$this->log( 'warning', sprintf( 'Błąd pobierania na stronie %d — zachowuję postęp, synchronizacja wymaga wznowienia.', $page ) );
			return $stats;
		} else if ( $count < $per_page && 0 !== $total_pages ) {
			$this->log( 'info', sprintf( 'Wszystkie %d/%d strony przetworzone.', $page - 1, $total_pages ) );
			// Clear progress — sync is truly done
		} else if ( $fetch_had_error && $pages_fetched === 0 ) {
			// Fetch error on first page attempt with no prior progress — clear stale progress to avoid infinite resume loop
			$this->log( 'warning', 'Błąd pobierania na pierwszej stronie wznowienia bez wcześniejszego postępu — usuwam stary postęp.' );
			$still_batching = false;
		} else if ( $count < $per_page && 0 === $total_pages ) {
			// total_pages not reported by API but we got a partial page — sync is complete even without X-WP-TotalPages
			if ( $pages_fetched >= 1 ) {
				$this->log( 'info', 'Brak nagłówka X-WP-TotalPages z API, ale otrzymano niepełną stronę — synchronizacja zakończona.' );
				// Fall through to soft-delete + clear progress
			} else {
				// No pages fetched and no totalPages — likely empty source or API issue. Don't clear progress for resumed batch.
				return $stats;
			}
		} else {
			// Still more pages to fetch but we hit batch limit or resumed with prior progress — DON'T clear progress yet
			$this->log( 'info', sprintf( 'Limit batchu osiągnięty lub wznowienie — pozostawiam postęp do kontynuacji.' ) );
			return $stats; // Exit early, don't reach the clear at bottom
		}

		if ( function_exists( 'gc_collect_cycles' ) ) gc_collect_cycles();

		// Grouped products — FINAL pass (B3). Now that every simple/variable product across all
		// batches is synced, fetch grouped products from the source and upsert them; their children
		// resolve by _wps_source_id regardless of which batch they were in, or their order.
		// Skipped in fast mode: grouped products carry no own price/stock (derived from children),
		// so a field-refresh has nothing to do for them.
		if ( ! $this->fast_mode ) {
			$this->sync_grouped_products( $dry_run, $stats );
		}

		// Force-full sync: wipe local products removed from the source, BEFORE soft-delete cleanup.
		// Only runs after ALL source fetches completed without errors — a partial view could wrongly
		// wipe valid products. Crucially, we delete only products NOT re-synced during THIS run:
		// every product created/updated this run is re-stamped (mark_synced → _wps_synced = time()),
		// so anything still carrying a timestamp older than the run start has disappeared from the
		// source. This is safe across batched/resumed syncs — unlike a blanket "all synced products"
		// delete, which erased the very products we just imported when a run completed in one batch.
		if ( $force_full && ! $dry_run && ! $this->fetch_had_error ) {
			// Run-start baseline: persisted in SYNC_LAST_RESULT (survives across resume batches) and
			// set just before the page loop on the first batch (reset_run_result). Every product
			// synced this run — on any batch — carries _wps_synced >= this value.
			$run_result = get_option( self::SYNC_LAST_RESULT, array() );
			$run_start  = ( is_array( $run_result ) && ! empty( $run_result['started_at'] ) ) ? (int) $run_result['started_at'] : 0;
			if ( $run_start < 1 ) {
				// Fail safe: without a reliable baseline we can't distinguish fresh from stale
				// products, so skip the wipe entirely rather than risk deleting freshly-synced items.
				$this->log( 'warning', 'PEŁNA SYNCHRONIZACJA: brak znacznika startu przebiegu — pomijam usuwanie (bezpiecznik).' );
			} else {
				global $wpdb;
				$ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					 WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) < %d",
					self::META_SYNCED,
					$run_start
				) );
				$ids = array_unique( array_map( 'absint', $ids ) );
				if ( ! empty( $ids ) ) {
					$this->log( 'info', sprintf( 'PEŁNA SYNCHRONIZACJA: usuwanie %d lokalnych produktów nieobecnych w źródle...', count( $ids ) ) );
					foreach ( $ids as $pid ) {
						$p    = wc_get_product( $pid );
						$name = $p ? $p->get_name() : '';
						$sku  = $p ? $p->get_sku() : '';
						$this->report_add( 'hard_deleted', array(
							'name'   => $name,
							'sku'    => $sku,
							'type'   => $p ? $p->get_type() : '',
							'reason' => 'pełna synchronizacja — brak w źródle',
						) );
						if ( $p ) {
							$p->delete( true );
						} else {
							wp_delete_post( $pid, true );
						}
						$this->log( 'info', sprintf( 'Usunięto lokalny produkt ID=%d (full sync)', $pid ) );
					}
					$this->log( 'info', sprintf( 'Usunięto %d lokalnych produktów (pełna synchronizacja).', count( $ids ) ) );
				} else {
					$this->log( 'info', 'PEŁNA SYNCHRONIZACJA: brak produktów do usunięcia — wszystkie obecne w źródle.' );
				}
			}
		}

		// Handle products removed from source (soft/hard per deletion_mode). We only reach here
		// when the sync is COMPLETE (all pages processed across however many batches). Compare the
		// full catalog view against local synced products. Dry runs are single-pass → use in-memory
		// keys. Real runs → use the keys accumulated across every batch (skipped if any batch had a
		// fetch error, i.e. an incomplete view).
		if ( $this->deletion_enabled() ) {
			if ( $dry_run ) {
				$this->soft_delete_missing( array_unique( $source_keys ), $total_counted, true );
			} else {
				$collected = get_transient( self::SYNC_KEYS_TRANSIENT );
				if ( is_array( $collected ) && empty( $collected['had_error'] ) && ! empty( $collected['keys'] ) ) {
					$this->soft_delete_missing( array_keys( $collected['keys'] ), (int) $collected['count'], false );
				} else {
					$this->log( 'warning', 'Soft-delete pominięty: niekompletny widok źródła (błąd pobierania w którymś batchu) lub brak danych.' );
				}
				delete_transient( self::SYNC_KEYS_TRANSIENT );
			}
		}

		// Final progress update for completed sync
		$this->clear_sync_progress();

		$this->log( 'info', sprintf( 'Pobrano %d produktów, %d atrybutów globalnych', $total_counted, count( $this->source_attributes ) ) );

		$this->log( 'info', sprintf(
			'=== Koniec: utworzono=%d, zaktualizowano=%d, pominięto=%d, błędy=%d ===',
			$stats['created'], $stats['updated'], $stats['skipped'], $stats['errors']
		) );

		return $stats;
	}

	private function process_single_product( array $p, $dry_run, &$stats ) {
		$base = array(
			'name' => (string) ( $p['name'] ?? '?' ),
			'sku'  => (string) ( $p['sku'] ?? '' ),
			'type' => (string) ( $p['type'] ?? 'simple' ),
		);

		$status = $p['status'] ?? 'publish';
		if ( ! $this->should_sync_status( $status ) ) {
			$stats['skipped']++;
			$reason = sprintf( "status '%s' w źródle (synchronizowane tylko 'publish')", $status );
			$this->report_add( 'skipped', $base + array( 'reason' => $reason ) );
			$this->log( 'info', sprintf( "Pominięto '%s' (SKU=%s): %s", $base['name'], $base['sku'], $reason ) );
			return;
		}

		$this->last_match_method  = '';
		$this->last_skip_reason   = '';
		$this->last_image_failed     = false;
		$this->last_variation_failed = false;
		try {
			$result = $this->dispatch_upsert( $p, $dry_run );
			if ( isset( $stats[ $result ] ) ) {
				$stats[ $result ]++;
			}
			// An image we could not fetch is missing product data — count it, so the summary
			// (and the admin notice, and the parity of what an operator believes) reflects it.
			// The product itself is still created/updated and still mark_synced()'d: it must NOT
			// look un-refreshed to force-full, or a transient image failure would get the whole
			// product deleted on the next run.
			if ( $this->last_image_failed ) {
				$stats['errors']++;
				$this->report_add( 'errors', $base + array( 'reason' => 'nie pobrano obrazów (produkt zachowany, obrazy bez zmian)' ) );
			}
			if ( $this->last_variation_failed ) {
				$stats['errors']++;
				$this->report_add( 'errors', $base + array( 'reason' => 'część wariacji nie została zsynchronizowana (szczegóły w logu)' ) );
			}
			if ( 'created' === $result ) {
				// Tag the product with the run that created it, so "undo last sync" can trash
				// exactly this run's creations. Uses the same $result the report keys off, so it
				// can never disagree with the "created" count.
				if ( $this->last_saved_id ) {
					update_post_meta( $this->last_saved_id, self::META_CREATED_RUN, $this->run_started_at ?: time() );
				}
				$this->report_add( 'created', $base + array( 'how' => 'nowy produkt' ) );
			} elseif ( 'updated' === $result ) {
				$this->report_add( 'updated', $base + array( 'how' => $this->last_match_method ? 'dopasowano po ' . $this->last_match_method : 'zaktualizowano' ) );
			} elseif ( 'skipped' === $result ) {
				$this->report_add( 'skipped', $base + array( 'reason' => $this->last_skip_reason ?: 'pominięto' ) );
			}
		} catch ( \Throwable $e ) {
			$stats['errors']++;
			$this->report_add( 'errors', $base + array( 'reason' => $e->getMessage() ) );
			$this->log( 'error', sprintf(
				"Błąd dla '%s' (SKU=%s): %s",
				$base['name'],
				$base['sku'],
				$e->getMessage()
			) );
		}
	}

	private function dispatch_upsert( array $p, $dry_run ) {
		$type = $p['type'] ?? 'simple';
		if ( ! $this->type_enabled( $type ) ) {
			$this->last_skip_reason = sprintf( "typ '%s' wyłączony w ustawieniach", $type );
			return 'skipped';
		}
		switch ( $type ) {
			case 'simple':
				return $this->upsert_simple( $p, $dry_run );
			case 'variable':
				return $this->upsert_variable( $p, $dry_run );
			case 'grouped':
				return $this->upsert_grouped( $p, $dry_run );
			default:
				$this->last_skip_reason = sprintf( "typ '%s' nieobsługiwany", $type );
				$this->log( 'warning', sprintf( "Pominięto '%s' – %s.", $p['name'] ?? '?', $this->last_skip_reason ) );
				return 'skipped';
		}
	}

	private function require_sku( array $p ) {
		$sku = isset( $p['sku'] ) ? trim( $p['sku'] ) : '';
		return $sku;
	}

/** Resolve SKU → product ID via direct SQL — bypasses all WC caching layers
	 * (deferred indexing in v9+, object cache, etc.). Mirrors WooCommerce\'s own
	 * sku lookup semantics: only active products/variation rows. */
	private static function sku_to_id( $sku ) {
		global $wpdb;
		if ( '' === trim( $sku ) ) {
			return 0;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				 WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
				   AND p.post_type IN ('product','product_variation')
				   AND p.post_status <> 'trash'
			   ORDER BY p.ID ASC LIMIT 1",
				trim( $sku )
			)
		);
		return $id ? absint( $id ) : 0;
	}

	/** Resolve local product ID by source WooCommerce ID. */
	private static function source_id_to_local( int $src_id ) {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s
			   ORDER BY post_id ASC LIMIT 1",
				self::META_SOURCE_ID,
				(string) $src_id
			)
		);
		return $id ? absint( $id ) : 0;
	}

/**
	 * Szuka istniejącego produktu na celcu: najpierw po SKU, potem po source_id,
	 * na końcu po nazwie (fallback).
	 */
	private function find_existing_product( array $p ) {

		$this->last_match_method = '';
		$sku = $this->require_sku( $p );
		if ( '' !== $sku ) {
			$id = self::sku_to_id( $sku );
			if ( $id ) {
				$this->last_match_method = 'SKU';
				return $id;
			}
		}

		// 2) Lookup po source_id z bazy — 100% trafne.
		if ( ! empty( $p['id'] ) ) {
			$src_id = absint( $p['id'] );
			$id     = self::source_id_to_local( $src_id );
			if ( $id ) {
				$this->last_match_method = 'ID źródła';
				return $id;
			}
		}

		// 3) Fallback: szukaj po nazwie.
		//    Tylko jeśli znaleziony produkt jest nieprzypisany (brak _wps_source_id)
		//    lub przypisany do tego samego źródła — w przeciwnym razie to inny
		//    produkt o tej samej nazwie, nie nasz.
		$name = isset( $p['name'] ) ? trim( $p['name'] ) : '';
		if ( '' !== $name && ! empty( $p['id'] ) ) {
			$src_id  = absint( $p['id'] );
			// 'title' (exact match), NOT 'post_title' — WP_Query has no 'post_title' arg, so it was
			// silently IGNORED: the query returned products regardless of name. With one candidate
			// on the target that meant every unmatched source product falsely matched it (wrong
			// product overwritten); with many, it always saw 2 and never matched (SKU-less/renamed
			// products duplicated instead). The whole name fallback never actually matched by name.
			$found   = get_posts( array(
				'post_type'      => 'product',
				'title'          => $name,
				'post_status'    => 'publish',
				'posts_per_page' => 2,
				'fields'         => 'ids',
			) );
			if ( $found && count( $found ) === 1 ) {
				$pid = (int) $found[0];
				$existing_src = get_post_meta( $pid, self::META_SOURCE_ID, true );
				// Dopuszczamy jeśli produkt jest nieprzypisany lub należy do tego samego źródła.
				if ( '' === $existing_src || (string) $existing_src === (string) $src_id ) {
					$this->last_match_method = 'nazwę';
					return $pid;
				}
			}
		}

		return 0;
	}

	private function apply_common_fields( $product, array $p ) {
		// Name + status are always managed (identity + publish state). Other fields are gated
		// by the "co synchronizować → pola" setting so local edits can be preserved.
		$product->set_name( (string) ( $p['name'] ?? '' ) );
		$status = $p['status'] ?? 'publish';
		$product->set_status( in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish' );
		if ( $this->field_on( 'description' ) ) {
			$product->set_description( (string) ( $p['description'] ?? '' ) );
			$product->set_short_description( (string) ( $p['short_description'] ?? '' ) );
		}
		if ( $this->field_on( 'categories' ) && ! empty( $p['categories'] ) && is_array( $p['categories'] ) ) {
			$ids = $this->resolve_category_ids( $p['categories'] );
			if ( $ids ) {
				$product->set_category_ids( $ids );
			}
		}
	}

	private function apply_physical( $obj, array $src ) {
		if ( ! $this->field_on( 'dimensions' ) ) {
			return;
		}
		if ( isset( $src['weight'] ) ) {
			$obj->set_weight( (string) $src['weight'] );
		}
		if ( ! empty( $src['dimensions'] ) && is_array( $src['dimensions'] ) ) {
			if ( isset( $src['dimensions']['length'] ) ) {
				$obj->set_length( (string) $src['dimensions']['length'] );
			}
			if ( isset( $src['dimensions']['width'] ) ) {
				$obj->set_width( (string) $src['dimensions']['width'] );
			}
			if ( isset( $src['dimensions']['height'] ) ) {
				$obj->set_height( (string) $src['dimensions']['height'] );
			}
		}
	}

	private function apply_stock( $obj, array $src ) {
		if ( ! $this->field_on( 'stock' ) ) {
			return;
		}
		$manage = ! empty( $src['manage_stock'] );
		$obj->set_manage_stock( $manage );
		if ( $manage && isset( $src['stock_quantity'] ) ) {
			$obj->set_stock_quantity( wc_stock_amount( $src['stock_quantity'] ) );
		}
		if ( ! empty( $src['stock_status'] ) ) {
			$obj->set_stock_status( $src['stock_status'] );
		}
	}

	private function ensure_product_type( $existing_id, $wanted_class, $wanted_term ) {
		$product = $existing_id ? wc_get_product( $existing_id ) : null;
		if ( $product && ! is_a( $product, $wanted_class ) ) {
			// Type is changing. If the product WAS variable and the new type is not, its
			// variation child posts would be left orphaned in the DB (wp_posts rows with no
			// parent product-type). Delete them before switching type.
			if ( 'variable' !== $wanted_term && $product->is_type( 'variable' ) ) {
				foreach ( $product->get_children() as $vid ) {
					$child = wc_get_product( $vid );
					if ( $child ) {
						$child->delete( true );
					} else {
						wp_delete_post( $vid, true );
					}
					$this->log( 'info', sprintf( 'Usunięto osieroconą wariację ID=%d (zmiana typu produktu %d → %s)', $vid, (int) $existing_id, $wanted_term ) );
				}
			}
			wp_set_object_terms( $existing_id, $wanted_term, 'product_type' );
			$product = new $wanted_class( $existing_id );
		}
		if ( ! $product ) {
			$product = new $wanted_class();
		}
		return $product;
	}

	/** Aktualizuje istniejący produkt z danymi ze źródła. */
	private function update_existing_product( $existing_id, $wanted_class, $wanted_term, array $p, $dry_run, $sku ) {
		if ( $dry_run ) {
			$this->log( 'info', sprintf( '[DRY] UPDATE %s: %s (%s=%s)', $wanted_term, $p['name'], $sku ? 'SKU' : 'nazwa', $sku ?: $p['name'] ) );
			return 'updated';
		}

		$this->writing_update = true; // gate fields to preserve local edits (#2)
		$product = $this->ensure_product_type( $existing_id, $wanted_class, $wanted_term );
		$this->apply_common_fields( $product, $p );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( 'WC_Product_Simple' === $wanted_class ) {
			if ( $this->field_on( 'price' ) ) {
				$product->set_regular_price( isset( $p['regular_price'] ) ? $this->modify_price( $p['regular_price'] ) : '' );
				$product->set_sale_price( isset( $p['sale_price'] ) ? $this->modify_price( $p['sale_price'] ) : '' );
			}
			$this->apply_stock( $product, $p ); // self-gated by field_on('stock')
		} elseif ( 'WC_Product_Variable' === $wanted_class ) {
			if ( $this->field_on( 'stock' ) && ! empty( $p['stock_status'] ) ) {
				$product->set_stock_status( $p['stock_status'] );
			}
			if ( $this->field_on( 'attributes' ) ) {
				$product->set_attributes( $this->build_parent_attributes( $p['attributes'] ?? array() ) );
			}
		}
		$this->apply_physical( $product, $p );

		$id = $product->save();
		if ( ! $id ) {
			throw new \RuntimeException( 'save() zwróciło 0' );
		}
		$this->last_saved_id = (int) $id;
		update_post_meta( $id, self::META_SOURCE_ID, (int) $p['id'] );
		// Images on UPDATE too (incremental — only new/changed download). Gated by the "images"
		// field so admins who manage images locally aren't overwritten.
		if ( $this->field_on( 'images' ) && ! empty( $p['images'] ) ) {
			$this->sync_product_images( $product, $p['images'] );
		}
		// #1: re-sync variations on UPDATE too (previously only on create), so variation
		// price/stock changes and added/removed variations in the source are reflected.
		if ( 'WC_Product_Variable' === $wanted_class ) {
			// Roll up the parent's aggregates (wc_product_meta_lookup min/max price + stock
			// status, used for catalog sort-by-price and the price-filter widget) ONLY when a
			// variation was added/removed or its price/stock changed. Saving individual
			// variations updates the live display range but NOT the parent lookup row, so this
			// resync is required for correctness; skipping it when nothing changed keeps the
			// no-op update fast (the common case).
			if ( $this->sync_variations( $id, (int) $p['id'] ) ) {
				WC_Product_Variable::sync( $id );
			}
		}
		$this->mark_synced( $id );
		$this->log( 'info', sprintf( 'Zaktualizowano %s: %s (ID=%d)', $wanted_term, $p['name'], $id ) );
		return 'updated';
	}

	/** Tworzy nowy produkt i zapisuje go na celzie. */
	private function create_new_product( $wanted_class, $wanted_term, array $p, $dry_run, $sku ) {
		// Fast field-refresh is update-only: never create products the daily full sync would create.
		if ( $this->fast_mode ) {
			$this->last_skip_reason = 'szybka synchronizacja: nowe produkty pomijane (tylko aktualizacja)';
			return 'skipped';
		}
		if ( $dry_run ) {
			if ( 'WC_Product_Variable' === $wanted_class ) {
				$vars = $this->fetch_variations( (int) $p['id'] );
				$this->log( 'info', sprintf( '[DRY] CREATE %s: %s (SKU=%s, wariacji=%d)', $wanted_term, $p['name'], $sku ?: '(brak)', count( $vars ) ) );
			} else {
				$this->log( 'info', sprintf( '[DRY] CREATE %s: %s (SKU=%s)', $wanted_term, $p['name'], $sku ?: '(brak)' ) );
			}
			return 'created';
		}

		$this->writing_update = false; // creating → import all fields (#2)
		$product = new $wanted_class();
		$this->apply_common_fields( $product, $p );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( 'WC_Product_Simple' === $wanted_class ) {
			if ( $this->field_on( 'price' ) ) {
				$product->set_regular_price( isset( $p['regular_price'] ) ? $this->modify_price( $p['regular_price'] ) : '' );
				$product->set_sale_price( isset( $p['sale_price'] ) ? $this->modify_price( $p['sale_price'] ) : '' );
			}
			$this->apply_stock( $product, $p ); // self-gated by field_on('stock')
		} elseif ( 'WC_Product_Variable' === $wanted_class ) {
			if ( $this->field_on( 'stock' ) && ! empty( $p['stock_status'] ) ) {
				$product->set_stock_status( $p['stock_status'] );
			}
			if ( $this->field_on( 'attributes' ) ) {
				$product->set_attributes( $this->build_parent_attributes( $p['attributes'] ?? array() ) );
			}
		}
		$this->apply_physical( $product, $p );

		$id = $product->save();
		if ( ! $id ) {
			throw new \RuntimeException( 'save() zwróciło 0' );
		}
		$this->last_saved_id = (int) $id;
		update_post_meta( $id, self::META_SOURCE_ID, (int) $p['id'] );
		if ( $this->field_on( 'images' ) && ! empty( $p['images'] ) ) {
			$this->sync_product_images( $product, $p['images'] );
		}
		if ( 'WC_Product_Variable' === $wanted_class ) {
			$this->sync_variations( $id, (int) $p['id'] );
			// Never persist a NEW variable product with zero variations — that is the broken,
			// unpurchasable "empty parent" from issue #15. It happens when the source /variations
			// endpoint errors (401/timeout) or returns nothing. Remove what we just created (parent +
			// any partially-written children) and fail loudly, so it is counted as an error and
			// retried next run instead of leaving a dead product in the catalog.
			if ( $this->variations_fetch_error || 0 === $this->last_variation_count ) {
				$this->delete_product_with_children( $id );
				throw new \RuntimeException( $this->variations_fetch_error
					? 'Nie udało się pobrać wariacji ze źródła — produkt wariantowy NIE został utworzony (uniknięto pustego produktu).'
					: 'Źródłowy produkt wariantowy nie ma żadnych wariacji — NIE utworzono (uniknięto pustego produktu).' );
			}
			WC_Product_Variable::sync( $id );
		}

		$this->mark_synced( $id );
		$this->log( 'info', sprintf( 'Utworzono %s: %s (ID=%d, SKU=%s)', $wanted_term, $p['name'], $id, $sku ?: '(brak)' ) );
		return 'created';
	}

	/* =====================================================================
	 *  SIMPLE
	 * ================================================================== */

	private function upsert_simple( array $p, $dry_run ) {
		$sku   = $this->require_sku( $p );
		$found = $this->find_existing_product( $p );
		if ( '' !== $sku && $found ) {
			return $this->update_existing_product( $found, 'WC_Product_Simple', 'simple', $p, $dry_run, $sku );
		} elseif ( '' === $sku && $found ) {
			return $this->update_existing_product( $found, 'WC_Product_Simple', 'simple', $p, $dry_run, '' );
		}
		return $this->create_new_product( 'WC_Product_Simple', 'simple', $p, $dry_run, $sku );
	}

	/* =====================================================================
	 *  VARIABLE
	 * ================================================================== */

	private function upsert_variable( array $p, $dry_run ) {
		$sku   = $this->require_sku( $p );
		$found = $this->find_existing_product( $p );
		if ( '' !== $sku && $found ) {
			return $this->update_existing_product( $found, 'WC_Product_Variable', 'variable', $p, $dry_run, $sku );
		} elseif ( '' === $sku && $found ) {
			return $this->update_existing_product( $found, 'WC_Product_Variable', 'variable', $p, $dry_run, '' );
		}
		return $this->create_new_product( 'WC_Product_Variable', 'variable', $p, $dry_run, $sku );
	}

	private function build_parent_attributes( array $source_attrs ) {
		$out = array();
		$pos = 0;
		foreach ( $source_attrs as $a ) {
			$attr = new WC_Product_Attribute();

			if ( ! empty( $a['id'] ) && (int) $a['id'] > 0 ) {
				$this->learn_attribute( $a );
				$map = $this->map_global_attribute( (int) $a['id'] );
				if ( ! $map ) {
					// Do NOT skip silently. The old `continue` dropped the attribute, which for a
					// variable product means rebuilding it with no attributes at all — a silent
					// wipe. Fail this product instead: it is reported as an error and left alone.
					throw new \RuntimeException( sprintf(
						'Nie udało się odwzorować atrybutu globalnego (ID=%d, nazwa=%s) — produkt pominięty, aby nie skasować mu atrybutów.',
						(int) $a['id'],
						(string) ( $a['name'] ?? '?' )
					) );
				}
				$term_ids = array();
				foreach ( ( $a['options'] ?? array() ) as $opt ) {
					$tid = $this->ensure_term( $map['taxonomy'], $opt );
					if ( $tid ) {
						$term_ids[] = $tid;
					}
				}
				$attr->set_id( $map['attribute_id'] );
				$attr->set_name( $map['taxonomy'] );
				$attr->set_options( $term_ids );
			} else {
				$attr->set_id( 0 );
				$attr->set_name( (string) ( $a['name'] ?? '' ) );
				$attr->set_options( $a['options'] ?? array() );
			}

			$attr->set_position( isset( $a['position'] ) ? (int) $a['position'] : $pos );
			$attr->set_visible( ! empty( $a['visible'] ) );
			$attr->set_variation( ! empty( $a['variation'] ) );
			$out[] = $attr;
			$pos++;
		}
		return $out;
	}

	/** Learn a global attribute from a product/variation payload, when /products/attributes was not
	 *  readable. WooCommerce ships `id`, `name` and `slug` inline with every attribute it returns,
	 *  and those two strings are the entire contents of the map we would have fetched. Older stores
	 *  may omit `slug`; sanitize_title(name) reproduces it (map_global_attribute already falls back
	 *  that way). No-op when the id is already known, so the endpoint's data always wins. */
	private function learn_attribute( array $a ) {
		$id = isset( $a['id'] ) ? (int) $a['id'] : 0;
		if ( $id <= 0 || isset( $this->source_attributes[ $id ] ) ) {
			return;
		}
		$slug = isset( $a['slug'] ) ? preg_replace( '/^pa_/', '', (string) $a['slug'] ) : '';
		$name = isset( $a['name'] ) ? (string) $a['name'] : $slug;
		if ( '' === $slug && '' === $name ) {
			return; // genuinely unresolvable — the caller turns this into a reported error
		}
		$this->source_attributes[ $id ] = array( 'name' => $name, 'slug' => $slug );
	}

	private function map_global_attribute( $source_attr_id ) {
		$src = $this->source_attributes[ $source_attr_id ] ?? null;

		// Last resort only. The payloads carry name+slug, so on any normal catalog this never
		// fires and /products/attributes is never requested. It exists for the odd store whose
		// payload omits both (very old WooCommerce); fetched at most once per run, and a failure
		// here is not fatal — the caller reports the one product it could not map.
		if ( ! $src && ! $this->attributes_fetch_tried ) {
			$this->attributes_fetch_tried = true;
			$this->log( 'info', sprintf(
				'Atrybut globalny ID=%d nie ma nazwy/sluga w payloadzie — sięgam po /products/attributes.',
				(int) $source_attr_id
			) );
			$this->source_attributes = $this->fetch_source_attributes() + $this->source_attributes;
			$src = $this->source_attributes[ $source_attr_id ] ?? null;
		}

		if ( ! $src ) {
			return null;
		}
		$bare = $src['slug'] ?: sanitize_title( $src['name'] );
		if ( isset( $this->attr_map_cache[ $bare ] ) ) {
			return $this->attr_map_cache[ $bare ];
		}

		$taxonomy = wc_attribute_taxonomy_name( $bare );
		$attr_id  = wc_attribute_taxonomy_id_by_name( $bare );

		if ( ! $attr_id ) {
			$attr_id = wc_create_attribute( array(
				'name'         => $src['name'],
				'slug'         => $bare,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			) );
			if ( is_wp_error( $attr_id ) ) {
				$this->log( 'warning', 'Nie utworzono atrybutu ' . $bare . ': ' . $attr_id->get_error_message() );
				return null;
			}
			delete_transient( 'wc_attribute_taxonomies' );
		}

		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
				apply_filters( 'woocommerce_taxonomy_args_' . $taxonomy, array(
					'hierarchical' => true,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				) )
			);
		}

		$this->attr_map_cache[ $bare ] = array( 'taxonomy' => $taxonomy, 'attribute_id' => (int) $attr_id );
		return $this->attr_map_cache[ $bare ];
	}

	private function ensure_term( $taxonomy, $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$new = wp_insert_term( $name, $taxonomy );
		if ( ! is_wp_error( $new ) ) {
			return (int) $new['term_id'];
		}
		$data = $new->get_error_data();
		if ( is_array( $data ) && isset( $data['term_id'] ) ) {
			return (int) $data['term_id'];
		}
		$this->log( 'warning', "Nie utworzono terminu '{$name}' w {$taxonomy}: " . $new->get_error_message() );
		return 0;
	}

	/** Permanently delete a product and every variation child, leaving no orphaned rows. */
	private function delete_product_with_children( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			foreach ( $product->get_children() as $child_id ) {
				wp_delete_post( $child_id, true );
			}
			$product->delete( true );
		} else {
			wp_delete_post( $product_id, true );
		}
	}

	private function sync_variations( $target_parent_id, $source_parent_id ) {
		$this->variations_fetch_error = false;
		$this->last_variation_count   = 0;
		$source_vars = $this->fetch_variations( $source_parent_id );
		// A failed variations fetch (401/timeout) used to be silent: no children written, but the run
		// still counted the parent as a clean sync — shipping an empty, unpurchasable variable product
		// (issue #15). Treat it like a failed variation so it is counted as an error and surfaced.
		if ( $this->variations_fetch_error ) {
			$this->last_variation_failed = true;
		}
		$parent      = wc_get_product( $target_parent_id );
		if ( ! $parent ) {
			return false;
		}

		$by_sku = array();
		$by_sig = array();
		foreach ( $parent->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			if ( $v->get_sku() ) {
				$by_sku[ $v->get_sku() ] = $vid;
			}
			$by_sig[ $this->signature( $v->get_attributes() ) ] = $vid;
		}

		// Track whether anything changed that requires re-rolling the PARENT aggregates
		// (wc_product_meta_lookup min/max price + stock status). A variation added, removed,
		// or a price/stock change on an existing one all invalidate the parent's rollup.
		$rollup_dirty = false;
		// Props whose change must propagate to the parent's price/stock rollup. 'status' is
		// included because WC's sync_price query only counts publish variations — a publish<->
		// private (or draft) flip changes which children feed the parent min/max price.
		$rollup_props = array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'stock_quantity', 'stock_status', 'manage_stock', 'status' );

		$kept = array();
		foreach ( $source_vars as $sv ) {
			$vid = null; // resolved match for THIS source variation; reset before any throw point so
			             // the catch never keeps a stale id from a previous iteration.
			try {
				$svsku   = isset( $sv['sku'] ) ? trim( $sv['sku'] ) : '';
				$attrs   = $this->build_variation_attributes( $sv['attributes'] ?? array() );
				$sig     = $this->signature( $attrs );

				if ( $svsku && isset( $by_sku[ $svsku ] ) ) {
					$vid = $by_sku[ $svsku ];
				} elseif ( isset( $by_sig[ $sig ] ) ) {
					$vid = $by_sig[ $sig ];
				}

				$is_update = (bool) $vid;
				// Fast field-refresh is update-only: skip source variations with no local match
				// (adding them is a structural change left to the daily full sync).
				if ( ! $is_update && $this->fast_mode ) {
					continue;
				}
				$variation = $vid ? wc_get_product( $vid ) : new WC_Product_Variation();
				if ( ! $variation ) {
					$variation = new WC_Product_Variation();
				}
				$variation->set_parent_id( $target_parent_id );
				if ( $svsku ) {
					$variation->set_sku( $svsku );
				}
				$variation->set_status( ( $sv['status'] ?? 'publish' ) === 'private' ? 'private' : 'publish' );
				if ( $this->field_on( 'price' ) ) {
					$variation->set_regular_price( isset( $sv['regular_price'] ) ? $this->modify_price( $sv['regular_price'] ) : '' );
					$variation->set_sale_price( isset( $sv['sale_price'] ) ? $this->modify_price( $sv['sale_price'] ) : '' );
				}
				$this->apply_stock( $variation, $sv );
				$this->apply_physical( $variation, $sv );
				$variation->set_attributes( $attrs );

				// New variation, or an existing one whose price/stock changed → parent rollup stale.
				$changes = $variation->get_changes();
				if ( ! $is_update || array_intersect_key( $changes, array_flip( $rollup_props ) ) ) {
					$rollup_dirty = true;
				}

				$new_vid = $variation->save();
				// Variation image — incremental, on create AND update (only new/changed downloads).
				if ( $this->field_on( 'images' ) && ! empty( $sv['image']['src'] ) ) {
					$this->sync_product_images( $variation, array( $sv['image'] ) );
				}

				$kept[ $new_vid ] = true;
			} catch ( \Throwable $e ) {
				// A dropped variation is missing product data, not a footnote. It used to log at
				// 'warning' and leave the run reporting błędy=0 — a variable product quietly short
				// of a variation, looking like a clean sync. The parent is counted as an error.
				$this->last_variation_failed = true;
				$this->log( 'error', sprintf( 'Wariacja (SKU=%s) rodzica %d: %s', $sv['sku'] ?? '', $target_parent_id, $e->getMessage() ) );
			}
		}

		if ( $this->fast_mode ) {
			// Fast field-refresh is update-only — leave variation add/remove to the daily full sync.
		} elseif ( ! $this->variations_fetch_error && ! $this->last_variation_failed ) {
			// Only prune stale children when EVERY source variation was written successfully. If any
			// save failed (#15 — most often WC's "Invalid or duplicated SKU" when another product
			// already holds that SKU), the failed ones are missing from $kept, so pruning would delete
			// the existing variations they were meant to replace and leave an empty variable product.
			// Keeping the old variations and reporting the error is always safer than destroying them;
			// a later clean run prunes anything genuinely removed from the source.
			foreach ( $parent->get_children() as $vid ) {
				if ( empty( $kept[ $vid ] ) ) {
					$stale = wc_get_product( $vid );
					if ( $stale ) {
						$stale->delete( true );
						$rollup_dirty = true; // removed a child → parent price/stock rollup stale
						$this->log( 'info', sprintf( 'Usunięto nieaktualną wariację ID=%d (rodzic %d)', $vid, $target_parent_id ) );
					}
				}
			}
		} else {
			$reason = $this->variations_fetch_error ? 'błąd pobrania wariacji' : 'część wariacji nie zapisała się';
			$this->log( 'warning', sprintf( 'Rodzic %d: %s – pomijam usuwanie dzieci (zachowuję istniejące wariacje).', $target_parent_id, $reason ) );
		}

		$this->last_variation_count = count( $kept );
		return $rollup_dirty;
	}

	private function build_variation_attributes( array $source_attrs ) {
		$out = array();
		foreach ( $source_attrs as $a ) {
			if ( ! empty( $a['id'] ) && (int) $a['id'] > 0 ) {
				$this->learn_attribute( $a );
				$map = $this->map_global_attribute( (int) $a['id'] );
				if ( ! $map ) {
					throw new \RuntimeException( sprintf(
						'Nie udało się odwzorować atrybutu globalnego wariacji (ID=%d) — wariacja pominięta.',
						(int) $a['id']
					) );
				}
				$option = $a['option'] ?? '';
				$term   = get_term_by( 'name', $option, $map['taxonomy'] );
				if ( ( ! $term || is_wp_error( $term ) ) && '' !== trim( (string) $option ) ) {
					// Term not found on target — create it via ensure_term() so the slug we
					// store actually points at a real term. The old fallback emitted a bare
					// sanitize_title() slug (Żółty → zolty) without creating the term, leaving
					// a dangling reference that silently dropped the variation's attribute value.
					$tid  = $this->ensure_term( $map['taxonomy'], $option );
					$term = $tid ? get_term( $tid, $map['taxonomy'] ) : null;
				}
				$out[ $map['taxonomy'] ] = ( $term && ! is_wp_error( $term ) ) ? $term->slug : sanitize_title( $option );
			} else {
				$out[ sanitize_title( $a['name'] ?? '' ) ] = $a['option'] ?? '';
			}
		}
		return $out;
	}

	private function signature( array $attrs ) {
		$attrs = array_map( 'strval', $attrs );
		ksort( $attrs );
		return md5( wp_json_encode( $attrs ) );
	}

	/* =====================================================================
	 *  GROUPED
	 * ================================================================== */

	/** Final pass (B3): fetch all grouped products from the source and upsert them once every
	 *  other product is synced, so their children resolve by _wps_source_id across batches/order.
	 *  Runs once at sync completion. Respects the "grouped" type toggle + status filter. */
	private function sync_grouped_products( $dry_run, &$stats ) {
		if ( ! $this->type_enabled( 'grouped' ) ) {
			return;
		}
		$page     = 1;
		$per_page = $this->cfg_per_page();
		$total    = 0;
		do {
			$batch = $this->api_get( '/wp-json/wc/v3/products', array(
				'per_page' => $per_page,
				'page'     => $page,
				'status'   => 'any',
				'type'     => 'grouped',
			) );
			if ( is_wp_error( $batch ) ) {
				$this->log( 'warning', 'Pobieranie grouped (strona ' . $page . '): ' . $batch->get_error_message() );
				break;
			}
			$count = count( $batch );
			if ( 0 === $count ) {
				break;
			}
			foreach ( $batch as $p ) {
				$this->process_single_product( $p, $dry_run, $stats );
				$total++;
			}
			$page++;
		} while ( $count === $per_page );
		if ( $total > 0 ) {
			$this->log( 'info', sprintf( 'Przetworzono %d produktów grouped (pass końcowy).', $total ) );
		}
	}

	private function upsert_grouped( array $p, $dry_run ) {
		$sku         = isset( $p['sku'] ) ? trim( $p['sku'] ) : '';
		$existing_id = $sku ? self::sku_to_id( $sku ) : 0;

		if ( ! $existing_id && ! empty( $p['id'] ) ) {
			$found = get_posts( array(
				'post_type'      => 'product',
				'meta_key'       => self::META_SOURCE_ID,
				'meta_value'     => (int) $p['id'],
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );
			if ( $found ) {
				$existing_id = (int) $found[0];
			}
		}
		if ( ! $existing_id && ! empty( $p['slug'] ) ) {
			$found = get_posts( array(
				'post_type'      => 'product',
				'name'           => $p['slug'],
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );
			if ( $found ) {
				$existing_id = (int) $found[0];
			}
		}
		$is_update = (bool) $existing_id;

		$child_ids = array();
		foreach ( ( $p['grouped_products'] ?? array() ) as $child_source_id ) {
			// B3: resolve the child by its source id → local product (_wps_source_id meta), which
			// works regardless of which batch synced it. Fall back to the SKU map for children
			// seen this run but not yet carrying the meta.
			$local = self::source_id_to_local( (int) $child_source_id );
			if ( ! $local ) {
				$child_sku = $this->source_id_to_sku[ (int) $child_source_id ] ?? '';
				if ( '' !== $child_sku ) {
					$local = self::sku_to_id( $child_sku );
				}
			}
			if ( $local ) {
				$child_ids[] = $local;
			} else {
				$this->report_add( 'warnings', array(
					'name' => $p['name'] ?? '?', 'sku' => $p['sku'] ?? '', 'type' => 'grouped',
					'reason' => sprintf( 'brak lokalnego dziecka src_id=%d (niezsynchronizowane — inny status/typ, brak SKU?)', (int) $child_source_id ),
				) );
				$this->log( 'warning', sprintf( "Grouped '%s': brak lokalnego dziecka src_id=%d.", $p['name'] ?? '?', $child_source_id ) );
			}
		}

		if ( $dry_run ) {
			$this->log( 'info', sprintf( '[DRY] %s grouped: %s (dzieci=%d)', $is_update ? 'UPDATE' : 'CREATE', $p['name'] ?? '?', count( $child_ids ) ) );
			return $is_update ? 'updated' : 'created';
		}

		$this->writing_update = $is_update; // gate fields only when updating an existing grouped (#2)
		$product = $this->ensure_product_type( $existing_id, 'WC_Product_Grouped', 'grouped' );
		$this->apply_common_fields( $product, $p );
		if ( $sku ) {
			$product->set_sku( $sku );
		}
		$product->set_children( $child_ids );

		$id = $product->save();
		if ( ! $id ) {
			throw new \RuntimeException( 'save() zwróciło 0' );
		}
		$this->last_saved_id = (int) $id;
		update_post_meta( $id, self::META_SOURCE_ID, (int) $p['id'] );
		if ( $this->field_on( 'images' ) && ! empty( $p['images'] ) ) {
			$this->sync_product_images( $product, $p['images'] );
		}
		$this->mark_synced( $id );
		$this->log( 'info', sprintf( '%s grouped: %s (ID=%d, dzieci=%d)', $is_update ? 'Zaktualizowano' : 'Utworzono', $p['name'] ?? '?', $id, count( $child_ids ) ) );
		return $is_update ? 'updated' : 'created';
	}

	/* =====================================================================
	 *  Kategorie i obrazy
	 * ================================================================== */

	private function resolve_category_ids( array $categories ) {
		$ids = array();
		foreach ( $categories as $cat ) {
			$name = isset( $cat['name'] ) ? trim( $cat['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$term = get_term_by( 'name', $name, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
				continue;
			}
			$new = wp_insert_term( $name, 'product_cat' );
			if ( ! is_wp_error( $new ) ) {
				$ids[] = (int) $new['term_id'];
			} else {
				$this->log( 'warning', 'Nie utworzono kategorii: ' . $name );
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/** Incrementally sync a product's (or variation's) images. Reuses already-downloaded images
	 *  keyed by source image id, sideloads only NEW/changed ones, and removes ones the source no
	 *  longer has. Runs on create AND update, so image changes propagate without re-downloading
	 *  unchanged images. $images = WC REST "images" array (each has 'id' + 'src'). */
	private function sync_product_images( $product, array $images ) {
		$pid = $product->get_id();
		$old = json_decode( (string) get_post_meta( $pid, self::META_IMAGE_MAP, true ), true );
		if ( ! is_array( $old ) ) {
			$old = array();
		}
		$new     = array();
		$att_ids = array();
		$failed  = 0;
		foreach ( $images as $img ) {
			$src = isset( $img['src'] ) ? esc_url_raw( $img['src'] ) : '';
			if ( '' === $src ) {
				continue;
			}
			$key = ! empty( $img['id'] ) ? 'id:' . (int) $img['id'] : 'url:' . $src;
			if ( isset( $old[ $key ] ) && get_post_status( (int) $old[ $key ] ) ) {
				$att = (int) $old[ $key ]; // already have it — no download
			} else {
				$att = $this->sideload_single( $src, $pid );
				if ( ! $att ) {
					$failed++;
				}
			}
			if ( $att ) {
				$new[ $key ] = $att;
				$att_ids[]   = $att;
			}
		}

		// A DOWNLOAD FAILURE MUST NEVER DESTROY WHAT WE ALREADY HAVE.
		//
		// The source still lists these images; we just could not fetch one right now (a blip, a
		// TLS mismatch, a 502). Previously the code carried on regardless: the failed key never
		// entered $new, so the cleanup below deleted the product's existing attachments, and an
		// empty $att_ids stripped the images off the product entirely — permanent local data loss
		// caused by a transient network error, reported as a clean run (błędy=0).
		//
		// So on any failure: leave the product's images and the image map exactly as they were,
		// bin only the attachments we created during THIS call (otherwise they orphan), and tell
		// the caller. The next run retries from the unchanged map.
		if ( $failed ) {
			foreach ( $new as $k => $att ) {
				if ( ! isset( $old[ $k ] ) ) {
					wp_delete_attachment( (int) $att, true );
				}
			}
			$this->last_image_failed = true;
			$this->log( 'error', sprintf(
				'Obrazy NIE zsynchronizowane dla ID=%d: %d z %d nie pobrano. Zachowano dotychczasowe obrazy — nic nie usunięto.',
				$pid,
				$failed,
				count( $images )
			) );
			return false;
		}

		// Delete attachments we created for images the source no longer references.
		foreach ( $old as $k => $att ) {
			if ( ! isset( $new[ $k ] ) && ! in_array( (int) $att, $att_ids, true ) ) {
				wp_delete_attachment( (int) $att, true );
			}
		}
		if ( $att_ids ) {
			$product->set_image_id( array_shift( $att_ids ) );
			$product->set_gallery_image_ids( $att_ids );
		} else {
			$product->set_image_id( 0 );
			$product->set_gallery_image_ids( array() );
		}
		update_post_meta( $pid, self::META_IMAGE_MAP, wp_json_encode( $new ) );
		$product->save();
		return true;
	}

	private function sideload_single( $src, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att = media_sideload_image( esc_url_raw( $src ), $post_id, null, 'id' );
		if ( is_wp_error( $att ) ) {
			// 'error', not 'warning': this is missing product data, and a warning is something an
			// operator reads past. sync_product_images() turns it into a counted error so the run
			// summary says so too.
			$this->log( 'error', 'Nie pobrano obrazu (' . $src . '): ' . $att->get_error_message() );
			return 0;
		}
		return (int) $att;
	}

	/* =====================================================================
	 *  Soft-delete
	 * ================================================================== */

	/* =====================================================================
	 *  Cofanie synchronizacji (undo) — kosz na produkty utworzone w przebiegu
	 * ================================================================== */

	/** started_at of the most recent run, i.e. the run "undo last sync" targets, or 0. */
	private function last_run_token() {
		$r = get_option( self::SYNC_LAST_RESULT, array() );
		return ( is_array( $r ) && ! empty( $r['started_at'] ) ) ? (int) $r['started_at'] : 0;
	}

	/** Product IDs that a given run created.
	 *
	 *  Primary signal is the _wps_created_run tag. But runs from before that tag existed (e.g. a
	 *  build that created duplicates and only stamped _wps_source_id) have no tag — so when the tag
	 *  finds nothing, fall back to the heuristic that defines "created this run": a product this
	 *  plugin owns (_wps_source_id) whose post_date is at or after the run start. Updated products
	 *  keep their old post_date, so they are never caught; a product added by hand during the run
	 *  has no _wps_source_id, so it is never caught either. */
	private function products_created_by_run( $token ) {
		if ( $token < 1 ) {
			return array();
		}
		$tagged = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::META_CREATED_RUN,
			'meta_value'     => (string) $token,
		) );
		if ( $tagged ) {
			return $tagged;
		}
		return get_posts( array(
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::META_SOURCE_ID,      // our product
			'date_query'     => array( array(
				'column' => 'post_date_gmt',
				'after'  => gmdate( 'Y-m-d H:i:s', $token - 2 ), // created at/after run start
				'inclusive' => true,
			) ),
		) );
	}

	/** How many products the last run created and could still be undone (for the admin button). */
	public function last_run_undo_count() {
		return count( $this->products_created_by_run( $this->last_run_token() ) );
	}

	/** Undo a run's creations: move exactly the products that run CREATED to the Trash. Reversible —
	 *  nothing is permanently deleted, so a mistaken undo is recoverable from WooCommerce → Kosz.
	 *  Only ever touches products carrying this run's _wps_created_run tag, so products the run merely
	 *  updated, and products that pre-dated the plugin, are never affected. $token defaults to the
	 *  last run. Returns the number trashed. */
	public function undo_run( $token = 0, $dry_run = false ) {
		$token = $token ? (int) $token : $this->last_run_token();
		$ids   = $this->products_created_by_run( $token );
		if ( ! $ids ) {
			$this->log( 'info', 'Cofanie synchronizacji: brak produktów utworzonych w tym przebiegu.' );
			return 0;
		}
		if ( $dry_run ) {
			$this->log( 'info', sprintf( '[DRY] Cofnięcie usunęłoby (do kosza) %d produktów utworzonych w przebiegu %d.', count( $ids ), $token ) );
			return count( $ids );
		}
		$n = 0;
		foreach ( $ids as $id ) {
			// Trash, not delete: recoverable. wp_trash_post also trashes child variations.
			if ( wp_trash_post( (int) $id ) ) {
				$n++;
			}
		}
		$this->log( 'warning', sprintf( 'Cofnięto synchronizację: %d produktów utworzonych w przebiegu %d przeniesiono do kosza.', $n, $token ) );
		return $n;
	}

	/* =====================================================================
	 *  Scalanie istniejących produktów (adoption) — nadanie _wps_source_id
	 *  produktom założonym poza wtyczką, by sync je aktualizował zamiast
	 *  tworzyć duplikaty.
	 * ================================================================== */

	/** True when a local product is UNCLAIMED — no _wps_source_id yet, so adopting it is safe. */
	private function is_unclaimed( $local_id ) {
		return '' === (string) get_post_meta( (int) $local_id, self::META_SOURCE_ID, true );
	}

	/** Find the single UNCLAIMED local product with this exact name, or 0 if none / ambiguous.
	 *  Any status (unlike the sync's publish-only name fallback) — an adoption tool should be able
	 *  to claim a draft the store already had. Uniqueness is required among unclaimed products, so
	 *  a name shared by several candidates is never guessed. */
	private function unclaimed_by_name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return 0;
		}
		$ids = get_posts( array(
			'post_type'      => 'product',
			'title'          => $name,   // exact match; 'post_title' is not a WP_Query arg (see find_existing_product)
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
		) );
		$unclaimed = array_values( array_filter( $ids, array( $this, 'is_unclaimed' ) ) );
		return count( $unclaimed ) === 1 ? (int) $unclaimed[0] : 0;
	}

	/** Reconcile the target with the source: stamp _wps_source_id onto EXISTING unclaimed products so
	 *  future syncs update them instead of creating duplicates.
	 *
	 *  Match order per source product: exact SKU, then exactly-one unclaimed product with that name.
	 *  Never touches a product that already carries _wps_source_id, and never guesses an ambiguous
	 *  name (0 or >1 candidates) — those are reported for manual handling, not adopted.
	 *
	 *  $dry_run (default true) returns the plan and writes nothing. Returns:
	 *    array( 'adopt' => [ [source_id,local_id,sku,name,how], ... ],
	 *           'ambiguous' => [ [source_id,sku,name,reason], ... ],
	 *           'claimed' => N )   // source products already matched to a claimed local (nothing to do) */
	/** Full-pass reconcile, no time budget — for wp-cli and tests. The admin UI uses the
	 *  backgrounded, batched path (handle_adopt → run_adopt_event) so it never exceeds the
	 *  FastCGI/proxy timeout on a large catalog. $dry_run=true only plans; false stamps. */
	public function adopt_existing( $dry_run = true ) {
		$this->adopt_reset( ! $dry_run );
		do {
			$done = $this->adopt_step( PHP_FLOAT_MAX );
		} while ( ! $done );
		$st   = get_transient( self::ADOPT_STATE );
		$plan = is_array( $st ) ? $st['plan'] : array( 'adopt' => array(), 'ambiguous' => array(), 'claimed' => 0 );
		delete_transient( self::ADOPT_STATE );
		$this->log( $dry_run ? 'info' : 'warning', sprintf(
			'%sScalanie: do adopcji %d, wieloznacznych %d, już przypisanych %d.',
			$dry_run ? '[DRY] ' : '',
			count( $plan['adopt'] ), count( $plan['ambiguous'] ), $plan['claimed']
		) );
		return $plan;
	}

	/** Start a fresh reconcile: page 1, empty plan. $apply = stamp (not just plan). */
	private function adopt_reset( $apply, $chain_mirror = false ) {
		set_transient( self::ADOPT_STATE, array(
			'apply' => $apply ? 1 : 0,
			'total' => $chain_mirror ? 1 : 0, // chain into a mirror (total) sync when this apply-adopt finishes
			'page'  => 1,
			'done'  => 0,
			'plan'  => array( 'adopt' => array(), 'ambiguous' => array(), 'claimed' => 0 ),
		), HOUR_IN_SECONDS );
	}

	/** Process source pages from the saved cursor until $deadline (microtime) or the source is
	 *  exhausted. Accumulates into the state's plan and, in apply mode, stamps _wps_source_id as it
	 *  goes. Returns true when the whole source has been processed. */
	private function adopt_step( $deadline ) {
		$st = get_transient( self::ADOPT_STATE );
		if ( ! is_array( $st ) ) {
			return true;
		}
		$apply = ! empty( $st['apply'] );
		$page  = (int) $st['page'];
		$plan  = $st['plan'];
		$per   = $this->cfg_per_page();
		$done  = false;
		do {
			$batch = $this->api_get( '/wp-json/wc/v3/products', array(
				'per_page' => $per,
				'page'     => $page,
				'status'   => 'any',
			) );
			if ( is_wp_error( $batch ) ) {
				$this->log( 'error', 'Scalanie: błąd pobierania źródła (strona ' . $page . '): ' . $batch->get_error_message() );
				$done = true;
				break;
			}
			$count = is_array( $batch ) ? count( $batch ) : 0;
			foreach ( (array) $batch as $p ) {
				$this->adopt_process_product( $p, $apply, $plan );
			}
			$page++;
			if ( $count < $per ) {
				$done = true;
				break;
			}
		} while ( microtime( true ) < $deadline );

		$st['page'] = $page;
		$st['plan'] = $plan;
		$st['done'] = $done ? 1 : 0;
		set_transient( self::ADOPT_STATE, $st, HOUR_IN_SECONDS );
		return $done;
	}

	/** Match one source product to an unclaimed local one (SKU, then unique name); accumulate. */
	private function adopt_process_product( array $p, $apply, array &$plan ) {
		$src_id = absint( $p['id'] ?? 0 );
		$sku    = trim( (string) ( $p['sku'] ?? '' ) );
		$name   = trim( (string) ( $p['name'] ?? '' ) );
		if ( ! $src_id ) {
			return;
		}
		if ( self::source_id_to_local( $src_id ) ) { // already claimed by source_id → nothing to do
			$plan['claimed']++;
			return;
		}
		$local = 0;
		$how   = '';
		if ( '' !== $sku ) {
			$cand = self::sku_to_id( $sku );
			if ( $cand && $this->is_unclaimed( $cand ) ) {
				$local = $cand;
				$how   = 'SKU';
			}
		}
		if ( ! $local ) {
			$cand = $this->unclaimed_by_name( $name );
			if ( $cand ) {
				$local = $cand;
				$how   = 'nazwa';
			}
		}
		if ( $local ) {
			$plan['adopt'][] = array( 'source_id' => $src_id, 'local_id' => $local, 'sku' => $sku, 'name' => $name, 'how' => $how );
			if ( $apply ) {
				update_post_meta( $local, self::META_SOURCE_ID, (string) $src_id );
			}
		} else {
			$plan['ambiguous'][] = array( 'source_id' => $src_id, 'sku' => $sku, 'name' => $name,
				'reason' => '' === $sku && '' === $name ? 'brak SKU i nazwy' : 'brak jednoznacznego dopasowania na celu' );
		}
	}

	/** Cron: run one time-boxed reconcile batch; reschedule until done, then publish the plan. */
	public function run_adopt_event() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 900 );
		}
		$budget = max( 1, (int) $this->get_options()['max_batch_seconds'] );
		$done   = $this->adopt_step( microtime( true ) + $budget );
		if ( ! $done ) {
			wp_schedule_single_event( time() + 5, self::ADOPT_HOOK );
			spawn_cron();
			return;
		}
		$st    = get_transient( self::ADOPT_STATE );
		$plan  = is_array( $st ) ? $st['plan'] : array( 'adopt' => array(), 'ambiguous' => array(), 'claimed' => 0 );
		$apply = is_array( $st ) && ! empty( $st['apply'] );
		$total = is_array( $st ) && ! empty( $st['total'] );
		set_transient( 'wps_adopt_preview', $plan, 30 * MINUTE_IN_SECONDS );
		update_option( self::ADOPT_RESULT, array(
			'running'   => 0,
			'apply'     => $apply ? 1 : 0,
			'adopt'     => count( $plan['adopt'] ),
			'ambiguous' => count( $plan['ambiguous'] ),
			'claimed'   => (int) $plan['claimed'],
			'finished_at' => time(),
		), false );
		delete_transient( self::ADOPT_STATE );
		$this->log( $apply ? 'warning' : 'info', sprintf(
			'%sScalanie zakończone: do adopcji %d, wieloznacznych %d, już przypisanych %d.',
			$apply ? '' : '[podgląd] ', count( $plan['adopt'] ), count( $plan['ambiguous'] ), (int) $plan['claimed']
		) );

		// Total sync: the adopt phase (SKU + name matching) is now applied, so every product that
		// exists on both sides carries _wps_source_id and will be UPDATED, not deleted. Chain into the
		// mirror sync (force_full + hard delete) which removes whatever the source no longer has.
		if ( $apply && $total ) {
			$this->start_mirror_sync();
		}
	}

	/** Kick off the batched mirror sync (force_full + hard delete). Called after the total-sync adopt
	 *  phase completes so adopted products are already linked and survive the deletion pass. */
	private function start_mirror_sync() {
		if ( false !== get_transient( self::SYNC_PROGRESS_TRANSIENT ) ) {
			// Rare: another sync started in the window between handle_total_sync's up-front busy check
			// and the adopt phase finishing. Don't silently drop the mirror — record it where the admin
			// will see it (the panel reads ADOPT_RESULT and the run summary links to the WC log).
			$this->log( 'error', 'Total sync: faza lustrzana NIE ruszyła — inna synchronizacja jest w toku. Uruchom „Total sync" ponownie, gdy się zakończy.' );
			$res = (array) get_option( self::ADOPT_RESULT, array() );
			$res['mirror_skipped'] = 1;
			update_option( self::ADOPT_RESULT, $res, false );
			return;
		}
		$this->fast_mode  = false;
		$this->dry_mode   = false;
		$this->total_sync = true;
		$this->reset_run_result( false );
		$this->seed_sync_progress();
		$this->log( 'warning', 'Total sync: faza scalania zakończona, uruchamiam synchronizację lustrzaną (twarde usuwanie brakujących).' );
		wp_schedule_single_event( time(), self::CRON_HOOK );
		spawn_cron();
	}

	private function mark_synced( $product_id ) {
		update_post_meta( $product_id, self::META_SYNCED, time() );
		if ( get_post_meta( $product_id, self::META_SOFT_DELETED, true ) ) {
			delete_post_meta( $product_id, self::META_SOFT_DELETED );
			$tag_id = $this->get_soft_delete_tag_id();
			if ( $tag_id ) {
				wp_remove_object_terms( $product_id, array( $tag_id ), 'product_tag' );
			}
			$this->log( 'info', sprintf( 'Przywrócono ze soft-delete: ID=%d', $product_id ) );
		}
	}

	private function get_soft_delete_tag_id() {
		$term = get_term_by( 'slug', self::TAG_SLUG, 'product_tag' );
		if ( $term && ! is_wp_error( $term ) ) {
			return (int) $term->term_id;
		}
		$new = wp_insert_term( self::TAG_NAME, 'product_tag', array( 'slug' => self::TAG_SLUG ) );
		if ( ! is_wp_error( $new ) ) {
			return (int) $new['term_id'];
		}
		$data = $new->get_error_data();
		return ( is_array( $data ) && isset( $data['term_id'] ) ) ? (int) $data['term_id'] : 0;
	}

	private function soft_delete_missing( array $source_keys, $source_count, $dry_run ) {
		if ( $this->fetch_had_error ) {
			$this->log( 'warning', 'Pobieranie źródła miało błąd – pomijam soft-delete.' );
			return;
		}
		if ( $source_count < 1 ) {
			$this->log( 'warning', 'Źródło zwróciło 0 produktów – pomijam soft-delete.' );
			return;
		}

		$source_keys_set = array_flip( $source_keys );

		$tag_id = 0;
		$candidates = array();
		$page       = 1;

		// Normal sync only ever removes products the plugin itself synced (META_SYNCED EXISTS), so a
		// product created by another tool is left untouched. A total sync is a MIRROR: it must also
		// remove foreign products the source doesn't have, so drop the "only ours" filter for it. The
		// adopt phase already stamped _wps_source_id on everything that DOES match the source, and
		// those keys are in $source_keys_set, so they are never candidates.
		$meta_query = array(
			array(
				'key'     => self::META_SOFT_DELETED,
				'compare' => 'NOT EXISTS',
			),
		);
		if ( ! $this->total_sync ) {
			$meta_query[] = array(
				'key'     => self::META_SYNCED,
				'compare' => 'EXISTS',
			);
		}

		do {
			$batch = get_posts( array(
				'fields'         => 'ids',
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'pending', 'private' ),
				'posts_per_page' => 100,
				'paged'          => $page,
				'no_found_rows'  => true,
				'meta_query'     => $meta_query,
			) );
			$count = count( $batch );
			foreach ( $batch as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}
				$match_sku  = $product->get_sku();
				$match_name = $product->get_name();
				$match_key  = ( '' !== $match_sku ) ? $match_sku : $match_name;

				if ( '' === $match_key ) {
					continue;
				}
				if ( ! isset( $source_keys_set[ $match_key ] ) ) {
					$candidates[] = $pid;
				}
			}
			$page++;
		} while ( 100 === $count );

		$mode = $this->effective_deletion_mode();

		if ( ! $candidates ) {
			if ( 'soft' === $mode ) {
				$this->enforce_soft_delete_limit( $dry_run );
			}
			return;
		}

		// HARD DELETE mode: permanently remove products that no longer exist in the source.
		// Irreversible — protected by the same guards (no fetch error, source_count>=1) plus a
		// per-run safety cap so a temporary source glitch can't wipe the whole catalogue at once.
		if ( 'hard' === $mode ) {
			$cap = (int) $this->get_options()['hard_delete_max'];
			if ( $cap > 0 && count( $candidates ) > $cap ) {
				$this->log( 'warning', sprintf( 'Hard-delete: %d produktów do usunięcia przekracza limit bezpieczeństwa %d — usuwam %d w tym przebiegu.', count( $candidates ), $cap, $cap ) );
				$candidates = array_slice( $candidates, 0, $cap );
			}
			$this->log( 'info', sprintf( 'Hard-delete: trwale usuwam %d produktów, których nie ma w źródle.', count( $candidates ) ) );
			foreach ( $candidates as $pid ) {
				$product = wc_get_product( $pid );
				$name    = $product ? $product->get_name() : '';
				$sku     = $product ? $product->get_sku() : '';
				$this->report_add( 'hard_deleted', array(
					'name'   => $name,
					'sku'    => $sku,
					'type'   => $product ? $product->get_type() : '',
					'reason' => 'brak w źródle — usunięto trwale' . ( $dry_run ? ' [DRY]' : '' ),
				) );
				if ( $dry_run ) {
					$this->log( 'info', sprintf( '[DRY] HARD-DELETE: %s (ID=%d, SKU=%s)', $name, $pid, $sku ) );
					continue;
				}
				if ( $product ) {
					$product->delete( true );
				} else {
					wp_delete_post( $pid, true );
				}
				$this->log( 'info', sprintf( 'HARD-DELETE (brak w źródle): %s (ID=%d, SKU=%s)', $name, $pid, $sku ) );
			}
			return;
		}

		$this->log( 'info', sprintf( 'Soft-delete: %d produktów nie ma już w źródle.', count( $candidates ) ) );

		foreach ( $candidates as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$match_sku  = $product->get_sku();
			$match_key  = ( '' !== $match_sku ) ? $match_sku : $product->get_name();

			$this->report_add( 'soft_deleted', array(
				'name'   => $product->get_name(),
				'sku'    => $match_sku,
				'type'   => $product->get_type(),
				'reason' => 'brak w źródle' . ( $dry_run ? ' [DRY]' : '' ),
			) );
			if ( $dry_run ) {
				$this->log( 'info', sprintf( '[DRY] SOFT-DELETE → draft: %s (ID=%d, klucz=%s)', $product->get_name(), $pid, $match_key ) );
				continue;
			}
			$tag_id = $this->get_soft_delete_tag_id();
			$product->set_status( 'draft' );
			$product->save();
			update_post_meta( $pid, self::META_SOFT_DELETED, time() );
			if ( $tag_id ) {
				wp_set_object_terms( $pid, array( $tag_id ), 'product_tag', true );
			}
			$this->log( 'info', sprintf( 'Soft-delete → draft: %s (ID=%d, klucz=%s)', $product->get_name(), $pid, $match_key ) );
		}

		$this->enforce_soft_delete_limit( $dry_run );
	}

	private function enforce_soft_delete_limit( $dry_run ) {
		$limit = (int) $this->get_options()['soft_delete_limit'];
		if ( $limit < 1 ) {
			return;
		}

		$ids = get_posts( array(
			'fields'         => 'ids',
			'post_type'      => 'product',
			'post_status'    => 'draft',
			'posts_per_page' => -1,
			'meta_key'       => self::META_SOFT_DELETED,
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => self::META_SOFT_DELETED,
					'compare' => 'EXISTS',
				),
			),
		) );

		$excess = count( $ids ) - $limit;
		if ( $excess < 1 ) {
			return;
		}

		$to_delete = array_slice( $ids, 0, $excess );
		$this->log( 'info', sprintf( 'Limit szkiców = %d, nadwyżka = %d.', $limit, $excess ) );

		foreach ( $to_delete as $pid ) {
			$product = wc_get_product( $pid );
			$name    = $product ? $product->get_name() : '';
			if ( $dry_run ) {
				$this->log( 'info', sprintf( '[DRY] TRWALE USUŃ: %s (ID=%d)', $name, $pid ) );
				continue;
			}
			if ( $product ) {
				$product->delete( true );
			} else {
				wp_delete_post( $pid, true );
			}
			$this->log( 'info', sprintf( 'Trwale usunięto: %s (ID=%d)', $name, $pid ) );
		}
	}

	private function should_sync_status( $source_status ) {
		$allowed = apply_filters( 'wps_sync_statuses', (array) $this->get_options()['sync_statuses'], $source_status );
		return in_array( $source_status, (array) $allowed, true );
	}
	/* =====================================================================
	 *  Logi
	 * ================================================================== */

	private function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}

	/* =====================================================================
	 *  Aktualizacje z własnego serwera (self-hosted JSON updater)
	 * ================================================================== */

	/** Update-metadata endpoint.
	 *
	 *  Defaults to the project's public release channel, so a plain install gets updates in
	 *  Wtyczki → Aktualizuj with no configuration at all. The channel serves metadata only; the
	 *  ZIP it points at is the immutable versioned release.
	 *
	 *  Overridable, in both directions:
	 *    define( 'WC_PRODUCT_SYNC_UPDATE_URL', 'https://…/update.json' );  // your own server
	 *    define( 'WC_PRODUCT_SYNC_UPDATE_URL', '' );                        // updater OFF, no HTTP
	 *  or via the `wps_update_url` filter. An empty value disables the updater completely: no
	 *  requests are made and every update filter becomes a no-op. */
	private function update_url() {
		if ( defined( 'WC_PRODUCT_SYNC_UPDATE_URL' ) ) {
			// Explicit override (own server, or '' to disable) always wins over the channel setting.
			$url = WC_PRODUCT_SYNC_UPDATE_URL;
		} else {
			$channel = ( $this->get_options()['update_channel'] ?? 'stable' );
			$url     = ( 'rc' === $channel ) ? self::RC_UPDATE_URL : self::DEFAULT_UPDATE_URL;
		}
		return trim( (string) apply_filters( 'wps_update_url', $url ) );
	}

	/** Access token for a private update server (Forgejo/Gitea). Empty = anonymous. */
	private function update_token() {
		$t = defined( 'WC_PRODUCT_SYNC_UPDATE_TOKEN' ) ? WC_PRODUCT_SYNC_UPDATE_TOKEN : '';
		return trim( (string) apply_filters( 'wps_update_token', $t ) );
	}

	/** Host of the configured update URL — used to scope the auth token to that server only. */
	private function update_host() {
		$url = $this->update_url();
		return $url ? strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) : '';
	}

	/** Attach the update-server token to requests aimed at that host — notably the ZIP download WP
	 *  core performs via download_url(), which our code never touches. Scoped strictly to the update
	 *  host so the token is never sent to any other server, and never clobbers an existing header. */
	public function authorize_update_request( $args, $url ) {
		$token = $this->update_token();
		if ( '' === $token ) {
			return $args;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || $host !== $this->update_host() ) {
			return $args;
		}
		if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = array();
		}
		if ( empty( $args['headers']['Authorization'] ) ) {
			$args['headers']['Authorization'] = 'token ' . $token;
		}
		return $args;
	}

	/** This plugin's installed version, read from the header (single source of truth). */
	private function current_version() {
		$data = get_file_data( __FILE__, array( 'Version' => 'Version' ) );
		return ! empty( $data['Version'] ) ? $data['Version'] : '0';
	}

	/** Fetch + cache the remote update metadata (JSON). Cached 12h on success, 2h on failure
	 *  (negative cache) so a down server never hammers on every admin page load. Returns the decoded
	 *  array, or null when the updater is disabled / the payload is unusable. */
	private function remote_update_info( $force = false ) {
		$url = $this->update_url();
		if ( '' === $url ) {
			return null;
		}
		if ( ! $force ) {
			$cached = get_transient( self::UPDATE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached; // array() = negative cache
			}
		}
		$headers = array( 'Accept' => 'application/json' );
		$token   = $this->update_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'token ' . $token; // private repo (Forgejo/Gitea)
		}
		$res = wp_remote_get( $url, array( 'timeout' => 15, 'headers' => $headers ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			set_transient( self::UPDATE_TRANSIENT, array(), 2 * HOUR_IN_SECONDS );
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) ) {
			set_transient( self::UPDATE_TRANSIENT, array(), 2 * HOUR_IN_SECONDS );
			return null;
		}
		set_transient( self::UPDATE_TRANSIENT, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}

	/** Inject an available update into WP's plugin-update transient so the Plugins screen shows it. */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = $this->remote_update_info();
		if ( empty( $info['version'] ) ) {
			return $transient;
		}
		$basename = plugin_basename( __FILE__ );
		$current  = $this->current_version();
		if ( ! empty( $info['download_url'] ) && version_compare( $info['version'], $current, '>' ) ) {
			$transient->response[ $basename ] = (object) array(
				'slug'         => 'wc-product-sync',
				'plugin'       => $basename,
				'new_version'  => $info['version'],
				'url'          => $info['homepage'] ?? '',
				'package'      => $info['download_url'],
				'requires'     => $info['requires'] ?? '',
				'requires_php' => $info['requires_php'] ?? '',
				'tested'       => $info['tested'] ?? '',
			);
		} else {
			// Report "up to date" so WP doesn't keep re-querying / shows a clean state.
			$transient->no_update[ $basename ] = (object) array(
				'slug'        => 'wc-product-sync',
				'plugin'      => $basename,
				'new_version' => $current,
			);
		}
		return $transient;
	}

	/** Provide the "View version details" modal (plugins_api) from the same JSON metadata. */
	public function update_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wc-product-sync' !== $args->slug ) {
			return $result;
		}
		$info = $this->remote_update_info();
		if ( empty( $info['version'] ) ) {
			return $result;
		}
		$sections = ( isset( $info['sections'] ) && is_array( $info['sections'] ) )
			? $info['sections']
			: array( 'changelog' => (string) ( $info['changelog'] ?? '' ) );
		return (object) array(
			'name'          => $info['name'] ?? 'WC Product Sync (SKU)',
			'slug'          => 'wc-product-sync',
			'version'       => $info['version'],
			'author'        => $info['author'] ?? 'Michał Pańczyk',
			'homepage'      => $info['homepage'] ?? '',
			'requires'      => $info['requires'] ?? '',
			'requires_php'  => $info['requires_php'] ?? '',
			'tested'        => $info['tested'] ?? '',
			'last_updated'  => $info['last_updated'] ?? '',
			'download_link' => $info['download_url'] ?? '',
			'sections'      => $sections,
		);
	}

	/** Drop the cached metadata right after any plugin update so the next check re-reads the server. */
	public function flush_update_cache( $upgrader, $data ) {
		if ( is_array( $data ) && isset( $data['type'] ) && 'plugin' === $data['type'] ) {
			delete_transient( self::UPDATE_TRANSIENT );
		}
	}

	/** Uninstall cleanup. Removes plugin OPTIONS, scheduled cron events, and transients only.
	 *  Product meta ({@see META_SYNCED}, {@see META_SOURCE_ID}, image maps) and the soft-delete
	 *  tag are intentionally LEFT INTACT: deleting them would destroy synced product data that
	 *  the store still relies on. Must be static — register_uninstall_hook cannot use $this. */
	public static function uninstall() {
		// Settings + last-run bookkeeping (stored via update_option, not transients).
		delete_option( self::OPTION_KEY );
		delete_option( self::SYNC_LAST_RESULT );
		delete_option( self::SYNC_LAST_REPORT );
		delete_option( self::ADOPT_RESULT );
		// Scheduled events.
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::RESUME_HOOK );
		wp_clear_scheduled_hook( self::FAST_CRON_HOOK );
		wp_clear_scheduled_hook( self::ADOPT_HOOK );
		// In-flight sync transients.
		delete_transient( self::SYNC_LOCK_TRANSIENT );
		delete_transient( self::SYNC_PROGRESS_TRANSIENT );
		delete_transient( self::SYNC_KEYS_TRANSIENT );
		delete_transient( self::UPDATE_TRANSIENT );
		delete_transient( self::ADOPT_STATE );
		delete_transient( 'wps_adopt_preview' );
	}
}

register_activation_hook( __FILE__, array( 'WC_Product_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WC_Product_Sync', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'WC_Product_Sync', 'uninstall' ) );
add_action( 'plugins_loaded', array( 'WC_Product_Sync', 'instance' ) );
