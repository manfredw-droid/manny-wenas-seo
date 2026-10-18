<?php
/**
 * B1 regression on a disposable equinox_ WordPress database.
 * php tests/editor-custom-fields-wordpress.php /path/to/wp-load.php
 */
$GLOBALS['wp_filter']['pre_option_mwseo_options'][10][] = array(
	'function' => static function () {
		return array( 'post_types' => array( 'post', 'page', 'equinox_no_meta' ) );
	},
	'accepted_args' => 1,
);
$GLOBALS['wp_filter']['init'][0][] = array(
	'function' => static function () {
		register_post_type( 'equinox_no_meta', array(
			'public'       => true,
			'show_in_rest' => true,
			'supports'     => array( 'title', 'editor' ),
		) );
	},
	'accepted_args' => 0,
);
require $argv[1];
if ( strpos( $wpdb->prefix, 'equinox_' ) !== 0 ) {
	throw new RuntimeException( 'Disposable equinox_ prefix required' );
}
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user( 1 );
$server   = rest_get_server();
$checks   = 0;
$failures = 0;
$fixtures = array();
function check_custom_fields( $ok, $label ) {
	global $checks, $failures;
	++$checks;
	if ( ! $ok ) { ++$failures; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
foreach ( array( 'equinox_no_meta' => 'equinox_no_meta', 'post' => 'posts', 'page' => 'pages' ) as $type => $rest_base ) {
	$fixture_id = wp_insert_post( array(
		'post_type'   => $type,
		'post_title'  => 'Equinox B1 fixture',
		'post_status' => 'draft',
		'post_author' => 1,
	) );
	$fixtures[] = $fixture_id;
	update_post_meta( $fixture_id, '_mwseo_focus', 'Existing focus' );
	update_post_meta( $fixture_id, '_mwseo_desc', 'Existing SEO description.' );
	$before = get_post_meta( $fixture_id );
	$request = new WP_REST_Request( 'GET', '/wp/v2/' . $rest_base . '/' . $fixture_id );
	$request->set_param( 'context', 'edit' );
	$response = $server->dispatch( $request );
	$data = $response->get_data();
	$has_fields = 'equinox_no_meta' !== $type;
	check_custom_fields( 200 === $response->get_status() &&
		( $has_fields ? isset( $data['meta']['_mwseo_focus'] ) : ! isset( $data['meta'] ) ),
		$type . ': real REST response reflects custom-fields support' );

	foreach ( array( 'mwseo-companion-core', 'mwseo-companion-block' ) as $handle ) {
		wp_dequeue_script( $handle );
	}
	wp_dequeue_style( 'mwseo-companion' );
	set_current_screen( $type );
	$screen = get_current_screen();
	$screen->is_block_editor( true );
	$GLOBALS['post'] = get_post( $fixture_id );
	MWSEO_Companion::block_assets();
	check_custom_fields( $has_fields === wp_script_is( 'mwseo-companion-core', 'enqueued' ) &&
		$has_fields === wp_script_is( 'mwseo-companion-block', 'enqueued' ) &&
		$has_fields === wp_style_is( 'mwseo-companion', 'enqueued' ),
		$type . ': native controls load only with required support' );
	ob_start();
	MWSEO_Metabox::editor_notice();
	$notice = ob_get_clean();
	check_custom_fields( $has_fields ? '' === $notice :
		false !== strpos( $notice, 'does not support custom-fields' ),
		$type . ': appropriate missing-support notice' );
	check_custom_fields( get_post_meta( $fixture_id ) === $before &&
		'Existing SEO description.' === MWSEO_Head::description_for_post( $fixture_id ),
		$type . ': stored metadata and frontend description remain available' );
}
check_custom_fields( ! post_type_supports( 'equinox_no_meta', 'custom-fields' ),
	'external CPT support was not added automatically' );
foreach ( $fixtures as $fixture_id ) {
	wp_delete_post( $fixture_id, true );
}
echo "WordPress $wp_version / PHP " . PHP_VERSION . ": $checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
