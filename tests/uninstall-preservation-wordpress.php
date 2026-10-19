<?php
/**
 * Actual WordPress lifecycle/persistence tests. No WP mocks or browser required.
 * Run: php -c <test.ini> tests/uninstall-preservation-wordpress.php <bootstrap> [bootstrap-config]
 * The bootstrap must create a fresh, disposable, single-site database/prefix,
 * load WordPress, and set WP_PLUGIN_DIR to the parent of this plugin directory.
 * NEVER point this at a live site: the entire dedicated test prefix is removed.
 */
if ( empty( $argv[1] ) || ! is_file( $argv[1] ) ) { fwrite( STDERR, "Provide an isolated WordPress bootstrap.\n" ); exit( 2 ); }
$bootstrap = $argv[1];
// A local bootstrap may accept its config as argv[1]. Keep it out of repository code.
$argv[1] = $argv[2] ?? null;
require $bootstrap;
if ( ! defined( 'MWSEO_UNINSTALL_TEST_SITE' ) || true !== MWSEO_UNINSTALL_TEST_SITE ||
	is_multisite() || ! preg_match( '/^mwseo_uninstall_test_[pc]_[a-f0-9]{8}_$/', $wpdb->prefix ) ) {
	fwrite( STDERR, "Refusing to run outside the dedicated disposable test site.\n" ); exit( 2 );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$source = dirname( __DIR__ );
$plugin = basename( $source ) . '/manny-wenas-seo.php';
if ( realpath( WP_PLUGIN_DIR . '/' . $plugin ) !== realpath( $source . '/manny-wenas-seo.php' ) ) {
	fwrite( STDERR, "Installed test plugin does not match this checkout.\n" ); exit( 2 );
}
$prefix = $wpdb->prefix;
$checks = array();
function verify( $name, $pass ) { $GLOBALS['checks'][] = array( 'name' => $name, 'passed' => (bool) $pass ); }
function meta_snapshot( $ids ) {
	global $wpdb;
	$out = array();
	foreach ( $ids as $id ) { $out[ $id ] = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key<>%s ORDER BY meta_id", $id, '_mwseo_indexnow_pinged' ), ARRAY_A ); }
	return $out;
}
$report = array( 'kind' => 'Actual WordPress lifecycle and database tests', 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'prefix' => $prefix );
try {
	register_post_type( 'mwseo_owned_fixture', array( 'public' => true, 'supports' => array( 'title', 'editor', 'custom-fields' ) ) );
	$seed = array(
		'_mwseo_title' => 'An authored title', '_mwseo_desc' => 'An authored description',
		'_mwseo_focus' => 'natural writing', '_mwseo_related' => array( 'clear ideas', 'reader trust' ),
		'_mwseo_noindex' => '1', '_mwseo_nofollow' => '0', '_mwseo_cornerstone' => '1', '_mwseo_score' => '57',
		'_mwseo_faq' => 'Question|Answer', '_mwseo_video' => 'https://example.test/video', '_mwseo_review' => array( 'Book', '5', '5' ),
		'_mwseo_extension_unknown' => array( 'owned' => 'elsewhere' ), '_mwseo_indexnow_pinged_extra' => 'keep',
		'_mw_seo_description' => 'Historical imported information', '_other_editorial' => 'keep',
	);
	$ids = array();
	foreach ( array( array( 'post', 'publish' ), array( 'post', 'draft' ), array( 'page', 'private' ), array( 'mwseo_owned_fixture', 'publish' ) ) as $fixture ) {
		$id = wp_insert_post( array( 'post_type' => $fixture[0], 'post_status' => $fixture[1], 'post_title' => 'Uninstall preservation fixture', 'post_content' => '<p>Writer-owned content.</p>', 'post_author' => 1 ), true );
		if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
		$ids[] = $id;
		foreach ( $seed as $key => $value ) { add_post_meta( $id, $key, $value ); }
		add_post_meta( $id, '_mwseo_extension_unknown', 'Second independent record' );
		add_post_meta( $id, '_mwseo_indexnow_pinged', time() );
	}
	$config = array( 'org_name' => 'Owner organization', 'title_template' => 'Authored template', 'post_types' => array( 'post', 'page', 'mwseo_owned_fixture' ), 'pro_legacy_setting' => 'keep', 'indexnow_key' => 'existing-fixture-key' );
	update_option( 'mwseo_options', $config );
	update_option( 'mwseo_pro_options', array( 'report_email' => 'owner@example.test' ) );
	$table = $prefix . 'mwseo_rank';
	$created = $wpdb->query( "CREATE TABLE {$table} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT, post_id bigint(20) unsigned NOT NULL, week date NOT NULL, clicks int(11) NOT NULL DEFAULT 0, impressions int(11) NOT NULL DEFAULT 0, avg_position float NOT NULL DEFAULT 0, PRIMARY KEY(id), UNIQUE KEY post_week(post_id,week))" );
	if ( false === $created ) { throw new RuntimeException( 'Could not create isolated rank-history fixture.' ); }
	$wpdb->insert( $table, array( 'post_id' => $ids[0], 'week' => '2026-10-12', 'clicks' => 12, 'impressions' => 200, 'avg_position' => 4.5 ) );
	$rank_before = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A );
	$before = meta_snapshot( $ids );
	$activation = activate_plugin( $plugin );
	if ( is_wp_error( $activation ) ) { throw new RuntimeException( $activation->get_error_message() ); }
	verify( 'native activation succeeds', is_plugin_active( $plugin ) );
	verify( 'activation preserves every raw editorial record', $before === meta_snapshot( $ids ) );
	deactivate_plugins( $plugin );
	verify( 'native deactivation succeeds', ! is_plugin_active( $plugin ) );
	verify( 'deactivation preserves every raw editorial record', $before === meta_snapshot( $ids ) );
	verify( 'deactivation preserves rank history', $rank_before === $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ) );
	$reactivation = activate_plugin( $plugin );
	verify( 'native reactivation succeeds', ! is_wp_error( $reactivation ) && is_plugin_active( $plugin ) );
	verify( 'reactivation preserves every raw editorial record', $before === meta_snapshot( $ids ) );
	verify( 'reactivation preserves authored configuration', get_option( 'mwseo_options' ) === $config );
	deactivate_plugins( $plugin );
	update_option( 'mwseo_gsc', array( 'access_token' => 'fixture-token', 'refresh_token' => 'fixture-refresh' ) );
	update_option( 'mwseo_llms_txt', 'old compatibility snapshot' );
	wp_schedule_event( time() + 3600, 'weekly', 'mwseo_weekly' );
	$caches = array( 'mwseo_sitemap_cache', 'mwseo_rewrite_missing', 'mwseo_gsc_' . md5( 'url' ), 'mwseo_trends_' . md5( 'phrase' ) );
	$retained = array( 'mwseo_gsc_extension', 'mwseo_trends_' . str_repeat( 'a', 31 ), 'mwseo_gsc_sitemap_sent', 'mwseo_pro_cache', 'other_cache' );
	foreach ( array_merge( $caches, $retained ) as $key ) { set_transient( $key, 'fixture cache', 3600 ); }
	$orphan = '_transient_timeout_mwseo_trends_' . md5( 'orphan' );
	update_option( $orphan, time() + 3600 );
	uninstall_plugin( $plugin );
	verify( 'WordPress invoked the guarded uninstall file', defined( 'WP_UNINSTALL_PLUGIN' ) && WP_UNINSTALL_PLUGIN === $plugin );
	$after = meta_snapshot( $ids );
	verify( 'uninstall preserves all raw editorial rows byte-for-byte', $before === $after );
	foreach ( $seed as $key => $value ) {
		$intact = true;
		foreach ( $ids as $id ) { clean_post_cache( $id ); if ( ! metadata_exists( 'post', $id, $key ) || get_post_meta( $id, $key, true ) !== $value ) { $intact = false; } }
		verify( 'all post types/statuses retain ' . $key, $intact );
	}
	foreach ( $ids as $id ) { verify( 'IndexNow operational timestamp removed from ' . $id, ! metadata_exists( 'post', $id, '_mwseo_indexnow_pinged' ) ); }
	verify( 'rank table and all history rows preserved', $rank_before === $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A ) );
	verify( 'authored and unknown configuration preserved', get_option( 'mwseo_options' ) === $config );
	verify( 'Pro option preserved', get_option( 'mwseo_pro_options' ) === array( 'report_email' => 'owner@example.test' ) );
	verify( 'weekly operational event removed', false === wp_next_scheduled( 'mwseo_weekly' ) );
	verify( 'GSC operational tokens removed', false === get_option( 'mwseo_gsc' ) );
	verify( 'llms compatibility snapshot removed', false === get_option( 'mwseo_llms_txt' ) );
	foreach ( $caches as $key ) { verify( 'Free cache removed: ' . $key, false === get_transient( $key ) && false === get_option( '_transient_timeout_' . $key ) ); }
	foreach ( $retained as $key ) { verify( 'unknown/extension transient preserved: ' . $key, 'fixture cache' === get_transient( $key ) ); }
	verify( 'orphan Free cache timeout removed', false === get_option( $orphan ) );
	// Exercise core's object-cache branch with the built-in in-memory backend.
	// This is not a Redis/Memcached or cross-request persistence test.
	$mixed = 'mwseo_gsc_' . md5( 'cache-backend-switch' );
	set_transient( $mixed, 'old database value', 3600 );
	wp_using_ext_object_cache( true );
	wp_cache_set( $mixed, 'object cache value', 'transient', 3600 );
	wp_cache_set( 'unrelated_cache', 'preserve object cache value', 'transient', 3600 );
	include $source . '/uninstall.php';
	verify( 'known object-cache entry removed', false === wp_cache_get( $mixed, 'transient' ) );
	verify( 'unrelated object-cache entry preserved', 'preserve object cache value' === wp_cache_get( 'unrelated_cache', 'transient' ) );
	wp_using_ext_object_cache( false );
	verify( 'old database cache value and timeout both removed', false === get_option( '_transient_' . $mixed ) && false === get_option( '_transient_timeout_' . $mixed ) );
	verify( 'repeated cleanup preserves every editorial row', $before === meta_snapshot( $ids ) );
	$reinstall = activate_plugin( $plugin );
	verify( 'same-version reinstall/reactivation succeeds', ! is_wp_error( $reinstall ) && is_plugin_active( $plugin ) );
	verify( 'same-version reinstall preserves raw editorial rows', $before === meta_snapshot( $ids ) );
	MWSEO_Options::flush();
	foreach ( MWSEO_Meta::fields() as $key => $definition ) {
		$expected = $seed[ '_mwseo_' . $key ];
		if ( 'boolean' === $definition[0] ) { $expected = '1' === $expected; }
		if ( 'integer' === $definition[0] ) { $expected = (int) $expected; }
		verify( 'reinstalled native accessor reads ' . $key, MWSEO_Meta::get( $ids[0], $key ) === $expected );
	}
	$report['before'] = $before;
	$report['after'] = $after;
	$report['rank_before'] = $rank_before;
	$report['rank_after'] = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id", ARRAY_A );
} catch ( Throwable $e ) {
	$report['error'] = $e->getMessage();
	verify( 'runtime test completed without exception', false );
} finally {
	// Bootstrap created only this unique prefix. Never drop existing site tables.
	$tables = $wpdb->get_col( 'SHOW TABLES' );
	$removed = array();
	foreach ( $tables as $name ) {
		if ( 0 === strpos( $name, $prefix ) && preg_match( '/^[a-z0-9_]+$/', $name ) ) { $removed[ $name ] = false !== $wpdb->query( 'DROP TABLE `' . $name . '`' ); }
	}
	$report['cleanup'] = $removed;
	verify( 'all isolated fixture tables removed', count( $removed ) >= 13 && ! in_array( false, $removed, true ) );
}
$report['checks'] = $checks;
$report['passed'] = count( array_filter( $checks, static function ( $c ) { return $c['passed']; } ) );
$report['failed'] = count( $checks ) - $report['passed'];
echo json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
exit( $report['failed'] ? 1 : 0 );
