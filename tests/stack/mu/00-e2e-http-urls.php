<?php
/**
 * Test-rig only. Loaded as an mu-plugin on the SOURCE store.
 *
 * up.sh sets $_SERVER['HTTPS'] = 'on' in the source's wp-config, because WooCommerce only
 * accepts consumer key/secret Basic auth when is_ssl() is true — and this stack is plain
 * HTTP. The side effect is that WP then generates https:// URLs for everything, including
 * the image `src` it hands back over the REST API. The target dutifully tries to sideload
 * https://src-wp/... , there is no TLS listener, and images silently do not sync (the sync
 * still reports 0 errors, which is how this hid).
 *
 * So: keep the shim, and force every generated URL back to http.
 */

add_filter( 'wp_get_attachment_url', static function ( $url ) {
	return set_url_scheme( $url, 'http' );
}, 99 );

add_filter( 'wp_get_attachment_image_src', static function ( $image ) {
	if ( is_array( $image ) && ! empty( $image[0] ) ) {
		$image[0] = set_url_scheme( $image[0], 'http' );
	}
	return $image;
}, 99 );

foreach ( array( 'home_url', 'site_url' ) as $hook ) {
	add_filter( $hook, static function ( $url ) {
		return set_url_scheme( $url, 'http' );
	}, 99 );
}
