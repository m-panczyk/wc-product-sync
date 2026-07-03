<?php
/**
 * Plugin Name:       WC Product Sync (SKU)
 * Description:        Codzienna synchronizacja produktów ze zdalnego sklepu WooCommerce (źródło) do TEGO sklepu (cel). Dopasowanie po SKU (lub nazwie gdy brak SKU). Obsługa: simple, variable, grouped. Zapisy lokalnie przez WooCommerce CRUD.
 * Version:           0.6.0
 * Author:            M
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * Requires Plugins:  woocommerce
 * Text Domain:       wc-product-sync
 *
 * Instalacja na sklepie DOCELOWYM. Ze źródła czytamy przez REST, na cel zapisujemy lokalnie.
 *
 * Klucze API źródła – zalecane w wp-config.php (mają pierwszeństwo nad bazą):
 *   define( 'WC_PRODUCT_SYNC_SOURCE_URL', 'https://zrodlo.pl' );
 *   define( 'WC_PRODUCT_SYNC_CK', 'ck_xxx' );
 *   define( 'WC_PRODUCT_SYNC_CS', 'cs_xxx' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WC_Product_Sync {

	const OPTION_KEY      = 'wc_product_sync_options';
	const CRON_HOOK       = 'wc_product_sync_daily_event';
	const LOG_SOURCE      = 'wc-product-sync';
	const NONCE_ACTION    = 'wc_product_sync_run';
	const SYNC_LOCK_TRANSIENT = 'wps_sync_running';

	// Soft-delete
	const META_SYNCED       = '_wps_synced';
	const META_SOFT_DELETED = '_wps_soft_deleted_at';
	const TAG_SLUG          = 'wps-usuniete';
	const TAG_NAME          = 'Usunięte (sync)';
	const META_SOURCE_ID    = '_wps_source_id';

	/** @var WC_Product_Sync|null */
	private static $instance = null;

	/** Cache: źródłowe atrybuty globalne  id => ['name','slug'] */
	private $source_attributes = array();
	/** Cache mapowania atrybutu: bare_slug => ['taxonomy','attribute_id'] */
	private $attr_map_cache = array();
	/** Mapa źródłowe product_id => sku (dla grouped) */
	private $source_id_to_sku = array();
	/** Czy pobieranie źródła napotkało błąd (blokada soft-delete) */
	private $fetch_had_error = false;
	/** Czy pobieranie wariacji napotkało błąd (blokada usunięć) */
	private $variations_fetch_error = false;

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
		add_action( self::CRON_HOOK, array( $this, 'run_sync_cron' ) );
		add_action( 'admin_notices', array( $this, 'maybe_wc_missing_notice' ) );
	}

	/* =====================================================================
	 *  Aktywacja / dezaktywacja / cron
	 * ================================================================== */

	public static function activate() {
		$opts = get_option( self::OPTION_KEY, array() );
		if ( ! empty( $opts['schedule_enabled'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule_cron_at_time();
		}
	}

	private static function schedule_cron_at_time() {
		$opts   = get_option( self::OPTION_KEY, array() );
		$hour   = isset( $opts['cron_hour'] ) ? (int) $opts['cron_hour'] : 3;
		$minute = isset( $opts['cron_minute'] ) ? (int) $opts['cron_minute'] : 0;
		$now    = time();
		$today  = mktime( $hour, $minute, 0, gmdate( 'n', $now ), gmdate( 'j', $now ), gmdate( 'Y', $now ) );
		if ( $today <= $now ) {
			$today = strtotime( '+1 day', $today );
		}
		wp_schedule_event( $today, 'daily', self::CRON_HOOK );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function sync_cron_schedule() {
		$enabled   = ! empty( $this->get_options()['schedule_enabled'] );
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );
		if ( $enabled && ! $scheduled ) {
			$this->schedule_next_run();
		} elseif ( ! $enabled && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	private function schedule_next_run() {
		$hour   = (int) $this->get_options()['cron_hour'];
		$minute = (int) $this->get_options()['cron_minute'];
		$now    = time();
		$today  = mktime( $hour, $minute, 0, gmdate( 'n', $now ), gmdate( 'j', $now ), gmdate( 'Y', $now ) );
		if ( $today <= $now ) {
			$today = strtotime( '+1 day', $today );
		}
		wp_schedule_event( $today, 'daily', self::CRON_HOOK );
		$this->log( 'info', sprintf( 'Zaplanowano sync: o %02d:%02d (dzisiaj/jutro)', $hour, $minute ) );
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
			'schedule_enabled'    => 0,
			'soft_delete_enabled' => 0,
			'soft_delete_limit'   => 50,
			'cron_hour'           => 3,
			'cron_minute'         => 0,
			'force_full_sync'     => 0,
		);
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
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
		$out['schedule_enabled']    = empty( $input['schedule_enabled'] ) ? 0 : 1;
		$out['soft_delete_enabled'] = empty( $input['soft_delete_enabled'] ) ? 0 : 1;
		if ( isset( $input['soft_delete_limit'] ) ) {
			$out['soft_delete_limit']   = max( 0, (int) $input['soft_delete_limit'] );
		}
		if ( isset( $input['cron_hour'] ) ) {
			$out['cron_hour']         = max( 0, min( 23, (int) $input['cron_hour'] ) );
		}
		if ( isset( $input['cron_minute'] ) ) {
			$out['cron_minute']       = max( 0, min( 59, (int) $input['cron_minute'] ) );
		}
		if ( isset( $input['force_full_sync'] ) ) {
			$out['force_full_sync']   = empty( $input['force_full_sync'] ) ? 0 : 1;
		}
		add_action( 'shutdown', array( $this, 'sync_cron_schedule' ) );
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
		$run_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_run&mode=run' ), self::NONCE_ACTION );
		$dry_url = wp_nonce_url( admin_url( 'admin-post.php?action=wc_product_sync_run&mode=dry' ), self::NONCE_ACTION );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Synchronizacja produktów WooCommerce', 'wc-product-sync' ); ?></h1>

			<?php if ( isset( $_GET['synced'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					printf(
						esc_html__( 'Zakończono. Utworzono: %1$d, zaktualizowano: %2$d, pominięto: %3$d, błędy: %4$d. Szczegóły w logach WooCommerce.', 'wc-product-sync' ),
						(int) ( $_GET['created'] ?? 0 ),
						(int) ( $_GET['updated'] ?? 0 ),
						(int) ( $_GET['skipped'] ?? 0 ),
						(int) ( $_GET['errors'] ?? 0 )
					);
					?>
				</p></div>
			<?php endif; ?>

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
						<th scope="row"><?php esc_html_e( 'Pełna synchronizacja', 'wc-product-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[force_full_sync]" value="1"
								<?php checked( ! empty( $opts['force_full_sync'] ) ); ?> /> <?php esc_html_e( 'Wyczyść lokalne produkty sync przed uruchomieniem', 'wc-product-sync' ); ?></label>
							<p class="description"><?php esc_html_e( 'UWAGA: usunie wszystkie lokalne produkty oznaczone jako zsynchronizowane. Zalecane przy pierwszym użyciu.', 'wc-product-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Soft-delete', 'wc-product-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[soft_delete_enabled]" value="1"
								<?php checked( ! empty( $opts['soft_delete_enabled'] ) ); ?> /> <?php esc_html_e( 'Produkty usunięte ze źródła ustawiaj jako szkic i oznaczaj tagiem', 'wc-product-sync' ); ?></label>
							<p class="description">
								<?php
								printf(
									esc_html__( 'Dotyczy tylko produktów synchronizowanych przez ten plugin (znacznik %1$s). Tag: „%2$s".', 'wc-product-sync' ),
									'<code>' . esc_html( self::META_SYNCED ) . '</code>',
									esc_html( self::TAG_NAME )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wps_sdl"><?php esc_html_e( 'Limit szkiców', 'wc-product-sync' ); ?></label></th>
						<td>
							<input name="<?php echo esc_attr( self::OPTION_KEY ); ?>[soft_delete_limit]" id="wps_sdl" type="number" min="0" class="small-text"
								value="<?php echo esc_attr( $opts['soft_delete_limit'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Ile szkiców soft-delete zachować. Najstarsze ponad limit są trwale usuwane. 0 = bez limitu.', 'wc-product-sync' ); ?></p>
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
			<?php endif; ?>
			<p class="description">
				<?php printf( esc_html__( 'Logi: %s', 'wc-product-sync' ), '<a href="' . esc_url( admin_url( 'admin.php?page=wc-status&tab=logs' ) ) . '">WooCommerce → Status → Logi</a>' ); ?>
			</p>
		</div>
		<?php
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
		$stats   = $this->run_sync( $dry_run );
		wp_safe_redirect( add_query_arg(
			array(
				'page'    => 'wc-product-sync',
				'synced'  => 1,
				'created' => $stats['created'],
				'updated' => $stats['updated'],
				'skipped' => $stats['skipped'],
				'errors'  => $stats['errors'],
			),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public function run_sync_cron() {
		$this->run_sync( false );
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

	private function foreach_product( callable $callback ) {
		$page   = 1;
		$per_page = $this->cfg_per_page();
		$total_counted = 0;

		do {
			$batch = $this->fetch_product_page( $page );
			if ( is_wp_error( $batch ) ) {
				$this->fetch_had_error = true;
				$this->log( 'error', 'Pobieranie strony ' . $page . ': ' . $batch->get_error_message() );
				break;
			}
			$count = count( $batch );
			if ( 0 === $count ) {
				break;
			}
			$total_counted += $count;
			$this->log( 'info', sprintf( 'Pobrano stronę %d (%d produktów)', $page, $count ) );

			foreach ( $batch as $product ) {
				$callback( $product, $this );
			}

			unset( $batch );
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			$page++;
		} while ( $count === $per_page );

		return $total_counted;
	}

	private function fetch_source_attributes() {
		$out  = array();
		$list = $this->api_get( '/wp-json/wc/v3/products/attributes', array( 'per_page' => 100 ) );
		if ( is_wp_error( $list ) ) {
			$this->log( 'warning', 'Nie pobrano definicji atrybutów: ' . $list->get_error_message() );
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

		if ( get_transient( self::SYNC_LOCK_TRANSIENT ) ) {
			$this->log( 'warning', 'Synchronizacja już trwa (blokada transient) – przerywam.' );
			return $stats;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 );
		}

		set_transient( self::SYNC_LOCK_TRANSIENT, time(), 900 );

		try {
			return $this->run_sync_inner( $dry_run, $stats );
		} finally {
			delete_transient( self::SYNC_LOCK_TRANSIENT );
		}
	}

	private function run_sync_inner( $dry_run, &$stats ) {
		$this->log( 'info', '=== Start synchronizacji' . ( $dry_run ? ' [DRY RUN]' : '' ) . ' ===' );

		// Pełna synchronizacja – usuń stare lokalne produkty.
		$force_full = ! empty( $this->get_options()['force_full_sync'] );
		if ( $force_full && ! $dry_run ) {
			$this->log( 'info', 'PEŁNA SYNCHRONIZACJA: usuwanie lokalnych produktów oznaczonych przez sync...' );
			global $wpdb;
			$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::META_SYNCED . "'" );
			$ids = array_unique( array_map( 'absint', $ids ) );
			if ( ! empty( $ids ) ) {
				foreach ( $ids as $pid ) {
					$p = wc_get_product( $pid );
					if ( $p ) {
						$p->delete( true );
						$this->log( 'info', sprintf( 'Usunięto lokalny produkt ID=%d (full sync)', $pid ) );
					} else {
						wp_delete_post( $pid, true );
					}
				}
				$this->log( 'info', sprintf( 'Usunięto %d lokalnych produktów.', count( $ids ) ) );
			}
		}

		$this->attr_map_cache   = array();
		$this->source_id_to_sku = array();
		$this->fetch_had_error  = false;

		$this->source_attributes = $this->fetch_source_attributes();

		$source_count = 0;
		$source_keys  = array();
		$grouped_buf  = array();

		$this->foreach_product( function( $p ) use ( &$stats, &$source_count, &$source_keys, &$grouped_buf, $dry_run ) {
			$source_count++;

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

			if ( isset( $p['type'] ) && 'grouped' === $p['type'] ) {
				$grouped_buf[] = $p;
			} else {
				$this->process_single_product( $p, $dry_run, $stats );
			}
		} );

		$this->log( 'info', sprintf( 'Pobrano %d produktów, %d atrybutów globalnych', $source_count, count( $this->source_attributes ) ) );

		foreach ( $grouped_buf as $p ) {
			$this->process_single_product( $p, $dry_run, $stats );
		}
		unset( $grouped_buf );
		if ( function_exists( 'gc_collect_cycles' ) ) gc_collect_cycles();

		if ( ! empty( $this->get_options()['soft_delete_enabled'] ) ) {
			$this->soft_delete_missing( array_unique( $source_keys ), $source_count, $dry_run );
		}

		$this->log( 'info', sprintf(
			'=== Koniec: utworzono=%d, zaktualizowano=%d, pominięto=%d, błędy=%d ===',
			$stats['created'], $stats['updated'], $stats['skipped'], $stats['errors']
		) );

		return $stats;
	}

	private function process_single_product( array $p, $dry_run, &$stats ) {
		if ( ! $this->should_sync_status( $p['status'] ?? 'publish' ) ) {
			$stats['skipped']++;
			return;
		}
		try {
			$result = $this->dispatch_upsert( $p, $dry_run );
			if ( isset( $stats[ $result ] ) ) {
				$stats[ $result ]++;
			}
		} catch ( \Throwable $e ) {
			$stats['errors']++;
			$this->log( 'error', sprintf(
				"Błąd dla '%s' (SKU=%s): %s",
				$p['name'] ?? '?',
				$p['sku'] ?? '',
				$e->getMessage()
			) );
		}
	}

	private function dispatch_upsert( array $p, $dry_run ) {
		$type = $p['type'] ?? 'simple';
		switch ( $type ) {
			case 'simple':
				return $this->upsert_simple( $p, $dry_run );
			case 'variable':
				return $this->upsert_variable( $p, $dry_run );
			case 'grouped':
				return $this->upsert_grouped( $p, $dry_run );
			default:
				$this->log( 'warning', sprintf( "Pominięto '%s' – typ '%s' nieobsługiwany.", $p['name'] ?? '?', $type ) );
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
	 * Szuka istniejącego produktu na celzie: najpierw po SKU, potem po source_id,
	 * na końcu po nazwie (fallback).
	 */
	private function find_existing_product( array $p ) {
		global $wpdb;

		$sku = $this->require_sku( $p );
		if ( '' !== $sku ) {
			$id = self::sku_to_id( $sku );
			if ( $id ) {
				return $id;
			}
		}

		// 2) Lookup po source_id z bazy — 100% trafne.
		if ( ! empty( $p['id'] ) ) {
			$src_id = absint( $p['id'] );
			$id     = self::source_id_to_local( $src_id );
			if ( $id ) {
				return $id;
			}
		}

		// 3) Fallback: szukaj po nazwie.
		//    Działa dla produktów, które nie mają _wps_source_id (np. ręcznie
		//    utworzone na celcu o tej samej nazwie). Jeśli produkt ma już
		//    _wps_source_id ale NIE pasuje do źródła, traktujemy go jako
		//    inny produkt i tworzymy nowy — nie podmieniamy cudzego.
		$name = isset( $p['name'] ) ? trim( $p['name'] ) : '';
		if ( '' !== $name ) {
			$found = get_posts( array(
				'post_type'      => 'product',
				'post_title'     => $name,
				'post_status'    => 'publish',
				'posts_per_page' => 2,
				'fields'         => 'ids',
			) );
			if ( $found && count( $found ) === 1 ) {
				return (int) $found[0];
			}
		}

		return 0;
	}

	private function apply_common_fields( $product, array $p ) {
		$product->set_name( (string) ( $p['name'] ?? '' ) );
		$status = $p['status'] ?? 'publish';
		$product->set_status( in_array( $status, array( 'publish', 'draft', 'pending', 'private' ), true ) ? $status : 'publish' );
		$product->set_description( (string) ( $p['description'] ?? '' ) );
		$product->set_short_description( (string) ( $p['short_description'] ?? '' ) );
		if ( ! empty( $p['categories'] ) && is_array( $p['categories'] ) ) {
			$ids = $this->resolve_category_ids( $p['categories'] );
			if ( $ids ) {
				$product->set_category_ids( $ids );
			}
		}
	}

	private function apply_physical( $obj, array $src ) {
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

		$product = $this->ensure_product_type( $existing_id, $wanted_class, $wanted_term );
		$this->apply_common_fields( $product, $p );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( 'WC_Product_Simple' === $wanted_class ) {
			$product->set_regular_price( isset( $p['regular_price'] ) ? (string) $p['regular_price'] : '' );
			$product->set_sale_price( isset( $p['sale_price'] ) ? (string) $p['sale_price'] : '' );
			$this->apply_stock( $product, $p );
		} elseif ( 'WC_Product_Variable' === $wanted_class ) {
			if ( ! empty( $p['stock_status'] ) ) {
				$product->set_stock_status( $p['stock_status'] );
			}
			$product->set_attributes( $this->build_parent_attributes( $p['attributes'] ?? array() ) );
		}
		$this->apply_physical( $product, $p );

		$id = $product->save();
		if ( ! $id ) {
			throw new \RuntimeException( 'save() zwróciło 0' );
		}
		update_post_meta( $id, self::META_SOURCE_ID, (int) $p['id'] );
		$this->mark_synced( $id );
		$this->log( 'info', sprintf( 'Zaktualizowano %s: %s (ID=%d)', $wanted_term, $p['name'], $id ) );
		return 'updated';
	}

	/** Tworzy nowy produkt i zapisuje go na celzie. */
	private function create_new_product( $wanted_class, $wanted_term, array $p, $dry_run, $sku ) {
		if ( $dry_run ) {
			if ( 'WC_Product_Variable' === $wanted_class ) {
				$vars = $this->fetch_variations( (int) $p['id'] );
				$this->log( 'info', sprintf( '[DRY] CREATE %s: %s (SKU=%s, wariacji=%d)', $wanted_term, $p['name'], $sku ?: '(brak)', count( $vars ) ) );
			} else {
				$this->log( 'info', sprintf( '[DRY] CREATE %s: %s (SKU=%s)', $wanted_term, $p['name'], $sku ?: '(brak)' ) );
			}
			return 'created';
		}

		$product = new $wanted_class();
		$this->apply_common_fields( $product, $p );
		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}
		if ( 'WC_Product_Simple' === $wanted_class ) {
			$product->set_regular_price( isset( $p['regular_price'] ) ? (string) $p['regular_price'] : '' );
			$product->set_sale_price( isset( $p['sale_price'] ) ? (string) $p['sale_price'] : '' );
			$this->apply_stock( $product, $p );
		} elseif ( 'WC_Product_Variable' === $wanted_class ) {
			if ( ! empty( $p['stock_status'] ) ) {
				$product->set_stock_status( $p['stock_status'] );
			}
			$product->set_attributes( $this->build_parent_attributes( $p['attributes'] ?? array() ) );
		}
		$this->apply_physical( $product, $p );

		$id = $product->save();
		if ( ! $id ) {
			throw new \RuntimeException( 'save() zwróciło 0' );
		}
		update_post_meta( $id, self::META_SOURCE_ID, (int) $p['id'] );
		if ( ! empty( $p['images'] ) ) {
			$this->set_product_images( $id, $p['images'] );
		}
		if ( 'WC_Product_Variable' === $wanted_class ) {
			$this->sync_variations( $id, (int) $p['id'] );
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
				$map = $this->map_global_attribute( (int) $a['id'] );
				if ( ! $map ) {
					continue;
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

	private function map_global_attribute( $source_attr_id ) {
		$src = $this->source_attributes[ $source_attr_id ] ?? null;
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

	private function sync_variations( $target_parent_id, $source_parent_id ) {
		$this->variations_fetch_error = false;
		$source_vars = $this->fetch_variations( $source_parent_id );
		$parent      = wc_get_product( $target_parent_id );
		if ( ! $parent ) {
			return;
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

		$kept = array();
		foreach ( $source_vars as $sv ) {
			try {
				$svsku   = isset( $sv['sku'] ) ? trim( $sv['sku'] ) : '';
				$attrs   = $this->build_variation_attributes( $sv['attributes'] ?? array() );
				$sig     = $this->signature( $attrs );
				$vid     = null;

				if ( $svsku && isset( $by_sku[ $svsku ] ) ) {
					$vid = $by_sku[ $svsku ];
				} elseif ( isset( $by_sig[ $sig ] ) ) {
					$vid = $by_sig[ $sig ];
				}

				$is_update = (bool) $vid;
				$variation = $vid ? wc_get_product( $vid ) : new WC_Product_Variation();
				if ( ! $variation ) {
					$variation = new WC_Product_Variation();
				}
				$variation->set_parent_id( $target_parent_id );
				if ( $svsku ) {
					$variation->set_sku( $svsku );
				}
				$variation->set_status( ( $sv['status'] ?? 'publish' ) === 'private' ? 'private' : 'publish' );
				$variation->set_regular_price( isset( $sv['regular_price'] ) ? (string) $sv['regular_price'] : '' );
				$variation->set_sale_price( isset( $sv['sale_price'] ) ? (string) $sv['sale_price'] : '' );
				$this->apply_stock( $variation, $sv );
				$this->apply_physical( $variation, $sv );
				$variation->set_attributes( $attrs );

				$new_vid = $variation->save();
				if ( ! $is_update && ! empty( $sv['image']['src'] ) ) {
					$att = $this->sideload_single( $sv['image']['src'], $new_vid );
					if ( $att ) {
						$variation->set_image_id( $att );
						$variation->save();
					}
				}

				$kept[ $new_vid ] = true;
			} catch ( \Throwable $e ) {
				$this->log( 'warning', sprintf( 'Wariacja (SKU=%s) rodzica %d: %s', $sv['sku'] ?? '', $target_parent_id, $e->getMessage() ) );
			}
		}

		if ( ! $this->variations_fetch_error ) {
			foreach ( $parent->get_children() as $vid ) {
				if ( empty( $kept[ $vid ] ) ) {
					$stale = wc_get_product( $vid );
					if ( $stale ) {
						$stale->delete( true );
						$this->log( 'info', sprintf( 'Usunięto nieaktualną wariację ID=%d (rodzic %d)', $vid, $target_parent_id ) );
					}
				}
			}
		} else {
			$this->log( 'warning', sprintf( 'Błąd pobierania wariacji rodzica %d – pomijam usuwanie dzieci.', $target_parent_id ) );
		}
	}

	private function build_variation_attributes( array $source_attrs ) {
		$out = array();
		foreach ( $source_attrs as $a ) {
			if ( ! empty( $a['id'] ) && (int) $a['id'] > 0 ) {
				$map = $this->map_global_attribute( (int) $a['id'] );
				if ( ! $map ) {
					continue;
				}
				$term = get_term_by( 'name', $a['option'] ?? '', $map['taxonomy'] );
				$out[ $map['taxonomy'] ] = $term ? $term->slug : sanitize_title( $a['option'] ?? '' );
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
			$child_sku = $this->source_id_to_sku[ (int) $child_source_id ] ?? '';
			if ( ! $child_sku ) {
				$this->log( 'warning', sprintf( "Grouped '%s': dziecko src_id=%d bez SKU – pomijam.", $p['name'] ?? '?', $child_source_id ) );
				continue;
			}
			$local = self::sku_to_id( $child_sku );
			if ( $local ) {
				$child_ids[] = $local;
			} else {
				$this->log( 'warning', sprintf( "Grouped '%s': brak lokalnego dziecka SKU=%s.", $p['name'] ?? '?', $child_sku ) );
			}
		}

		if ( $dry_run ) {
			$this->log( 'info', sprintf( '[DRY] %s grouped: %s (dzieci=%d)', $is_update ? 'UPDATE' : 'CREATE', $p['name'] ?? '?', count( $child_ids ) ) );
			return $is_update ? 'updated' : 'created';
		}

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
		update_post_meta( $id, self::META_SOURCE_ID, (int) $p['id'] );
		if ( ! $is_update && ! empty( $p['images'] ) ) {
			$this->set_product_images( $id, $p['images'] );
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

	private function set_product_images( $product_id, array $images ) {
		$attachment_ids = array();
		foreach ( $images as $img ) {
			$src = isset( $img['src'] ) ? esc_url_raw( $img['src'] ) : '';
			if ( '' === $src ) {
				continue;
			}
			$att = $this->sideload_single( $src, $product_id );
			if ( $att ) {
				$attachment_ids[] = $att;
			}
		}
		if ( ! $attachment_ids ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$product->set_image_id( array_shift( $attachment_ids ) );
		if ( $attachment_ids ) {
			$product->set_gallery_image_ids( $attachment_ids );
		}
		$product->save();
	}

	private function sideload_single( $src, $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$att = media_sideload_image( esc_url_raw( $src ), $post_id, null, 'id' );
		if ( is_wp_error( $att ) ) {
			$this->log( 'warning', 'Obraz pominięty (' . $src . '): ' . $att->get_error_message() );
			return 0;
		}
		return (int) $att;
	}

	/* =====================================================================
	 *  Soft-delete
	 * ================================================================== */

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

		do {
			$batch = get_posts( array(
				'fields'         => 'ids',
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'pending', 'private' ),
				'posts_per_page' => 100,
				'paged'          => $page,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => self::META_SYNCED,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_SOFT_DELETED,
						'compare' => 'NOT EXISTS',
					),
				),
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

		if ( ! $candidates ) {
			$this->enforce_soft_delete_limit( $dry_run );
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
		$allowed = apply_filters( 'wps_sync_statuses', array( 'publish' ), $source_status );
		return in_array( $source_status, (array) $allowed, true );
	}

	/** Resolve SKU → product ID via direct SQL — bypasses all WC
	 *  caching layers (deferred indexing in v9+, object cache, etc.).
	 *  Always reads fresh data from the database. */
	private static function sku_to_id( $sku ) {
		global $wpdb;
		if ( '' === trim( $sku ) ) {
			return 0;
		}
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				trim( $sku )
			)
		);
		return $id ? absint( $id ) : 0;
	}

	/* =====================================================================
	 *  Logi
	 * ================================================================== */

	private function log( $level, $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
		}
	}
}

register_activation_hook( __FILE__, array( 'WC_Product_Sync', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WC_Product_Sync', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'WC_Product_Sync', 'instance' ) );
