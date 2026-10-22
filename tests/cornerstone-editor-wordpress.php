<?php
/**
 * 1.0.9: the editor's view of the cornerstone flag, and what saving does to it.
 * php tests/cornerstone-editor-wordpress.php /disposable/site/wp-load.php
 *
 * Requires real WordPress with an activated Manny plugin on a DISPOSABLE site: a solstice_ table prefix,
 * or MWSEO_DISPOSABLE_SITE=1. Uses the real REST API in-process, as the block editor does (context=edit
 * to load, a partial body to save). Creates and removes its own posts, also after a failure.
 *
 * The checkbox is bound to the "meta" the editor loads. A post that only carries an old Yoast SEO or
 * Rank Math flag must load as ticked (the effective value), loading must write nothing, saving unrelated
 * fields must not create or change the flag, and only an explicit untick may opt the post out.
 */
require $argv[1];
if ( 0 !== strpos( $wpdb->prefix, 'solstice_' ) && '1' !== getenv( 'MWSEO_DISPOSABLE_SITE' ) ) {
	throw new RuntimeException( 'A disposable site is required: a solstice_ table prefix or MWSEO_DISPOSABLE_SITE=1.' );
}
wp_set_current_user( 1 );
$checks        = 0;
$failures      = array();
$created       = array();
$saved_options = null;
function ce_check( $condition, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $condition ) { $failures[] = $label; }
}
function ce_cleanup() {
	global $created, $saved_options;
	foreach ( $created as $id ) { wp_delete_post( $id, true ); }
	$created = array();
	if ( null !== $saved_options ) { update_option( MWSEO_Options::KEY, $saved_options ); MWSEO_Options::flush(); }
}
register_shutdown_function( 'ce_cleanup' );
$saved_options = get_option( MWSEO_Options::KEY, array() );
$options       = $saved_options;
$options['post_types'] = array( 'post', 'page' );
update_option( MWSEO_Options::KEY, $options );
MWSEO_Options::flush();
function ce_post( $title, array $meta ) {
	global $created;
	$id = wp_insert_post( array( 'post_title' => $title, 'post_content' => '<p>Body</p>', 'post_status' => 'publish', 'post_author' => 1, 'meta_input' => $meta ), true );
	if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
	$created[] = $id;
	return $id;
}
function ce_rest( $method, $id, array $params = array() ) {
	$r = new WP_REST_Request( $method, '/wp/v2/posts/' . $id );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	if ( 'GET' !== $method ) { $r->set_body_params( $params ); }
	return rest_do_request( $r );
}
// What the editor loads: the edit-context entity record. The checkbox shows this value.
function ce_editor_meta( $id ) {
	$d = ce_rest( 'GET', $id, array( 'context' => 'edit' ) )->get_data();
	return $d['meta'];
}
function ce_stored( $id ) { return metadata_exists( 'post', $id, '_mwseo_cornerstone' ) ? get_post_meta( $id, '_mwseo_cornerstone', true ) : null; }

foreach ( array(
	'Yoast flag only'     => array( '_yoast_wpseo_is_cornerstone' => '1' ),
	'Rank Math flag only' => array( 'rank_math_pillar_content' => 'on' ),
) as $label => $legacy ) {
	$id = ce_post( "editor $label", $legacy );
	$m  = ce_editor_meta( $id );
	ce_check( true === $m['_mwseo_cornerstone'], "$label: the editor loads the box ticked (effective value)" );
	ce_check( null === ce_stored( $id ), "$label: loading the editor writes nothing" );
	ce_check( true === MWSEO_Meta::get( $id, 'cornerstone' ), "$label: effective value is true" );

	ce_rest( 'POST', $id, array( 'title' => 'changed title' ) );
	ce_check( null === ce_stored( $id ), "$label: saving the title leaves the flag alone" );
	ce_rest( 'POST', $id, array( 'meta' => array( '_mwseo_focus' => 'some phrase' ) ) );
	ce_check( null === ce_stored( $id ), "$label: saving another SEO field leaves the flag alone" );
	ce_check( true === ce_editor_meta( $id )['_mwseo_cornerstone'], "$label: the editor still shows the box ticked after saving" );

	$other = ce_post( "editor $label noindex", $legacy );
	ce_rest( 'POST', $other, array( 'meta' => array( '_mwseo_noindex' => true ) ) );
	ce_check( null === ce_stored( $other ), "$label: ticking noindex does not touch the cornerstone flag" );

	ce_rest( 'POST', $id, array( 'meta' => array( '_mwseo_cornerstone' => false ) ) );
	ce_check( metadata_exists( 'post', $id, '_mwseo_cornerstone' ) && '' === ce_stored( $id ), "$label: unticking stores an explicit empty value" );
	ce_check( false === ce_editor_meta( $id )['_mwseo_cornerstone'] && false === MWSEO_Meta::get( $id, 'cornerstone' ), "$label: after the untick the editor and the effective value say not cornerstone" );
	ce_rest( 'POST', $id, array( 'title' => 'saved again' ) );
	ce_check( '' === ce_stored( $id ) && false === MWSEO_Meta::get( $id, 'cornerstone' ), "$label: a later save keeps the opt-out" );

	ce_rest( 'POST', $id, array( 'meta' => array( '_mwseo_cornerstone' => true ) ) );
	ce_check( '1' === ce_stored( $id ) && true === ce_editor_meta( $id )['_mwseo_cornerstone'], "$label: ticking again stores 1" );
}

// A Yoast value that is set but false beats Rank Math: effective false, so the editor loads unticked.
$id = ce_post( 'editor Yoast 0 plus Rank Math on', array( '_yoast_wpseo_is_cornerstone' => '0', 'rank_math_pillar_content' => 'on' ) );
ce_check( false === ce_editor_meta( $id )['_mwseo_cornerstone'] && null === ce_stored( $id ), 'Yoast 0 + Rank Math on: loads unticked, nothing written' );

// A client that sends back everything it received persists the effective value; that is not an opt-out.
$id = ce_post( 'editor echo of all meta', array( '_yoast_wpseo_is_cornerstone' => '1' ) );
ce_rest( 'POST', $id, array( 'meta' => ce_editor_meta( $id ) ) );
ce_check( '1' === ce_stored( $id ) && true === MWSEO_Meta::get( $id, 'cornerstone' ), 'echoing all meta stores the effective value (still cornerstone, not an opt-out)' );

// REST null resets (API-only; the editor never sends one). The existing guard rejects a reset whose stored value
// does not validate as a boolean, which includes an explicit opt-out stored as "": the opt-out survives.
$id = ce_post( 'editor reset', array( '_mwseo_cornerstone' => '', '_yoast_wpseo_is_cornerstone' => '1' ) );
ce_check( false === ce_editor_meta( $id )['_mwseo_cornerstone'], 'an explicit opt-out loads unticked despite the old flag' );
$res = ce_rest( 'POST', $id, array( 'meta' => array( '_mwseo_cornerstone' => null ) ) );
ce_check( $res->is_error() && 'mwseo_invalid_null_reset' === $res->as_error()->get_error_code(), 'a REST null reset of an explicit opt-out is rejected' );
ce_check( '' === ce_stored( $id ) && false === MWSEO_Meta::get( $id, 'cornerstone' ), 'the opt-out is unchanged after the rejected reset' );
$id = ce_post( 'editor reset from 1', array( '_mwseo_cornerstone' => '1', '_yoast_wpseo_is_cornerstone' => '1' ) );
$res = ce_rest( 'POST', $id, array( 'meta' => array( '_mwseo_cornerstone' => null ) ) );
ce_check( ! $res->is_error() && null === ce_stored( $id ) && true === MWSEO_Meta::get( $id, 'cornerstone' ), 'a REST null reset of a stored 1 removes it, and the old flag then applies' );

// A post with nothing stored anywhere is simply unticked.
$id = ce_post( 'editor nothing stored', array() );
ce_check( false === ce_editor_meta( $id )['_mwseo_cornerstone'] && null === ce_stored( $id ), 'no flag anywhere: unticked, nothing written' );

ce_cleanup();
echo wp_json_encode( array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'checks' => $checks, 'failures' => $failures ) ) . "\n";
exit( $failures ? 1 : 0 );
