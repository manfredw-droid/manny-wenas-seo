<?php
/**
 * 1.0.9: the SQL rule behind the Cornerstone filter and llms.txt equals MWSEO_Meta::get().
 * php tests/cornerstone-sql-wordpress.php /disposable/site/wp-load.php
 *
 * Requires real WordPress with an activated Manny plugin on a DISPOSABLE site: a solstice_ table prefix,
 * or MWSEO_DISPOSABLE_SITE=1 set by whoever runs it. Seeds a few thousand posts directly in the database
 * (prefix "sx-") and removes them again, also after a failure.
 *
 * Contract: values as the three plugins write them (plain strings, NULL and empty included). PHP-serialized
 * values are out of contract: get() unserializes them, the SQL sees text, and no plugin here writes them.
 */
require $argv[1];
if ( 0 !== strpos( $wpdb->prefix, 'solstice_' ) && '1' !== getenv( 'MWSEO_DISPOSABLE_SITE' ) ) {
	throw new RuntimeException( 'A disposable site is required: a solstice_ table prefix or MWSEO_DISPOSABLE_SITE=1.' );
}
if ( ! function_exists( 'set_current_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
wp_set_current_user( 1 );
$checks   = 0;
$failures = array();
function sx_check( $condition, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) { $failures[] = $label; }
}
function sx_cleanup() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE 'sx-%')" );
	$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_title LIKE 'sx-%'" );
}
sx_cleanup();
register_shutdown_function( 'sx_cleanup' ); // Also runs after an exception.
const SX_ABSENT = '__absent__';
/**
 * Seed one published post per entry of $specs. Each spec is a list of [ meta_key, value ] rows in insert
 * order (value null = SQL NULL). Returns post IDs in the same order.
 */
function sx_seed( array $specs, $offset ) {
	global $wpdb;
	$now = '2026-10-23 10:00:00';
	foreach ( array_chunk( array_keys( $specs ), 500 ) as $chunk ) {
		$rows = array();
		foreach ( $chunk as $i ) {
			$n      = $offset + $i;
			$rows[] = $wpdb->prepare( "(1,%s,%s,'x',%s,'','publish','closed','closed','',%s,'','',%s,%s,'',0,%s,0,'post','',0)", $now, $now, "sx-$n", "sx-$n", $now, $now, "http://x/?p=sx$n" );
		}
		$wpdb->query( "INSERT INTO {$wpdb->posts} (post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count) VALUES " . implode( ',', $rows ) );
	}
	$by_title = array();
	foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_title LIKE %s", 'sx-%' ) ) as $r ) { $by_title[ $r->post_title ] = (int) $r->ID; }
	$ids  = array();
	$meta = array();
	foreach ( $specs as $i => $rows_for_post ) {
		$id = $by_title[ 'sx-' . ( $offset + $i ) ];
		$ids[ $i ] = $id;
		foreach ( $rows_for_post as $r ) { $meta[] = array( $id, $r[0], $r[1] ); }
	}
	foreach ( array_chunk( $meta, 1000 ) as $chunk ) {
		$vals = array();
		foreach ( $chunk as $m ) { $vals[] = null === $m[2] ? $wpdb->prepare( '(%d,%s,NULL)', $m[0], $m[1] ) : $wpdb->prepare( '(%d,%s,%s)', $m[0], $m[1], $m[2] ); }
		$wpdb->query( "INSERT INTO {$wpdb->postmeta} (post_id,meta_key,meta_value) VALUES " . implode( ',', $vals ) );
	}
	return $ids;
}
// What the SQL rule decides, and what the real list filter query returns, for the given posts.
function sx_sql_true( array $ids ) {
	global $wpdb;
	$list = implode( ',', array_map( 'intval', $ids ) );
	return array_flip( array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($list) AND " . MWSEO_Meta::cornerstone_where_sql( "{$wpdb->posts}.ID" ) ) ) );
}
function sx_list_query( array $extra = array() ) {
	global $wp_the_query;
	$GLOBALS['pagenow'] = 'edit.php';
	set_current_screen( 'edit-post' );
	$_GET['mwseo_cornerstone'] = '1';
	$q = new WP_Query();
	$wp_the_query = $q;
	$q->query( array_merge( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'no_found_rows' => true ), $extra ) );
	unset( $_GET['mwseo_cornerstone'] );
	return $q;
}

// ---- 1. Every combination of plain stored values across the three keys -------------------------
// ABSENT = no row; null = SQL NULL. 15 states per key: 15 x 15 x 15 posts.
$states = array( SX_ABSENT, null, '', '0', '1', 'true', 'on', 'ON', 'On', 'TRUE', ' 1', '1 ', 'yes', 'off', 'false' );
$keys   = array( '_mwseo_cornerstone', '_yoast_wpseo_is_cornerstone', 'rank_math_pillar_content' );
function sx_label( $v ) { return SX_ABSENT === $v ? '-' : ( null === $v ? 'NULL' : "'" . $v . "'" ); }
$specs  = array();
$labels = array();
foreach ( $states as $o ) { foreach ( $states as $y ) { foreach ( $states as $r ) {
	$rows = array();
	foreach ( array( $o, $y, $r ) as $k => $v ) { if ( SX_ABSENT !== $v ) { $rows[] = array( $keys[ $k ], $v ); } }
	$rows[]   = array( '_edit_lock', '1' ); // unrelated metadata on every post
	$specs[]  = $rows;
	$labels[] = 'own=' . sx_label( $o ) . ' yoast=' . sx_label( $y ) . ' rank=' . sx_label( $r );
} } }
$ids = sx_seed( $specs, 0 );
wp_cache_flush();
update_meta_cache( 'post', $ids );
$sql = sx_sql_true( $ids );
$divergent = array();
$truth_true = 0;
foreach ( $ids as $i => $id ) {
	$truth = MWSEO_Meta::get( $id, 'cornerstone' );
	$truth_true += $truth ? 1 : 0;
	if ( $truth !== isset( $sql[ $id ] ) ) { $divergent[] = $labels[ $i ] . ' get()=' . json_encode( $truth ); }
}
sx_check( 3375 === count( $ids ), 'cube: all combinations seeded (' . count( $ids ) . ')' );
sx_check( $truth_true > 100 && $truth_true < count( $ids ) - 100, "cube: the combinations are not trivially all true or all false ($truth_true)" );
sx_check( ! $divergent, 'cube: SQL equals get() for every combination; ' . count( $divergent ) . ' divergent, e.g. ' . implode( ' | ', array_slice( $divergent, 0, 4 ) ) );
// The real list-filter query returns the same posts, and the one-JOIN prefilter never drops a true post.
$q      = sx_list_query();
$listed = array_intersect( array_map( 'intval', $q->posts ), $ids );
$want   = array();
foreach ( $ids as $id ) { if ( MWSEO_Meta::get( $id, 'cornerstone' ) ) { $want[] = $id; } }
sort( $want );
$listed = array_values( $listed );
sort( $listed );
sx_check( $listed === $want, 'cube: the list filter query returns exactly the effective cornerstone posts (' . count( $listed ) . ' vs ' . count( $want ) . ')' );
sx_check( 1 === preg_match_all( '/JOIN ' . preg_quote( $wpdb->postmeta, '/' ) . '\b/', $q->request ), 'cube: the filter query has exactly one JOIN on postmeta' );
$pre = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => MWSEO_Meta::cornerstone_candidate_meta_query() ) );
$pre_ids = array_flip( array_map( 'intval', $pre->posts ) );
$dropped = array();
foreach ( $want as $id ) { if ( ! isset( $pre_ids[ $id ] ) ) { $dropped[] = $id; } }
sx_check( ! $dropped, 'cube: the single-join prefilter is a superset (dropped ' . count( $dropped ) . ')' );

// ---- 2. Duplicate rows: get_post_meta() reads the first row (lowest meta_id) of a key -----------
$dup_pairs = array();
foreach ( array( '0', '1', '' ) as $a ) { foreach ( array( '0', '1', '' ) as $b ) { $dup_pairs[] = array( $a, $b ); } }
$specs  = array();
$labels = array();
foreach ( array( 0, 1, 2 ) as $dup_key ) {
	foreach ( $dup_pairs as $pair ) {
		// Other keys: absent, or a fixed opposing value, so the duplicated key is decisive.
		foreach ( array( 'alone', 'with a fixed Yoast 1 and Rank Math on' ) as $mode ) {
			$rows = array();
			if ( 'alone' !== $mode ) {
				if ( 1 !== $dup_key ) { $rows[] = array( $keys[1], '1' ); }
				if ( 2 !== $dup_key ) { $rows[] = array( $keys[2], 'on' ); }
			}
			$rows[]   = array( $keys[ $dup_key ], $pair[0] );
			$rows[]   = array( '_thumbnail_id', '5' );
			$rows[]   = array( $keys[ $dup_key ], $pair[1] );
			$specs[]  = $rows;
			$labels[] = $keys[ $dup_key ] . " rows '" . $pair[0] . "' then '" . $pair[1] . "' ($mode)";
		}
	}
}
$dids = sx_seed( $specs, 10000 );
wp_cache_flush();
update_meta_cache( 'post', $dids );
$sql = sx_sql_true( $dids );
$divergent = array();
foreach ( $dids as $i => $id ) {
	$truth = MWSEO_Meta::get( $id, 'cornerstone' );
	if ( $truth !== isset( $sql[ $id ] ) ) { $divergent[] = $labels[ $i ] . ' get()=' . json_encode( $truth ); }
}
sx_check( 54 === count( $dids ), 'duplicates: all variants seeded (' . count( $dids ) . ')' );
sx_check( ! $divergent, 'duplicates: SQL equals get() (first row wins); ' . count( $divergent ) . ' divergent, e.g. ' . implode( ' | ', array_slice( $divergent, 0, 3 ) ) );

// ---- 3. Key spelling, look-alike keys and unrelated metadata -----------------------------------
// The database compares meta keys case-insensitively, PHP does not: only the exact key counts.
// Expected values are written by hand, not taken from get().
$special = array(
	array( 'only an upper-case own key', false, array( array( '_MWSEO_CORNERSTONE', '1' ) ) ),
	array( 'only a mixed-case Yoast key', false, array( array( '_Yoast_Wpseo_Is_Cornerstone', '1' ) ) ),
	array( 'exact own 0, upper-case own 1, Yoast 1', false, array( array( '_mwseo_cornerstone', '0' ), array( '_MWSEO_CORNERSTONE', '1' ), array( '_yoast_wpseo_is_cornerstone', '1' ) ) ),
	array( 'upper-case own 0 first, then exact own 1', true, array( array( '_MWSEO_CORNERSTONE', '0' ), array( '_mwseo_cornerstone', '1' ) ) ),
	array( 'upper-case Yoast 0 first, then exact Yoast 1', true, array( array( '_YOAST_WPSEO_IS_CORNERSTONE', '0' ), array( '_yoast_wpseo_is_cornerstone', '1' ) ) ),
	array( 'look-alike keys only', false, array( array( '_mwseo_cornerstone_note', '1' ), array( '_yoast_wpseo_is_cornerstone2', '1' ), array( 'rank_math_pillar_content_x', 'on' ) ) ),
	array( 'own 1 among unrelated metadata', true, array( array( '_edit_lock', '1' ), array( '_mwseo_cornerstone', '1' ), array( '_thumbnail_id', '5' ), array( '_mwseo_noindex', '1' ) ) ),
	array( 'own 0 and Yoast 1 with unrelated rows between', false, array( array( '_edit_lock', 'x' ), array( '_mwseo_cornerstone', '0' ), array( '_thumbnail_id', '5' ), array( '_yoast_wpseo_is_cornerstone', '1' ) ) ),
	array( 'only unrelated metadata', false, array( array( '_edit_lock', '1' ), array( '_thumbnail_id', '5' ) ) ),
	array( 'Yoast empty string, Rank Math on', true, array( array( '_yoast_wpseo_is_cornerstone', '' ), array( 'rank_math_pillar_content', 'on' ) ) ),
	array( 'Yoast 0, Rank Math on (Yoast has a value)', false, array( array( '_yoast_wpseo_is_cornerstone', '0' ), array( 'rank_math_pillar_content', 'on' ) ) ),
	array( 'Rank Math ON (wrong case)', false, array( array( 'rank_math_pillar_content', 'ON' ) ) ),
);
$sids = sx_seed( array_column( $special, 2 ), 20000 );
wp_cache_flush();
update_meta_cache( 'post', $sids );
$sql = sx_sql_true( $sids );
foreach ( $special as $i => $case ) {
	$id = $sids[ $i ];
	sx_check( $case[1] === MWSEO_Meta::get( $id, 'cornerstone' ), 'special: get() is ' . json_encode( $case[1] ) . ' for ' . $case[0] );
	sx_check( $case[1] === isset( $sql[ $id ] ), 'special: SQL is ' . json_encode( $case[1] ) . ' for ' . $case[0] );
}

echo wp_json_encode( array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'checks' => $checks, 'failures' => $failures ) ) . "\n";
exit( $failures ? 1 : 0 );
