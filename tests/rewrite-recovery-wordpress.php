<?php
/**
 * Sitemap rewrite recovery with real HTTP requests.
 *
 * Disposable site only: table prefix `equinox_`, tests/mwseo-test-fixture.php copied to wp-content/mu-plugins/,
 * the site's home/siteurl pointing at a running web server (for example php -S with PHP_CLI_SERVER_WORKERS=4),
 * pretty permalinks possible, Manny active, administrator ID 1.
 *
 * php tests/rewrite-recovery-wordpress.php /path/to/wp-load.php
 */
require $argv[1];
if ( 0 !== strpos( $wpdb->prefix, 'equinox_' ) || ! function_exists( 'wp_remote_get' ) ) {
	throw new RuntimeException( 'Disposable equinox_ prefix required' );
}
if ( ! file_exists( WPMU_PLUGIN_DIR . '/mwseo-test-fixture.php' ) ) {
	throw new RuntimeException( 'Copy tests/mwseo-test-fixture.php to wp-content/mu-plugins/ first' );
}
wp_set_current_user( 1 );
$checks   = 0;
$failures = 0;
function rr_check( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) {
		++$failures;
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
/** Options straight from the database: the HTTP requests change them behind this process's cache. */
function rr_db_option( $name ) {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore
	return null === $raw ? null : maybe_unserialize( $raw );
}
function rr_has_both( $rules ) {
	return is_array( $rules ) && isset( $rules['^mwseo-sitemap\.xml$'], $rules['^mwseo-([a-z0-9_]+)-sitemap([0-9]*)\.xml$'] );
}
function rr_flushes() {
	return (int) rr_db_option( 'mwseo_test_flushes' );
}
function rr_get( $path ) {
	$r = wp_remote_get( home_url( $path ), array( 'timeout' => 30, 'redirection' => 0 ) );
	if ( is_wp_error( $r ) ) {
		throw new RuntimeException( 'HTTP failed for ' . $path . ': ' . $r->get_error_message() );
	}
	return $r;
}
function rr_pages( $n ) {
	for ( $i = 0; $i < $n; $i++ ) {
		rr_get( '/?rr=' . $i );
	}
}
/** Known good starting state: pretty permalinks, no conflict, both rules stored, counters at zero. */
function rr_reset( $conflict = '' ) {
	update_option( 'mwseo_test_nosave', 0 );
	update_option( 'mwseo_test_conflict', '' );
	update_option( 'permalink_structure', '/%postname%/' );
	delete_transient( MWSEO_Notices::REWRITE_LOCK );
	delete_transient( MWSEO_Notices::REWRITE_TRANSIENT );
	MWSEO_Sitemaps::add_rewrites();
	flush_rewrite_rules( false );
	wp_cache_flush();
	update_option( 'mwseo_test_conflict', $conflict );
	update_option( 'mwseo_test_flushes', 0 );
	wp_cache_flush();
}
/** Store rewrite rules without the given keys, the way a conflicting plugin's flush would. */
function rr_strip_rules( array $keys ) {
	$rules = rr_db_option( 'rewrite_rules' );
	foreach ( $keys as $k ) {
		unset( $rules[ $k ] );
	}
	global $wpdb;
	$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $rules ) ), array( 'option_name' => 'rewrite_rules' ) ); // phpcs:ignore
	wp_cache_flush();
}
function rr_notice_html() {
	wp_cache_flush();
	ob_start();
	MWSEO_Notices::rewrite_notice();
	return ob_get_clean();
}

$probe = rr_get( '/wp-login.php' );
if ( 200 !== wp_remote_retrieve_response_code( $probe ) ) {
	throw new RuntimeException( 'The web server for ' . home_url() . ' is not reachable' );
}
$parent = '^mwseo-sitemap\.xml$';
$child  = '^mwseo-([a-z0-9_]+)-sitemap([0-9]*)\.xml$';

// ---- Conflicts are detected ------------------------------------------------------------------
foreach ( array( 'rankmath', 'yoast', 'filter' ) as $kind ) {
	rr_reset( $kind );
	$state = json_decode( wp_remote_retrieve_body( rr_get( '/?mwseo_fx=state' ) ), true );
	rr_check( ! empty( $state['other_active'] ), "conflict '$kind' is seen as another active SEO plugin" );
}
rr_reset( '' );
$state = json_decode( wp_remote_retrieve_body( rr_get( '/?mwseo_fx=state' ) ), true );
rr_check( isset( $state['other_active'] ) && false === $state['other_active'], 'no conflict: Manny is the active SEO plugin' );

// ---- A flush during a conflict drops the rules and nothing recovers them while the conflict lasts ----
foreach ( array( 'rankmath', 'yoast', 'filter' ) as $kind ) {
	rr_reset( $kind );
	rr_check( rr_has_both( rr_db_option( 'rewrite_rules' ) ), "$kind: both rules stored before the flush" );
	rr_get( '/?mwseo_fx=flush' );
	rr_check( ! isset( rr_db_option( 'rewrite_rules' )[ $parent ] ) && ! isset( rr_db_option( 'rewrite_rules' )[ $child ] ), "$kind: a flush during the conflict drops the sitemap rules" );
	$after_flush = rr_flushes();
	rr_pages( 3 );
	rr_check( rr_flushes() === $after_flush, "$kind: no recovery flush on later page loads while the conflict lasts ({$after_flush} flush in total)" );
	rr_check( false === get_transient( MWSEO_Notices::REWRITE_TRANSIENT ) || ! rr_db_option( '_transient_' . MWSEO_Notices::REWRITE_TRANSIENT ), "$kind: no warning is raised during the conflict" );
}

// ---- The conflict ends: the first sitemap request works with exactly one flush ----------------
update_option( 'mwseo_test_conflict', '' );
update_option( 'mwseo_test_flushes', 0 );
wp_cache_flush();
rr_check( ! rr_has_both( rr_db_option( 'rewrite_rules' ) ), 'after the conflict the rules are still missing' );
$sitemap = rr_get( '/mwseo-sitemap.xml' );
rr_check( 200 === wp_remote_retrieve_response_code( $sitemap ), 'the first sitemap request after the conflict returns 200 (got ' . wp_remote_retrieve_response_code( $sitemap ) . ')' );
rr_check( 1 === rr_flushes(), 'that request used exactly one flush (' . rr_flushes() . ')' );
rr_check( rr_has_both( rr_db_option( 'rewrite_rules' ) ), 'both rules are stored again' );
rr_pages( 5 );
rr_check( 1 === rr_flushes(), 'five later page loads cause no flush (' . rr_flushes() . ' in total)' );

// ---- Losing only the child rule restores both ------------------------------------------------
rr_reset( '' );
rr_strip_rules( array( $child ) );
rr_check( isset( rr_db_option( 'rewrite_rules' )[ $parent ] ) && ! isset( rr_db_option( 'rewrite_rules' )[ $child ] ), 'setup: only the child sitemap rule is missing' );
rr_pages( 1 );
rr_check( rr_has_both( rr_db_option( 'rewrite_rules' ) ), 'losing only the child rule restores both rules' );
rr_check( 1 === rr_flushes(), 'with one flush (' . rr_flushes() . ')' );
rr_check( 200 === wp_remote_retrieve_response_code( rr_get( '/mwseo-news-sitemap.xml' ) ) || 404 !== wp_remote_retrieve_response_code( rr_get( '/mwseo-post-sitemap.xml' ) ), 'a child sitemap URL resolves again' );

// ---- Rules that cannot be saved: at most one flush in ten requests, and the button appears ------
rr_reset( '' );
rr_strip_rules( array( $parent, $child ) );
update_option( 'mwseo_test_nosave', 1 );
wp_cache_flush();
rr_pages( 10 );
rr_check( rr_flushes() <= 1, 'unsaveable rules cause at most one flush in ten requests (' . rr_flushes() . ')' );
rr_check( ! rr_has_both( rr_db_option( 'rewrite_rules' ) ), 'the rules really could not be saved' );
rr_check( (bool) rr_db_option( '_transient_' . MWSEO_Notices::REWRITE_TRANSIENT ), 'the warning flag is raised' );
$html = rr_notice_html();
rr_check( false !== strpos( $html, 'Restore sitemap rewrite rules' ) && false !== strpos( $html, 'mwseo_flush_rewrite' ), 'the admin notice shows the restore button' );

// ---- A stale warning clears ------------------------------------------------------------------
rr_reset( '' );
set_transient( MWSEO_Notices::REWRITE_TRANSIENT, 1, HOUR_IN_SECONDS );
wp_cache_flush();
rr_check( '' === trim( rr_notice_html() ) && ! rr_db_option( '_transient_' . MWSEO_Notices::REWRITE_TRANSIENT ), 'a stale warning clears when the rules are present' );

rr_reset( '' );
rr_strip_rules( array( $parent, $child ) );
set_transient( MWSEO_Notices::REWRITE_TRANSIENT, 1, HOUR_IN_SECONDS );
add_filter( 'mwseo_other_seo_active', '__return_true' );
rr_check( '' === trim( rr_notice_html() ) && ! rr_db_option( '_transient_' . MWSEO_Notices::REWRITE_TRANSIENT ), 'a stale warning clears during a conflict' );
remove_filter( 'mwseo_other_seo_active', '__return_true' );

rr_reset( '' );
rr_strip_rules( array( $parent, $child ) );
set_transient( MWSEO_Notices::REWRITE_TRANSIENT, 1, HOUR_IN_SECONDS );
update_option( 'permalink_structure', '' );
rr_check( '' === trim( rr_notice_html() ) && ! rr_db_option( '_transient_' . MWSEO_Notices::REWRITE_TRANSIENT ), 'a stale warning clears with plain permalinks' );
rr_reset( '' );

// ---- The unresolved legacy title fallback from 1.0.9.1 still works ----------------------------
$unresolved = wp_insert_post( array( 'post_title' => 'Legacy unresolved', 'post_name' => 'legacy-unresolved', 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
update_post_meta( $unresolved, '_yoast_wpseo_title', '%%primary_category%% custom wording' );
$resolved = wp_insert_post( array( 'post_title' => 'Legacy resolved', 'post_name' => 'legacy-resolved', 'post_status' => 'publish', 'post_content' => '<p>Body.</p>' ) );
update_post_meta( $resolved, '_yoast_wpseo_title', 'Legacy wording %%title%%' );
rr_reset( '' );
$a = wp_remote_retrieve_body( rr_get( '/legacy-unresolved/' ) );
$b = wp_remote_retrieve_body( rr_get( '/legacy-resolved/' ) );
rr_check( preg_match( '#<title>Legacy unresolved \S+ [^<]+</title>#', $a ) && false === strpos( $a, '%%primary_category%%' ), 'a legacy title with unresolved variables falls back to the default title format' );
rr_check( false !== strpos( $b, '<title>Legacy wording Legacy resolved</title>' ), 'a fully resolving legacy title is used' );
wp_delete_post( $unresolved, true );
wp_delete_post( $resolved, true );

update_option( 'mwseo_test_conflict', '' );
update_option( 'mwseo_test_nosave', 0 );
echo 'WordPress ' . get_bloginfo( 'version' ) . ' / PHP ' . PHP_VERSION . ": $checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
