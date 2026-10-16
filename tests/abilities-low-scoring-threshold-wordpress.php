<?php
/**
 * Ability mwseo/list-low-scoring-posts honours its threshold (strictly below).
 * php tests/abilities-low-scoring-threshold-wordpress.php /disposable/site/wp-load.php
 *
 * Requires real WordPress with the Manny plugin active and a disposable
 * solstice_ table prefix. Without the Abilities API (WordPress < 6.9) the
 * plugin's registration is captured by a shim and the real execute_callback and
 * permission_callback are called directly; this is NOT an Abilities API
 * integration test (no registry schema validation, defaults or enforcement).
 * The query itself is a real WP_Query against the database.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
require $argv[1];
global $wpdb;
if ( 0 !== strpos( $wpdb->prefix, 'solstice_' ) ) {
	throw new RuntimeException( 'A disposable solstice_ database prefix is required.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_set_current_user( 1 );
$checks   = 0;
$failures = array();
function lt_check( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) {
		$failures[] = $label;
	}
}
$GLOBALS['lt_captured'] = array();
$shim = ! function_exists( 'wp_register_ability' );
if ( $shim ) {
	function wp_register_ability( $name, $args ) {
		$GLOBALS['lt_captured'][ $name ] = $args;
		return true;
	}
	function wp_register_ability_category() {
		return true;
	}
	MWSEO_Abilities::register();
	$ab = $GLOBALS['lt_captured']['mwseo/list-low-scoring-posts'] ?? null;
} else {
	$ab = null;
}
if ( ! $ab ) {
	throw new RuntimeException( 'Ability registration not captured (real Abilities API present: not supported by this test).' );
}
lt_check( 'integer' === $ab['input_schema']['properties']['threshold']['type'] && 60 === $ab['input_schema']['properties']['threshold']['default'], 'schema: threshold integer, default 60' );

register_post_type( 'ltother', array( 'public' => true ) );
$ids = array();
$mk  = function ( $title, $score, $extra = array() ) use ( &$ids ) {
	$meta = null === $score ? array() : array( '_mwseo_score' => $score );
	$id   = wp_insert_post( array_merge( array( 'post_title' => $title, 'post_content' => '<p>x</p>', 'post_status' => 'publish', 'post_author' => 1, 'meta_input' => $meta ), $extra ) );
	$ids[ $id ] = $title;
	return $id;
};
$mk( 'S missing', null );
foreach ( array( 0, 9, 49, 50, 79, 80, 100 ) as $s ) {
	$mk( 'S ' . $s, $s );
}
$mk( 'X draft', 5, array( 'post_status' => 'draft' ) );
$mk( 'X private', 6, array( 'post_status' => 'private' ) );
$mk( 'X other type', 7, array( 'post_type' => 'ltother' ) );
$mk( 'P page 20', 20, array( 'post_type' => 'page' ) );
$scores = array( 'S 0' => 0, 'S 9' => 9, 'P page 20' => 20, 'S 49' => 49, 'S 50' => 50, 'S 79' => 79, 'S 80' => 80, 'S 100' => 100 );

$sql = array();
add_filter( 'query', function ( $q ) use ( &$sql ) {
	if ( false !== strpos( $q, '_mwseo_score' ) ) {
		$sql[] = preg_replace( '/\s+/', ' ', $q );
	}
	return $q;
} );
$run = function ( $input ) use ( $ab, $ids, &$sql ) {
	wp_cache_flush();
	$sql = array();
	$res = ( $ab['execute_callback'] )( $input );
	$out = array();
	foreach ( $res as $r ) {
		$out[] = ( $ids[ $r['post_id'] ] ?? '?' ) . '=' . $r['score'];
	}
	return $out;
};
$expect = function ( $threshold, $limit = 20 ) use ( $scores ) {
	$list = array_filter( $scores, function ( $s ) use ( $threshold ) { return $s < $threshold; } );
	asort( $list );
	$out = array();
	foreach ( $list as $t => $s ) {
		$out[] = $t . '=' . $s;
	}
	return array_slice( $out, 0, $limit );
};
foreach ( array( 0, 10, 50, 60, 80, 101 ) as $t ) {
	$got = $run( array( 'threshold' => $t ) );
	lt_check( $got === $expect( $t ), "threshold $t: expected [" . implode( ', ', $expect( $t ) ) . '] got [' . implode( ', ', $got ) . ']' );
	lt_check( (bool) preg_match( '/CAST\([^)]*meta_value AS SIGNED\) < \'' . $t . '\'/', end( $sql ) ?: '' ), "threshold $t: SQL has a numeric value comparison" );
}
$got = $run( array() );
lt_check( $got === $expect( 60 ), 'default threshold (no input) behaves as 60: got [' . implode( ', ', $got ) . ']' );
lt_check( (bool) preg_match( "/< '60'/", end( $sql ) ?: '' ), 'default: SQL compares with 60' );
$got = $run( array( 'threshold' => 80, 'limit' => 3 ) );
lt_check( $got === $expect( 80, 3 ), 'limit 3 at threshold 80: got [' . implode( ', ', $got ) . ']' );
$got = $run( array( 'threshold' => 101, 'limit' => 0 ) );
lt_check( 1 === count( $got ), 'limit below 1 is clamped to 1' );
$got = $run( array( 'threshold' => 101, 'limit' => 500 ) );
lt_check( 8 === count( $got ), 'limit above 100 is clamped (all 8 returned)' );
$got = $run( array( 'threshold' => 101 ) );
lt_check( ! in_array( 'S missing=', $got, true ) && 0 === count( array_filter( $got, function ( $x ) { return 0 === strpos( $x, 'S missing' ) || 0 === strpos( $x, 'X ' ); } ) ), 'missing score, draft, private and other post type never listed' );
lt_check( in_array( 'S 0=0', $got, true ), 'score 0 is a valid score and is listed' );
lt_check( in_array( 'P page 20=20', $got, true ), 'published pages are listed' );

$perm = $ab['permission_callback'];
foreach ( array( 'administrator' => true, 'editor' => true, 'author' => false, 'contributor' => false, 'subscriber' => false ) as $role => $allowed ) {
	$uid = wp_insert_user( array( 'user_login' => 'ltperm_' . $role, 'user_pass' => wp_generate_password(), 'role' => $role ) );
	wp_set_current_user( $uid );
	lt_check( $allowed === (bool) $perm(), "permission: $role " . ( $allowed ? 'allowed' : 'denied' ) );
	wp_set_current_user( 1 );
	wp_delete_user( $uid );
}
wp_set_current_user( 0 );
lt_check( false === (bool) $perm(), 'permission: logged out denied' );
wp_set_current_user( 1 );

foreach ( array_keys( $ids ) as $id ) {
	wp_delete_post( $id, true );
}
echo 'WordPress ', $GLOBALS['wp_version'], ' | registry: ', $shim ? 'shim (callbacks called directly)' : 'real', "\n";
echo $checks - count( $failures ), '/', $checks, " passed\n";
foreach ( $failures as $f ) {
	echo 'FAIL: ', $f, "\n";
}
exit( $failures ? 1 : 0 );
