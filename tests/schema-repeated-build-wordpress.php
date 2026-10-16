<?php
/** Finding #22: real WordPress graph regressions, not mocked schema builders.
 * php tests/schema-repeated-build-wordpress.php /disposable/site/wp-load.php
 * Requires an activated Manny plugin and a solstice_schema22_ database prefix.
 */
require $argv[1];
if ( strpos( $wpdb->prefix, 'solstice_schema22_' ) !== 0 ) {
    throw new RuntimeException( 'A disposable schema22 database is required.' );
}
wp_set_current_user( 1 );
$saved_options = get_option( MWSEO_Options::KEY, array() );
$fixture_posts = array();
$results = array();
$checks = 0;
$failures = 0;
function schema22_check( $condition, $label ) {
    global $checks, $failures, $results;
    ++$checks;
    if ( ! $condition ) { ++$failures; }
    $results[] = array( 'check' => $label, 'pass' => (bool) $condition );
}
function schema22_index( $graph ) {
    $indexed = array();
    foreach ( $graph as $node ) { $indexed[ $node['@id'] ] = $node; }
    return $indexed;
}
function schema22_logo( $name ) {
    global $fixture_posts;
    // A real 1x1 GIF and ordinary WordPress attachment metadata, no image API stub.
    $upload = wp_upload_bits( $name . '.gif', null, base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ) );
    if ( $upload['error'] ) { throw new RuntimeException( $upload['error'] ); }
    $attachment = wp_insert_attachment( array( 'post_title' => $name, 'post_mime_type' => 'image/gif', 'post_author' => 1 ), $upload['file'], 0, true );
    if ( is_wp_error( $attachment ) ) { unlink( $upload['file'] ); throw new RuntimeException( $attachment->get_error_message() ); }
    $fixture_posts[] = $attachment;
    wp_update_attachment_metadata( $attachment, array( 'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path( $upload['file'] ), 'sizes' => array() ) );
    update_post_meta( $attachment, '_wp_attachment_image_alt', $name );
    return $attachment;
}
$graphs = array();
try {
    $article_id = wp_insert_post( array( 'post_title' => 'Schema repeat fixture', 'post_content' => '<p>Disposable article content.</p>', 'post_status' => 'publish', 'post_author' => 1, 'post_date' => '2026-10-16 10:00:00', 'post_date_gmt' => '2026-10-16 10:00:00' ), true );
    if ( is_wp_error( $article_id ) ) { throw new RuntimeException( $article_id->get_error_message() ); }
    $fixture_posts[] = $article_id;
    $logo_a = schema22_logo( 'Schema logo A' );
    $logo_b = schema22_logo( 'Schema logo B' );
    schema22_check( (bool) wp_get_attachment_image_src( $logo_a, 'full' ), 'Real logo attachment resolves through core image API' );
    $GLOBALS['wp_query'] = new WP_Query( array( 'p' => $article_id, 'post_type' => 'post' ) );
    $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
    $GLOBALS['post'] = get_post( $article_id );
    $GLOBALS['wp']->request = 'schema-repeat-fixture';
    $cases = array(
        'organization_logo' => array( 'organization', 0, $logo_a ),
        'organization_no_logo' => array( 'organization', 0, 0 ),
        'person_logo' => array( 'person', 1, $logo_a ),
        'person_no_logo' => array( 'person', 1, 0 ),
        'both_logo' => array( 'both', 1, $logo_a ),
        'both_no_logo' => array( 'both', 1, 0 ),
        'person_missing_user' => array( 'person', 99999999, $logo_a ),
        'both_missing_user' => array( 'both', 99999999, $logo_a ),
        'invalid_logo' => array( 'organization', 0, 99999999 ),
        'shared_featured_logo' => array( 'organization', 0, $logo_a ),
        'changed_organization_and_logo' => array( 'organization', 0, $logo_b ),
    );
    foreach ( $cases as $name => $settings ) {
        list( $mode, $person, $logo ) = $settings;
        delete_post_thumbnail( $article_id );
        if ( 'shared_featured_logo' === $name ) { set_post_thumbnail( $article_id, $logo ); }
        MWSEO_Options::save( array_merge( $saved_options, array( 'site_represents' => $mode, 'person_user' => $person, 'org_logo' => $logo, 'org_name' => $name, 'social_profiles' => "https://example.invalid/" . $name, 'default_image' => 0 ) ) );
        $first = MWSEO_Schema::build();
        $second = MWSEO_Schema::build();
        $instance_a = new MWSEO_Schema();
        $instance_b = new MWSEO_Schema();
        $third = $instance_a->build();
        $fourth = $instance_b->build();
        $graphs[ $name ] = array( $first, $second, $third, $fourth );
        schema22_check( $first === $second && $first === $third && $first === $fourth, $name . ': complete identical repeated/static/instance builds' );
        $expected_type = 'Organization';
        $publisher_id = home_url( '/#/schema/Organization/1' );
        $expected_name = $name;
        if ( 1 === $person && 'person' === $mode ) {
            $expected_type = 'Person';
            $publisher_id = home_url( '/#/schema/Person/' . md5( hash( 'sha256', get_home_url() ) . '1' ) );
            $expected_name = get_userdata( 1 )->display_name;
        } elseif ( 1 === $person && 'both' === $mode ) {
            $expected_type = array( 'Person', 'Organization' );
            $expected_name = get_userdata( 1 )->display_name;
        }
        $valid_logo = in_array( $logo, array( $logo_a, $logo_b ), true );
        foreach ( $graphs[ $name ] as $iteration => $graph ) {
            $index = schema22_index( $graph );
            $publisher = $index[ $publisher_id ] ?? array();
            schema22_check( count( $index ) === count( $graph ), $name . '/' . $iteration . ': unique node IDs' );
            schema22_check( ( $publisher['@type'] ?? null ) === $expected_type && ( $publisher['name'] ?? null ) === $expected_name && ( $publisher['sameAs'] ?? array() ) === array( 'https://example.invalid/' . $name ), $name . '/' . $iteration . ': current publisher type/name/profiles and stable identifier' );
            schema22_check( ( $index[ home_url( '/#/schema/WebSite/1' ) ]['publisher']['@id'] ?? null ) === $publisher_id && ( $index[ home_url( '/#/schema/Article/' . $article_id ) ]['publisher']['@id'] ?? null ) === $publisher_id, $name . '/' . $iteration . ': website/article publisher references resolve' );
            if ( $valid_logo ) {
                $image_id = home_url( '/#/schema/ImageObject/' . $logo );
                schema22_check( ( $publisher['logo'] ?? array() ) === array( array( '@id' => $image_id ) ) && ( $publisher['image'] ?? array() ) === array( array( '@id' => $image_id ) ) && ( $index[ $image_id ]['@type'] ?? null ) === 'ImageObject' && ( $index[ $image_id ]['url'] ?? null ) === wp_get_attachment_url( $logo ), $name . '/' . $iteration . ': logo references resolve to correct ImageObject' );
                if ( $logo === $logo_b ) { schema22_check( ! isset( $index[ home_url( '/#/schema/ImageObject/' . $logo_a ) ] ), $name . '/' . $iteration . ': no stale prior logo' ); }
            } else {
                schema22_check( ! array_key_exists( 'logo', $publisher ) && ! array_key_exists( 'image', $publisher ) && ! array_filter( $graph, function( $node ) { return $node['@type'] === 'ImageObject'; } ), $name . '/' . $iteration . ': no fabricated logo or dangling reference' );
            }
        }
    }
    // Same attachment, changed metadata: no stale cached image/caption reuse.
    update_post_meta( $logo_b, '_wp_attachment_image_alt', 'Updated logo caption' );
    $last = schema22_index( MWSEO_Schema::build() );
    schema22_check( ( $last[ home_url( '/#/schema/ImageObject/' . $logo_b ) ]['caption'] ?? null ) === 'Updated logo caption', 'Updated logo metadata is read on the next build' );
    $canonical = get_permalink( $article_id );
    $first_normalized = str_replace( array( '/ImageObject/' . $logo_a, '/Article/' . $article_id, md5( $canonical ), '?p=' . $article_id ), array( '/ImageObject/LOGO', '/Article/POST', 'BREADCRUMB', '?p=POST' ), wp_json_encode( $graphs['organization_logo'][0], JSON_UNESCAPED_SLASHES ) );
} finally {
    MWSEO_Options::save( is_array( $saved_options ) ? $saved_options : array() );
    foreach ( array_reverse( $fixture_posts ) as $fixture_id ) {
        if ( 'attachment' === get_post_type( $fixture_id ) ) { wp_delete_attachment( $fixture_id, true ); } else { wp_delete_post( $fixture_id, true ); }
    }
}
echo wp_json_encode( array( 'wordpress' => $wp_version, 'php' => PHP_VERSION, 'checks' => $checks, 'failures' => $failures, 'first_graph_normalized' => $first_normalized, 'results' => $results, 'graphs' => $graphs ), JSON_PRETTY_PRINT );
exit( $failures ? 1 : 0 );
