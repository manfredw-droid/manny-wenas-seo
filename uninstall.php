<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package MannyWenasSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

wp_clear_scheduled_hook( 'mwseo_weekly' );

delete_option( 'mwseo_options' );
delete_option( 'mwseo_gsc' );
delete_option( 'mwseo_llms_txt' );
delete_transient( 'mwseo_gsc_sitemap_sent' );

// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mwseo_rank" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_mwseo\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mwseo\_gsc\_%' OR option_name LIKE '\_transient\_timeout\_mwseo\_gsc\_%'" );
// Clean up Trends transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_mwseo\_trends\_%' OR option_name LIKE '\_transient\_timeout\_mwseo\_trends\_%'" );
// phpcs:enable WordPress.DB.DirectDatabaseQuery
