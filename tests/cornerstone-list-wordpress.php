<?php
/**
 * 1.0.9: Cornerstone column and "Only cornerstone" filter on the Posts and Pages lists.
 * php tests/cornerstone-list-wordpress.php /disposable/site/wp-load.php
 *
 * Requires real WordPress with an activated Manny plugin on a DISPOSABLE site: either a
 * solstice_ database prefix, or MWSEO_DISPOSABLE_SITE=1 set by whoever runs it (for example a
 * WordPress Playground site). Creates and removes its own posts, restores the plugin options
 * and runs no HTTP requests. The designation reuses _mwseo_cornerstone; no new flag is created.
 */
require $argv[1];
if ( 0 !== strpos( $wpdb->prefix, 'solstice_' ) && '1' !== getenv( 'MWSEO_DISPOSABLE_SITE' ) ) {
	throw new RuntimeException( 'A disposable site is required: a solstice_ table prefix or MWSEO_DISPOSABLE_SITE=1.' );
}
wp_set_current_user( 1 );
if ( ! function_exists( 'set_current_screen' ) ) {
	// Admin screen helpers (not loaded on a front-end request) to simulate the list screen.
	require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
	require_once ABSPATH . 'wp-admin/includes/screen.php';
}
$checks   = 0;
$failures = array();
$created  = array();
$saved_options = null;
function cs_cleanup() {
	global $created, $saved_options;
	foreach ( $created as $id ) { wp_delete_post( $id, true ); }
	$created = array();
	if ( post_type_exists( 'mwseo_other' ) ) { unregister_post_type( 'mwseo_other' ); }
	if ( null !== $saved_options ) { update_option( MWSEO_Options::KEY, $saved_options ); MWSEO_Options::flush(); }
	unset( $_GET['mwseo_cornerstone'] );
}
register_shutdown_function( 'cs_cleanup' ); // Also runs after an exception, so a failed run leaves no posts behind.
function cs_check( $condition, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) { $failures[] = $label; }
}
function cs_post( $title, $type = 'post', array $meta = array(), $status = 'publish' ) {
	global $created;
	$id = wp_insert_post( array( 'post_title' => $title, 'post_content' => '<p>Body of ' . $title . '</p>', 'post_status' => $status, 'post_type' => $type, 'post_author' => 1, 'meta_input' => $meta ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$created[] = $id;
	return $id;
}
function cs_cell( $type, $id, $column = 'mwseo_cornerstone' ) {
	ob_start();
	do_action( "manage_{$type}_posts_custom_column", $column, $id );
	return ob_get_clean();
}
function cs_filter_html( $type, $which = 'top' ) {
	ob_start();
	do_action( 'restrict_manage_posts', $type, $which );
	return ob_get_clean();
}
// An admin list query: the main query of edit.php for the given post type.
function cs_list( $type, array $extra = array() ) {
	global $wp_the_query;
	$GLOBALS['pagenow'] = 'edit.php';
	set_current_screen( 'edit-' . $type );
	$q = new WP_Query();
	$wp_the_query = $q;
	$q->query( array_merge( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC' ), $extra ) );
	return array_values( array_intersect( array_map( 'intval', $q->posts ), $GLOBALS['created'] ) ); // Only this test's own posts.
}
$saved_options = get_option( MWSEO_Options::KEY, array() );
$options       = $saved_options;
$options['post_types'] = array( 'post', 'page' );
update_option( MWSEO_Options::KEY, $options );
MWSEO_Options::flush();
register_post_type( 'mwseo_other', array( 'public' => true ) );

$own      = cs_post( 'Own flag', 'post', array( '_mwseo_cornerstone' => '1' ) );
$plain    = cs_post( 'Plain post' );
$yoast    = cs_post( 'Yoast flag', 'post', array( '_yoast_wpseo_is_cornerstone' => '1' ) );
$rankmath = cs_post( 'Rank Math flag', 'post', array( 'rank_math_pillar_content' => 'on' ) );
$draft    = cs_post( 'Draft flag', 'post', array( '_mwseo_cornerstone' => '1' ), 'draft' );
$page_cs  = cs_post( 'Page own flag', 'page', array( '_mwseo_cornerstone' => '1' ) );
$page_pl  = cs_post( 'Plain page', 'page' );
$other_cs = cs_post( 'Other type flag', 'mwseo_other', array( '_mwseo_cornerstone' => '1' ) );
$other_pl = cs_post( 'Other type plain', 'mwseo_other' );
MWSEO_Metabox::register_cornerstone_list();

// ---- Column -----------------------------------------------------------------------------------
cs_check( false !== has_filter( 'manage_post_posts_columns', array( 'MWSEO_Metabox', 'cornerstone_column' ) ), 'column registered for Posts' );
cs_check( false !== has_filter( 'manage_page_posts_columns', array( 'MWSEO_Metabox', 'cornerstone_column' ) ), 'column registered for Pages' );
cs_check( false === has_filter( 'manage_mwseo_other_posts_columns', array( 'MWSEO_Metabox', 'cornerstone_column' ) ), 'no column for a post type that is not configured' );
foreach ( array( 'post', 'page' ) as $type ) {
	$cols = apply_filters( "manage_{$type}_posts_columns", array( 'cb' => '', 'title' => 'Title' ) );
	cs_check( isset( $cols['mwseo_cornerstone'] ) && 'Cornerstone' === $cols['mwseo_cornerstone'], "$type header is Cornerstone" );
}
foreach ( array( 'own flag' => $own, 'Yoast flag' => $yoast, 'Rank Math flag' => $rankmath, 'draft with own flag' => $draft ) as $label => $id ) {
	$html = cs_cell( 'post', $id );
	cs_check( false !== strpos( $html, 'dashicons-star-filled' ) && false !== strpos( $html, 'Cornerstone' ), "post cell marks $label" );
}
$html = cs_cell( 'post', $plain );
cs_check( false === strpos( $html, 'dashicons-star-filled' ) && false !== strpos( $html, 'Not cornerstone content' ), 'post cell is empty for a plain post, with a screen-reader label' );
cs_check( false !== strpos( cs_cell( 'page', $page_cs ), 'dashicons-star-filled' ), 'page cell marks a cornerstone page' );
cs_check( false === strpos( cs_cell( 'page', $page_pl ), 'dashicons-star-filled' ), 'page cell is empty for a plain page' );
cs_check( '' === cs_cell( 'post', $own, 'mwseo_score' ) || false === strpos( cs_cell( 'post', $own, 'mwseo_score' ), 'dashicons-star' ), 'other columns are untouched' );

// ---- Filter control ---------------------------------------------------------------------------
unset( $_GET['mwseo_cornerstone'] );
$ui = cs_filter_html( 'post' );
cs_check( false !== strpos( $ui, 'name="mwseo_cornerstone"' ) && false !== strpos( $ui, 'Only cornerstone' ), 'filter control is shown on the Posts list' );
cs_check( false === strpos( $ui, "selected='selected'" ), 'filter is not preselected without the query argument' );
cs_check( false !== strpos( cs_filter_html( 'page' ), 'Only cornerstone' ), 'filter control is shown on the Pages list' );
cs_check( '' === cs_filter_html( 'post', 'bottom' ), 'filter control only above the table' );
cs_check( '' === cs_filter_html( 'mwseo_other' ), 'no filter control for a post type that is not configured' );
$_GET['mwseo_cornerstone'] = '1';
cs_check( false !== strpos( cs_filter_html( 'post' ), "selected='selected'" ), 'filter control keeps its selection' );

// ---- Filtering the list -----------------------------------------------------------------------
$_GET['mwseo_cornerstone'] = '1';
$ids = cs_list( 'post' );
cs_check( $ids === array( $own, $yoast, $rankmath, $draft ), 'Posts: only cornerstone items (own, Yoast, Rank Math, draft): ' . implode( ',', $ids ) );
cs_check( cs_list( 'page' ) === array( $page_cs ), 'Pages: only the cornerstone page' );
$ids = cs_list( 'post', array( 'meta_query' => array( array( 'key' => '_yoast_wpseo_is_cornerstone', 'compare' => 'EXISTS' ) ) ) );
cs_check( $ids === array( $yoast ), 'an existing meta query is combined with AND, not replaced: ' . implode( ',', $ids ) );
cs_check( cs_list( 'mwseo_other' ) === array( $other_cs, $other_pl ), 'a post type that is not configured is never filtered' );
foreach ( array( '', '0', 'yes', '1 ' ) as $value ) {
	$_GET['mwseo_cornerstone'] = $value;
	$ids = cs_list( 'post' );
	$expect = '1 ' === $value ? array( $own, $yoast, $rankmath, $draft ) : array( $own, $plain, $yoast, $rankmath, $draft );
	cs_check( $ids === $expect, 'argument ' . json_encode( $value ) . ' lists ' . ( '1 ' === $value ? 'cornerstone only' : 'everything' ) );
}
unset( $_GET['mwseo_cornerstone'] );
cs_check( count( cs_list( 'post' ) ) === 5, 'no argument: nothing is filtered' );
$_GET['mwseo_cornerstone'] = '1';
$GLOBALS['pagenow'] = 'index.php';
set_current_screen( 'front' );
$q = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
cs_check( count( array_intersect( $q->posts, $created ) ) === 5, 'outside the admin list the argument does nothing' );

// ---- One effective value: checkbox, column, filter and llms.txt -------------------------------
// Rule (MWSEO_Meta::get): if our own key exists it alone decides, so an explicit opt-out (a saved empty
// value or 0) wins over an old Yoast/Rank Math flag; only an absent key falls back to Yoast's value
// when it has one, otherwise Rank Math's. This oracle is written out by hand, not taken from the code.
function cs_expected( $own, $yoast, $rank ) {
	$yes = array( '1', 'true', 'on' );
	if ( null !== $own ) { return in_array( $own, $yes, true ); }
	if ( null !== $yoast && '' !== $yoast ) { return in_array( $yoast, $yes, true ); }
	return null !== $rank && in_array( $rank, $yes, true );
}
function cs_llms_key_section( $body ) {
	if ( ! preg_match( '/^## Key content\R(.*?)(?=^## |\z)/ms', $body, $m ) ) { return ''; }
	return $m[1];
}
$matrix = array();
foreach ( array( 'absent' => null, 'empty' => '', 'zero' => '0', 'one' => '1' ) as $o_name => $o ) {
	foreach ( array( 'absent' => null, 'empty' => '', 'zero' => '0', 'one' => '1' ) as $y_name => $y ) {
		foreach ( array( 'absent' => null, 'empty' => '', 'off' => 'off', 'on' => 'on' ) as $r_name => $r ) {
			$meta = array();
			if ( null !== $o ) { $meta['_mwseo_cornerstone'] = $o; }
			if ( null !== $y ) { $meta['_yoast_wpseo_is_cornerstone'] = $y; }
			if ( null !== $r ) { $meta['rank_math_pillar_content'] = $r; }
			$title = "mx own=$o_name yoast=$y_name rank=$r_name";
			$id = cs_post( $title, 'post', $meta );
			$matrix[ $id ] = array( 'title' => $title, 'expected' => cs_expected( $o, $y, $r ), 'own_exists' => null !== $o );
		}
	}
}
cs_check( 64 === count( $matrix ), 'the matrix has every combination' );

$_GET['mwseo_cornerstone'] = '1';
$listed = array_intersect( cs_list( 'post' ), array_keys( $matrix ) );
unset( $_GET['mwseo_cornerstone'] );
$key_section = cs_llms_key_section( MWSEO_Llms_Txt::build() );
foreach ( $matrix as $id => $row ) {
	$label = $row['title'];
	$want  = $row['expected'];
	cs_check( $want === MWSEO_Meta::get( $id, 'cornerstone' ), "$label: effective value is " . json_encode( $want ) );
	cs_check( $want === ( false !== strpos( cs_cell( 'post', $id ), 'dashicons-star-filled' ) ), "$label: column agrees" );
	cs_check( $want === in_array( $id, $listed, true ), "$label: 'Only cornerstone' agrees" );
	cs_check( $want === ( false !== strpos( $key_section, '[' . $row['title'] . ']' ) ), "$label: llms.txt Key content agrees" );
}

// The four cases named in the review, spelled out.
$by_title = array_column( array_map( null, array_keys( $matrix ), array_column( $matrix, 'title' ) ), 0, 1 );
foreach ( array(
	'Manny flag 1: included'                                   => array( 'mx own=one yoast=absent rank=absent', true ),
	'Manny flag 0 plus an old Yoast flag: excluded'            => array( 'mx own=zero yoast=one rank=absent', false ),
	'Manny flag 0 plus an old Rank Math flag: excluded'        => array( 'mx own=zero yoast=absent rank=on', false ),
	'No Manny flag plus an old Yoast flag: included'           => array( 'mx own=absent yoast=one rank=absent', true ),
	'No Manny flag plus an old Rank Math flag: included'       => array( 'mx own=absent yoast=absent rank=on', true ),
	'Manny flag saved empty (unticked) plus Yoast: excluded'   => array( 'mx own=empty yoast=one rank=absent', false ),
) as $label => $case ) {
	$id = $by_title[ $case[0] ];
	cs_check( $case[1] === in_array( $id, $listed, true ), "filter: $label" );
	cs_check( $case[1] === ( false !== strpos( cs_cell( 'post', $id ), 'dashicons-star-filled' ) ), "column: $label" );
	cs_check( $case[1] === ( false !== strpos( $key_section, '[' . $case[0] . ']' ) ), "llms.txt: $label" );
}

// Unticking the checkbox in the editor saves the flag through the REST API: that opt-out must win everywhere.
$ticked = cs_post( 'rest optout', 'post', array( '_mwseo_cornerstone' => '1', '_yoast_wpseo_is_cornerstone' => '1' ) );
$req    = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $ticked );
$req->set_body_params( array( 'meta' => array( '_mwseo_cornerstone' => false ) ) );
$res = rest_do_request( $req );
cs_check( ! $res->is_error() && metadata_exists( 'post', $ticked, '_mwseo_cornerstone' ), 'REST opt-out keeps an explicit (empty) flag row' );
$_GET['mwseo_cornerstone'] = '1';
cs_check( ! in_array( $ticked, cs_list( 'post' ), true ), 'REST opt-out: removed from Only cornerstone despite the old Yoast flag' );
unset( $_GET['mwseo_cornerstone'] );
cs_check( false === strpos( cs_cell( 'post', $ticked ), 'dashicons-star-filled' ), 'REST opt-out: column agrees' );
cs_check( false === strpos( cs_llms_key_section( MWSEO_Llms_Txt::build() ), '[rest optout]' ), 'REST opt-out: llms.txt agrees' );

// noindex keeps excluding posts from llms.txt, cornerstone or not; it does not change what is cornerstone.
$n_own  = cs_post( 'ni own noindex', 'post', array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '1' ) );
$n_ok   = cs_post( 'ni own indexable', 'post', array( '_mwseo_cornerstone' => '1', '_mwseo_noindex' => '0' ) );
$n_yoa  = cs_post( 'ni yoast noindex', 'post', array( '_yoast_wpseo_is_cornerstone' => '1', '_mwseo_noindex' => '1' ) );
$n_out  = cs_post( 'ni optout noindex', 'post', array( '_mwseo_cornerstone' => '0', '_yoast_wpseo_is_cornerstone' => '1', '_mwseo_noindex' => '1' ) );
$body   = MWSEO_Llms_Txt::build();
$keys   = cs_llms_key_section( $body );
cs_check( false === strpos( $body, '[ni own noindex]' ), 'noindex cornerstone post is absent from llms.txt' );
cs_check( false === strpos( $body, '[ni yoast noindex]' ), 'noindex post with an old Yoast flag is absent from llms.txt' );
cs_check( false === strpos( $body, '[ni optout noindex]' ), 'noindex opted-out post is absent from llms.txt' );
cs_check( false !== strpos( $keys, '[ni own indexable]' ), 'an indexable cornerstone post is still in Key content' );
cs_check( false !== strpos( cs_cell( 'post', $n_own ), 'dashicons-star-filled' ), 'a noindex cornerstone post is still marked cornerstone in the list' );

// ---- Reuses the existing flag; no second flag; no scoring link ----------------------------------
$keys = array_filter( array_keys( get_post_meta( $own ) ), static function ( $key ) { return false !== strpos( $key, 'cornerstone' ); } );
cs_check( array( '_mwseo_cornerstone' ) === array_values( $keys ), 'the list reads and writes only _mwseo_cornerstone' );
$registered = array_filter( array_keys( get_registered_meta_keys( 'post', 'post' ) ), static function ( $key ) { return false !== strpos( $key, 'cornerstone' ); } );
cs_check( array( '_mwseo_cornerstone' ) === array_values( $registered ), 'no second cornerstone meta key is registered' );
foreach ( array( 'class-mwseo-scorer.php', 'class-mwseo-analyzer.php' ) as $file ) {
	cs_check( false === stripos( file_get_contents( MWSEO_DIR . 'includes/' . $file ), 'cornerstone' ), "$file does not know about cornerstone" );
}
$strings = new ReflectionMethod( 'MWSEO_Companion', 'strings' );
$strings->setAccessible( true );
$labels = $strings->invoke( null );
cs_check( 0 === strpos( $labels['cornerstone'], 'Cornerstone content' ), 'the editor checkbox is labelled Cornerstone content' );
cs_check( false === stripos( $labels['cornerstone'], 'anchor' ), 'the editor checkbox no longer says Anchor post' );

// ---- Clean up ---------------------------------------------------------------------------------
cs_cleanup();
echo wp_json_encode( array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'checks' => $checks, 'failures' => $failures ) ) . "\n";
exit( $failures ? 1 : 0 );
