<?php
/**
 * Focused lifecycle regression checks using WordPress doubles, not a WP site.
 * Run: php tests/uninstall-preservation.php [plugin-directory]
 */
$source = isset( $argv[1] ) ? $argv[1] : dirname( __DIR__ );
if ( '--guard' === $source ) {
	// Any cleanup call without the uninstall constant will be an undefined function.
	require $argv[2] . '/uninstall.php';
	exit( 9 );
}
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_UNINSTALL_PLUGIN', 'manny-wenas-seo/manny-wenas-seo.php' );
define( 'HOUR_IN_SECONDS', 3600 );
$state = array( 'meta' => array(), 'options' => array(), 'cron' => true, 'rank' => array( array( 'post_id' => 42, 'clicks' => 12 ) ) );
function __( $s, $domain = null ) { return $s; }
function get_bloginfo( $key ) { return 'Test site'; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, $args ); }
function get_option( $key, $default = false ) { return $GLOBALS['state']['options'][ $key ] ?? $default; }
function add_option( $key, $value, $deprecated = '', $autoload = null ) { $GLOBALS['state']['options'][ $key ] = $value; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['state']['options'][ $key ] = $value; }
function delete_option( $key ) { $exists = array_key_exists( $key, $GLOBALS['state']['options'] ); unset( $GLOBALS['state']['options'][ $key ] ); return $exists; }
function delete_transient( $key ) { if ( delete_option( '_transient_' . $key ) ) { delete_option( '_transient_timeout_' . $key ); } }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['state']['meta'][ $id ][ $key ] ?? ''; }
function metadata_exists( $type, $id, $key ) { return array_key_exists( $key, $GLOBALS['state']['meta'][ $id ] ?? array() ); }
function delete_post_meta_by_key( $key ) { foreach ( $GLOBALS['state']['meta'] as &$row ) { unset( $row[ $key ] ); } }
function wp_clear_scheduled_hook( $hook ) { if ( 'mwseo_weekly' === $hook ) { $GLOBALS['state']['cron'] = false; } }
function wp_next_scheduled( $hook ) { return $GLOBALS['state']['cron']; }
function wp_schedule_event( $timestamp, $schedule, $hook ) { $GLOBALS['state']['cron'] = true; }
function flush_rewrite_rules() {}
class MWSEO_Compat { public static function owns_title_fields() { return false; } }
class MWSEO_Indexnow { public static function ensure_key() { return 'existing-key'; } }
class MWSEO_Sitemaps { public static function add_rewrites() {} }
class MWSEO_Setup { public static function flag_new_install( $is_new ) { $GLOBALS['state']['setup_flag'] = (bool) $is_new; } }
class MWSEO_Llms_Txt { public static function add_rewrites() {} public static function regenerate() { update_option( 'mwseo_llms_txt', 'regenerated cache' ); } }
class Uninstall_Test_DB {
	public $prefix = 'test_';
	public $options = 'test_options';
	public $postmeta = 'test_postmeta';
	public function prepare( $sql, $value ) { return str_replace( '%s', "'" . $value . "'", $sql ); }
	public function get_col( $sql ) {
		if ( ! preg_match( "/REGEXP '([^']+)'/", $sql, $match ) ) { throw new RuntimeException( 'Unexpected query' ); }
		return array_values( preg_grep( '/' . $match[1] . '/', array_keys( $GLOBALS['state']['options'] ) ) );
	}
	public function query( $sql ) {
		// Simulate the destructive baseline only; never touch a real database.
		if ( false !== strpos( $sql, 'DROP TABLE' ) ) { $GLOBALS['state']['rank'] = null; }
		if ( false !== strpos( $sql, 'DELETE FROM test_postmeta' ) ) {
			foreach ( $GLOBALS['state']['meta'] as &$row ) { foreach ( array_keys( $row ) as $key ) { if ( 0 === strpos( $key, '_mwseo_' ) ) { unset( $row[ $key ] ); } } }
		}
		if ( false !== strpos( $sql, 'DELETE FROM test_options' ) ) {
			foreach ( array_keys( $GLOBALS['state']['options'] ) as $key ) { if ( preg_match( '/^_transient_(timeout_)?mwseo_(gsc|trends)_/', $key ) ) { delete_option( $key ); } }
		}
	}
}
$wpdb = new Uninstall_Test_DB();
require $source . '/includes/class-mwseo-meta.php';
require $source . '/includes/class-mwseo-options.php';
require $source . '/includes/class-mwseo-plugin.php';
$seed = array(
	'_mwseo_title' => 'An authored title', '_mwseo_desc' => 'An authored description',
	'_mwseo_focus' => 'natural writing', '_mwseo_related' => array( 'clear ideas', 'reader trust' ),
	'_mwseo_noindex' => '1', '_mwseo_nofollow' => '0', '_mwseo_cornerstone' => '1', '_mwseo_score' => '57',
	'_mwseo_faq' => 'Question|Answer', '_mwseo_video' => 'https://example.test/video', '_mwseo_review' => array( 'Book', '5', '5' ),
	'_mwseo_extension_unknown' => array( 'owned' => 'elsewhere' ), '_mwseo_indexnow_pinged_extra' => 'preserve',
	'_mw_seo_description' => 'Historical imported information', '_other_editorial' => 'preserve',
);
foreach ( array( 42, 43 ) as $id ) { $state['meta'][ $id ] = $seed + array( '_mwseo_indexnow_pinged' => '1700000000' ); }
$configuration = array( 'org_name' => 'Author organization', 'title_template' => 'Custom template', 'post_types' => array( 'post' ), 'pro_legacy_setting' => 'preserve' );
$state['options'] = array( 'mwseo_options' => $configuration, 'mwseo_pro_options' => array( 'report_email' => 'owner@example.test' ), 'mwseo_gsc' => array( 'access_token' => 'test-only' ), 'mwseo_llms_txt' => 'cached body' );
$caches = array( 'mwseo_sitemap_cache', 'mwseo_rewrite_missing', 'mwseo_gsc_' . md5( 'url' ), 'mwseo_trends_' . md5( 'phrase' ) );
$retained = array( 'mwseo_gsc_extension', 'mwseo_trends_' . str_repeat( 'a', 31 ), 'mwseo_gsc_sitemap_sent', 'mwseo_pro_cache', 'other_cache' );
foreach ( array_merge( $caches, $retained ) as $key ) { $state['options'][ '_transient_' . $key ] = 'cache'; $state['options'][ '_transient_timeout_' . $key ] = 2000000000; }
$orphan = '_transient_timeout_mwseo_trends_' . md5( 'orphan' );
$state['options'][ $orphan ] = 2000000000;
$rank_before = $state['rank'];
$checks = array();
function check( $name, $passed ) { $GLOBALS['checks'][] = array( 'name' => $name, 'passed' => (bool) $passed ); echo ( $passed ? 'PASS ' : 'FAIL ' ) . $name . PHP_EOL; }
MWSEO_Plugin::deactivate();
check( 'deactivation preserves article information', $state['meta'][42] === $seed + array( '_mwseo_indexnow_pinged' => '1700000000' ) );
MWSEO_Plugin::activate();
check( 'reactivation preserves article information', $state['meta'][42] === $seed + array( '_mwseo_indexnow_pinged' => '1700000000' ) );
check( 'reactivation preserves authored configuration', get_option( 'mwseo_options' ) === $configuration );
check( 'reactivation with stored options is not flagged as a new installation', false === $state['setup_flag'] );
require $source . '/uninstall.php';
foreach ( $seed as $key => $value ) { foreach ( array( 42, 43 ) as $id ) { check( $id . ' preserves ' . $key, array_key_exists( $key, $state['meta'][ $id ] ) && $state['meta'][ $id ][ $key ] === $value ); } }
check( 'rank history preserved', $state['rank'] === $rank_before );
check( 'configuration preserved', get_option( 'mwseo_options' ) === $configuration );
check( 'extension option preserved', get_option( 'mwseo_pro_options' ) === array( 'report_email' => 'owner@example.test' ) );
check( 'weekly runtime job removed', ! $state['cron'] );
check( 'OAuth runtime tokens removed', false === get_option( 'mwseo_gsc' ) );
check( 'llms snapshot removed', false === get_option( 'mwseo_llms_txt' ) );
check( 'IndexNow timestamp removed from first article', ! array_key_exists( '_mwseo_indexnow_pinged', $state['meta'][42] ) );
check( 'IndexNow timestamp removed from second article', ! array_key_exists( '_mwseo_indexnow_pinged', $state['meta'][43] ) );
foreach ( $caches as $key ) { check( 'disposable cache removed: ' . $key, false === get_option( '_transient_' . $key ) && false === get_option( '_transient_timeout_' . $key ) ); }
foreach ( $retained as $key ) { check( 'unknown/extension cache retained: ' . $key, 'cache' === get_option( '_transient_' . $key ) && 2000000000 === get_option( '_transient_timeout_' . $key ) ); }
check( 'orphan Free cache timeout removed', false === get_option( $orphan ) );
MWSEO_Options::flush();
foreach ( MWSEO_Meta::fields() as $key => $definition ) {
	$expected = $seed[ '_mwseo_' . $key ];
	if ( 'boolean' === $definition[0] ) { $expected = '1' === $expected; }
	if ( 'integer' === $definition[0] ) { $expected = (int) $expected; }
	check( 'reinstalled accessor reads ' . $key, MWSEO_Meta::get( 42, $key ) === $expected );
}
$pipes = array();
$guard = proc_open( array( PHP_BINARY, '-n', __FILE__, '--guard', $source ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( is_resource( $guard ) ) { fclose( $pipes[0] ); $stdout = stream_get_contents( $pipes[1] ); $stderr = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] ); check( 'direct invocation exits before cleanup', 0 === proc_close( $guard ) && '' === $stdout && '' === $stderr ); }
else { check( 'direct invocation guard could be tested', false ); }
$failed = count( array_filter( $checks, static function ( $c ) { return ! $c['passed']; } ) );
echo ( count( $checks ) - $failed ) . ' passed; ' . $failed . ' failed. Isolated doubles, not WordPress runtime.' . PHP_EOL;
exit( $failed ? 1 : 0 );
