<?php
/**
 * Finding #23: noindex posts are excluded from llms.txt, also when cornerstone.
 * php tests/llms-noindex-cornerstone-wordpress.php /disposable/site/wp-load.php [http://127.0.0.1:PORT]
 *
 * Requires real WordPress with an activated Manny plugin and a disposable
 * solstice_llms23_ table prefix. The generated body is checked in-process
 * (MWSEO_Llms_Txt::build(), what serve() prints) and, when a base URL is given,
 * over HTTP from the real /llms.txt handler (/?mwseo_llms=1). Only the native
 * _mwseo_noindex field is covered; other plugins' noindex fields are not.
 */
require $argv[1];
$base = isset( $argv[2] ) ? rtrim( $argv[2], '/' ) : '';
if ( 0 !== strpos( $wpdb->prefix, 'solstice_llms23_' ) ) {
	throw new RuntimeException( 'A disposable solstice_llms23_ database prefix is required.' );
}
wp_set_current_user( 1 );
$saved_options = get_option( MWSEO_Options::KEY, array() );
$saved_public  = get_option( 'blog_public' );
$options       = $saved_options;
$options['llms_enabled'] = 1;
update_option( MWSEO_Options::KEY, $options );
MWSEO_Options::flush();
update_option( 'blog_public', 1 );

$created  = array();
$checks   = 0;
$failures = array();
function l23_check( $condition, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}
function l23_post( $title, array $meta = array(), $date = null ) {
	global $created;
	$args = array(
		'post_title'   => $title,
		'post_content' => '<p>Body of ' . $title . '</p>',
		'post_status'  => 'publish',
		'post_author'  => 1,
		'meta_input'   => $meta,
	);
	if ( $date ) {
		$args['post_date'] = $date;
	}
	$id = wp_insert_post( $args, true );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$created[] = $id;
	return $id;
}
/** Parse llms.txt into section => titles. */
function l23_parse( $body ) {
	$sections = array();
	$current  = null;
	foreach ( explode( "\n", $body ) as $line ) {
		if ( 0 === strpos( $line, '## ' ) ) {
			$current = substr( $line, 3 );
			$sections[ $current ] = array();
		} elseif ( null !== $current && preg_match( '/^- \[(.*?)\]\(/', $line, $m ) ) {
			$sections[ $current ][] = $m[1];
		}
	}
	return $sections;
}
function l23_http( $base ) {
	$body = @file_get_contents( $base . '/?mwseo_llms=1' );
	if ( false === $body ) {
		throw new RuntimeException( 'HTTP /llms.txt request failed.' );
	}
	return $body;
}
/** Titles in "Key content" and in all other sections, from in-process and (optional) HTTP output. */
function l23_views() {
	global $base;
	$views = array( 'build' => MWSEO_Llms_Txt::build() );
	if ( $base ) {
		$views['http'] = l23_http( $base );
	}
	$out = array();
	foreach ( $views as $name => $body ) {
		$sections = l23_parse( $body );
		$key      = isset( $sections['Key content'] ) ? $sections['Key content'] : array();
		$rest     = array();
		foreach ( $sections as $label => $titles ) {
			if ( 'Key content' !== $label ) {
				$rest = array_merge( $rest, $titles );
			}
		}
		$out[ $name ] = array( 'key' => $key, 'rest' => $rest, 'all' => array_merge( $key, $rest ) );
	}
	return $out;
}
function l23_expect( $label, array $in_key, array $in_rest, array $absent ) {
	foreach ( l23_views() as $view => $v ) {
		foreach ( $in_key as $t ) {
			l23_check( in_array( $t, $v['key'], true ), "$view: [$label] '$t' should be under Key content" );
		}
		foreach ( $in_rest as $t ) {
			l23_check( in_array( $t, $v['rest'], true ) && ! in_array( $t, $v['key'], true ), "$view: [$label] '$t' should be listed under its type, not Key content" );
		}
		foreach ( $absent as $t ) {
			l23_check( ! in_array( $t, $v['all'], true ), "$view: [$label] '$t' should not appear at all" );
		}
	}
}

try {
	// 1. Ordinary posts: noindex absent, '0', '' (false) are listed; '1' (true) is not.
	l23_post( 'L23 ordinary plain' );
	l23_post( 'L23 ordinary noindex zero', array( '_mwseo_noindex' => '0' ) );
	l23_post( 'L23 ordinary noindex empty', array( '_mwseo_noindex' => '' ) );
	l23_post( 'L23 ordinary noindex true', array( '_mwseo_noindex' => '1' ) );
	// 2. Native cornerstone, with the same noindex variants.
	l23_post( 'L23 cornerstone plain', array( '_mwseo_cornerstone' => '1' ) );
	l23_post( 'L23 cornerstone noindex zero', array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '0' ) );
	l23_post( 'L23 cornerstone noindex empty', array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '' ) );
	l23_post( 'L23 cornerstone noindex true', array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '1' ) );
	// 3. Cornerstone via Yoast / Rank Math markers, with the native noindex field.
	l23_post( 'L23 yoast cornerstone', array( '_yoast_wpseo_is_cornerstone' => '1' ) );
	l23_post( 'L23 yoast cornerstone native noindex', array( '_yoast_wpseo_is_cornerstone' => '1', '_mwseo_noindex' => '1' ) );
	l23_post( 'L23 rankmath pillar', array( 'rank_math_pillar_content' => 'on' ) );
	l23_post( 'L23 rankmath pillar native noindex', array( 'rank_math_pillar_content' => 'on', '_mwseo_noindex' => '1' ) );

	l23_expect(
		'cases 1-3',
		array( 'L23 cornerstone plain', 'L23 cornerstone noindex zero', 'L23 cornerstone noindex empty', 'L23 yoast cornerstone', 'L23 rankmath pillar' ),
		array( 'L23 ordinary plain', 'L23 ordinary noindex zero', 'L23 ordinary noindex empty' ),
		array( 'L23 ordinary noindex true', 'L23 cornerstone noindex true', 'L23 yoast cornerstone native noindex', 'L23 rankmath pillar native noindex' )
	);

	// 4. Toggles reach the frontend output without regenerating anything.
	$toggle_cs = l23_post( 'L23 toggle cornerstone' );
	$toggle_ni = l23_post( 'L23 toggle noindex', array( '_mwseo_cornerstone' => '1' ) );
	l23_expect( 'toggle start', array( 'L23 toggle noindex' ), array( 'L23 toggle cornerstone' ), array() );
	update_post_meta( $toggle_cs, '_mwseo_cornerstone', '1' );
	l23_expect( 'cornerstone on', array( 'L23 toggle cornerstone', 'L23 toggle noindex' ), array(), array() );
	update_post_meta( $toggle_ni, '_mwseo_noindex', '1' );
	l23_expect( 'noindex on', array( 'L23 toggle cornerstone' ), array(), array( 'L23 toggle noindex' ) );
	update_post_meta( $toggle_cs, '_mwseo_noindex', '1' );
	l23_expect( 'both noindex', array(), array(), array( 'L23 toggle cornerstone', 'L23 toggle noindex' ) );
	delete_post_meta( $toggle_ni, '_mwseo_noindex' );
	delete_post_meta( $toggle_cs, '_mwseo_noindex' );
	delete_post_meta( $toggle_cs, '_mwseo_cornerstone' );
	l23_expect( 'noindex off, cornerstone off', array( 'L23 toggle noindex' ), array( 'L23 toggle cornerstone' ), array() );

	// 5. Limit of 50: newer noindex cornerstone posts must not take places from eligible ones.
	// Remove the earlier fixtures first so only the limit posts compete for the 50 places.
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
	$created  = array();
	$eligible = array();
	for ( $i = 1; $i <= 52; $i++ ) {
		$title      = sprintf( 'L23 limit eligible %02d', $i );
		$eligible[] = $title;
		l23_post( $title, array( '_mwseo_cornerstone' => '1' ), '2020-01-01 00:00:' . sprintf( '%02d', $i ) ); // Past dates keep the posts published.
	}
	$excluded = array();
	for ( $i = 1; $i <= 55; $i++ ) {
		$title      = sprintf( 'L23 limit noindex %02d', $i );
		$excluded[] = $title;
		l23_post( $title, array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '1' ), '2024-01-01 00:00:' . sprintf( '%02d', $i ) );
	}
	foreach ( l23_views() as $view => $v ) {
		$limit_key = array_values( array_filter( $v['key'], static function ( $t ) { return 0 === strpos( $t, 'L23 limit ' ); } ) );
		l23_check( 50 === count( array_intersect( $limit_key, $eligible ) ), "$view: [limit] Key content holds 50 eligible limit posts (got " . count( array_intersect( $limit_key, $eligible ) ) . ')' );
		l23_check( 0 === count( array_intersect( $v['all'], $excluded ) ), "$view: [limit] no noindex limit post appears (got " . count( array_intersect( $v['all'], $excluded ) ) . ')' );
		l23_check( 52 === count( array_intersect( $v['all'], $eligible ) ), "$view: [limit] all 52 eligible limit posts stay available (got " . count( array_intersect( $v['all'], $eligible ) ) . ')' );
		// The 50 places go to the most recently modified eligible posts.
		$expected_key = array_slice( array_reverse( $eligible ), 0, 50 );
		l23_check( array() === array_diff( $expected_key, $limit_key ), "$view: [limit] the 50 newest eligible posts occupy Key content" );
	}

	// 6. Query shape: one postmeta join (no N x N row multiplication), exclusion by correlated
	// subqueries, and the filter does not leak into other queries or stay registered.
	$captured = array();
	$capture  = static function ( $query ) use ( &$captured ) {
		if ( false !== strpos( $query, 'LIMIT 0, 50' ) && false !== strpos( $query, $GLOBALS['wpdb']->postmeta ) ) {
			$captured[] = $query;
		}
		return $query;
	};
	wp_cache_flush(); // WP_Query results are cached in memory per request; force the SQL to run.
	add_filter( 'query', $capture );
	MWSEO_Llms_Txt::build();
	remove_filter( 'query', $capture );
	l23_check( 1 === count( $captured ), 'sql: exactly one 50-limit cornerstone query was issued (got ' . count( $captured ) . ')' );
	$sql = $captured ? $captured[0] : '';
	l23_check( 1 === preg_match_all( '/JOIN ' . preg_quote( $wpdb->postmeta, '/' ) . '\b/', $sql ), 'sql: exactly one JOIN on postmeta' );
	l23_check( false !== strpos( $sql, 'NOT EXISTS' ) && 2 === substr_count( $sql, "meta_key = '_mwseo_noindex'" ), 'sql: noindex handled by two key-bounded subqueries' );
	l23_check( false === has_filter( 'get_meta_sql', array( 'MWSEO_Llms_Txt', 'exclude_noindex_sql' ) ), 'sql: get_meta_sql filter is removed after build()' );
	$other = new WP_Query( array( 'post_type' => 'post', 'fields' => 'ids', 'suppress_filters' => true, 'meta_query' => array( array( 'key' => '_mwseo_cornerstone', 'value' => '1' ) ) ) );
	l23_check( false === strpos( $other->request, 'mwseo_ni' ), 'sql: an unrelated meta query is not touched' );
	if ( is_callable( array( 'MWSEO_Llms_Txt', 'exclude_noindex_sql' ) ) ) {
		add_filter( 'get_meta_sql', array( 'MWSEO_Llms_Txt', 'exclude_noindex_sql' ), 10, 6 );
		$unmarked = new WP_Query( array( 'post_type' => 'post', 'fields' => 'ids', 'suppress_filters' => true, 'meta_query' => array( array( 'key' => '_mwseo_cornerstone', 'value' => '1' ) ) ) );
		remove_filter( 'get_meta_sql', array( 'MWSEO_Llms_Txt', 'exclude_noindex_sql' ), 10 );
		l23_check( false === strpos( $unmarked->request, 'mwseo_ni' ), 'sql: the filter ignores queries without the llms marker' );
	}

	// 7. Filter lifetime: each build() removes only a registration it added itself.
	$cb_name   = array( 'MWSEO_Llms_Txt', 'exclude_noindex_sql' );
	$callbacks = static function () {
		global $wp_filter;
		return isset( $wp_filter['get_meta_sql'] ) ? array_sum( array_map( 'count', $wp_filter['get_meta_sql']->callbacks ) ) : 0;
	};
	l23_post( 'L23 reentrancy noindex', array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '1' ) );
	l23_post( 'L23 reentrancy eligible', array( '_mwseo_cornerstone' => '1' ) );
	$start = $callbacks();
	for ( $i = 0; $i < 5; $i++ ) {
		wp_cache_flush();
		MWSEO_Llms_Txt::build();
	}
	l23_check( $start === $callbacks() && false === has_filter( 'get_meta_sql', $cb_name ), 'filter: repeated builds leave no get_meta_sql callbacks behind' );

	$thrower = static function ( $query ) {
		if ( $query->get( 'mwseo_llms_cornerstone' ) ) {
			throw new RuntimeException( 'forced failure inside the query window' );
		}
	};
	add_action( 'pre_get_posts', $thrower );
	$caught = false;
	try {
		wp_cache_flush();
		MWSEO_Llms_Txt::build();
	} catch ( RuntimeException $e ) {
		$caught = true;
	}
	remove_action( 'pre_get_posts', $thrower );
	l23_check( $caught && $start === $callbacks() && false === has_filter( 'get_meta_sql', $cb_name ), 'filter: an exception inside the query window propagates and leaves no callback behind' );

	// A build() started from inside the query window of another build() (nested).
	$inner_body = null;
	$reenter    = static function ( $query ) use ( &$inner_body ) {
		static $entered = false;
		if ( $query->get( 'mwseo_llms_cornerstone' ) && ! $entered ) {
			$entered    = true;
			$inner_body = MWSEO_Llms_Txt::build();
		}
	};
	add_action( 'pre_get_posts', $reenter );
	wp_cache_flush();
	$outer_body = MWSEO_Llms_Txt::build();
	remove_action( 'pre_get_posts', $reenter );
	l23_check( null !== $inner_body && false !== strpos( $inner_body, '[L23 reentrancy eligible]' ) && false === strpos( $inner_body, '[L23 reentrancy noindex]' ), 'nested: the inner build() keeps the noindex exclusion' );
	l23_check( false !== strpos( $outer_body, '[L23 reentrancy eligible]' ) && false === strpos( $outer_body, '[L23 reentrancy noindex]' ), 'nested: the outer build() keeps the noindex exclusion' );
	l23_check( $start === $callbacks() && false === has_filter( 'get_meta_sql', $cb_name ), 'nested: no get_meta_sql callback is left behind' );
} finally {
	foreach ( $created as $id ) {
		wp_delete_post( $id, true );
	}
	update_option( MWSEO_Options::KEY, $saved_options );
	MWSEO_Options::flush();
	update_option( 'blog_public', $saved_public );
}

$report = array(
	'wordpress' => $GLOBALS['wp_version'],
	'php'       => PHP_VERSION,
	'http'      => $base ? 'yes' : 'no',
	'checks'    => $checks,
	'failures'  => count( $failures ),
);
echo json_encode( $report ), "\n";
foreach ( $failures as $f ) {
	echo "FAIL: $f\n";
}
exit( $failures ? 1 : 0 );
