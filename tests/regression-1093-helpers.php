<?php
/**
 * Shared helpers for the 1.0.9.3 regression tests.
 *
 * Every test is a standalone CLI script that runs against a dedicated disposable
 * WordPress site which is already being served over HTTP at its home URL:
 *
 *     php tests/<name>-wordpress.php /path/to/wordpress
 *
 * The site must use a table prefix starting with "mwfix_", have the Manny Wenas
 * SEO plugin active and have an administrator. The tests create fixtures, change
 * reading/permalink options while they run and remove their own fixtures again.
 * Never point them at a site whose content matters.
 */

if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
	exit( "CLI WordPress root required.\n" );
}
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( 0 !== strpos( $wpdb->prefix, 'mwfix_' ) || ! class_exists( 'MWSEO_Plugin' ) ) {
	fwrite( STDERR, "Dedicated disposable prefix (mwfix_) and active plugin required.\n" );
	exit( 2 );
}
$mwt_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
	)
);
if ( ! $mwt_admins ) {
	fwrite( STDERR, "Disposable administrator required.\n" );
	exit( 2 );
}
wp_set_current_user( $mwt_admins[0]->ID );

$GLOBALS['mwt_checks']   = 0;
$GLOBALS['mwt_failures'] = 0;

/**
 * Record one check.
 *
 * @param bool   $ok     Result.
 * @param string $label  What was checked.
 * @param string $detail Shown on failure.
 */
function mwt_check( $ok, $label, $detail = '' ) {
	++$GLOBALS['mwt_checks'];
	if ( ! $ok ) {
		++$GLOBALS['mwt_failures'];
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ( ! $ok && '' !== $detail ? "\n       " . $detail : '' ) . "\n";
}

/**
 * GET a path of the disposable site without following redirects.
 *
 * @param string $path Path and query, starting with "/".
 * @return array { int code, string body, string type }
 */
function mwt_fetch( $path ) {
	$response = wp_remote_get(
		home_url( $path ),
		array(
			'timeout'     => 120,
			'redirection' => 0,
		)
	);
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'HTTP request failed for ' . $path . ': ' . $response->get_error_message() );
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
		'type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
	);
}

/**
 * Let the plugin finish reading existing content for its media flags, so a test measures
 * and checks the settled state. Existing content is read in steps while the sitemap index
 * is requested. Returns at once on a version without that mechanism.
 *
 * @param int $max Index requests at most.
 * @return bool Whether the settled state was reached (false on a version without the mechanism).
 */
function mwt_settle_media_index( $max = 40 ) {
	global $wpdb;
	for ( $i = 0; $i < $max; $i++ ) {
		mwt_fetch( '/mwseo-sitemap.xml' );
		$raw   = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'mwseo_media_index'" );
		$state = $raw ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $state ) ) {
			return false;
		}
		if ( ! empty( $state['done'] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Print the summary and exit non-zero when a check failed.
 *
 * @param string $name Test name.
 */
function mwt_finish( $name ) {
	printf( "%s: %d checks, %d failed\n", $name, $GLOBALS['mwt_checks'], $GLOBALS['mwt_failures'] );
	exit( $GLOBALS['mwt_failures'] ? 1 : 0 );
}
