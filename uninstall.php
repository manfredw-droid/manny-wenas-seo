<?php
/**
 * Remove Free-owned operational state, preserving information on uninstall.
 *
 * @package MannyWenasSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'mwseo_weekly' );

// Keep configuration, article metadata (including scores), and extension data.
// mwseo_rank is Pro-owned ranking history, not a disposable Free cache.
delete_option( 'mwseo_gsc' );
delete_option( 'mwseo_llms_txt' );
delete_post_meta_by_key( '_mwseo_indexnow_pinged' );
delete_transient( 'mwseo_sitemap_cache' );
delete_transient( 'mwseo_rewrite_missing' );

// Only these MD5-suffixed cache names are written by Free's GSC/Trends code.
// Do not sweep unknown extension keys merely because they share a prefix.
$cache_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name REGEXP %s", // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		'^_transient_(timeout_)?mwseo_(gsc|trends)_[a-f0-9]{32}$'
	)
);
$cache_names = array_unique( preg_replace( '/^_transient_(?:timeout_)?/', '', $cache_options ) );
foreach ( $cache_names as $cache_name ) {
	delete_transient( $cache_name );
	// Also remove old database values when WordPress uses an external cache.
	delete_option( '_transient_' . $cache_name );
	// Core only deletes an expiry row when the corresponding value still exists.
	delete_option( '_transient_timeout_' . $cache_name );
}
