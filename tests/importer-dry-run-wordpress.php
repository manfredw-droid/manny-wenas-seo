<?php
/**
 * Real WordPress importer regression. Run only against a dedicated disposable site:
 * php tests/importer-dry-run-wordpress.php /path/to/wordpress
 * Requires an installed, active Manny plugin, an administrator, and a table prefix
 * starting solstice_dry_. Creates and permanently deletes only its own fixtures.
 */
if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) ) {
    exit( "CLI WordPress root required.\n" );
}
define( 'DOING_AJAX', true );
require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
if ( strpos( $wpdb->prefix, 'solstice_dry_' ) !== 0 || ! class_exists( 'MWSEO_Importer' ) ) {
    throw new RuntimeException( 'Dedicated disposable prefix and active importer required.' );
}
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admins ) {
    throw new RuntimeException( 'Disposable administrator required.' );
}
wp_set_current_user( $admins[0]->ID );
register_post_type( 'dry_backend', array( 'public' => true, 'supports' => array( 'title' ) ) );
class Importer_Dry_Run_Response extends RuntimeException {}
add_filter( 'wp_die_ajax_handler', function () {
    return function () { throw new Importer_Dry_Run_Response(); };
} );
$sources = array(
    'yoast' => array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc' ),
    'rankmath' => array( 'rank_math_title', 'rank_math_description' ),
    'aioseo' => array( '_aioseo_title', '_aioseo_description' ),
    'seopress' => array( '_seopress_titles_title', '_seopress_titles_desc' ),
    'tsf' => array( '_genesis_title', '_genesis_description' ),
);
$ids = array();
$results = array();
$assert = function ( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
};
try {
    foreach ( array( 'missing', 'existing' ) as $state ) {
        $id = wp_insert_post( array( 'post_type' => 'dry_backend', 'post_status' => 'publish', 'post_title' => 'Disposable importer ' . $state ), true );
        if ( is_wp_error( $id ) ) { throw new RuntimeException( $id->get_error_message() ); }
        $ids[ $state ] = $id;
    }
    $snapshot = function () use ( $wpdb, &$ids ) {
        return $wpdb->get_results( 'SELECT meta_id,post_id,meta_key,meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ') ORDER BY meta_id', ARRAY_A );
    };
    foreach ( $sources as $source => $keys ) {
        // Include omitted flags (safe default) and matching targets: comparing
        // only final values would miss attempted writes of identical values.
        $cases = array();
        foreach ( array( false, true ) as $overwrite ) {
            foreach ( array( '1', 'omitted', '0' ) as $dry ) {
                $cases[] = array( $overwrite, $dry, false );
            }
        }
        $cases[] = array( true, '1', true );
        foreach ( $cases as $case ) {
            list( $overwrite, $dry, $matching ) = $case;
            $native = in_array( $source, array( 'yoast', 'rankmath' ), true );
            $targets = $native ? array( '_mwseo_title', '_mwseo_desc' ) : array( '_mw_seo_title', '_mw_seo_description' );
            foreach ( $ids as $state => $id ) {
                foreach ( array_keys( get_post_meta( $id ) ) as $key ) { delete_post_meta( $id, $key ); }
                foreach ( $sources as $source_keys ) {
                    update_post_meta( $id, $source_keys[0], 'SOURCE TITLE' );
                    update_post_meta( $id, $source_keys[1], 'SOURCE DESCRIPTION' );
                }
                if ( $state === 'existing' ) {
                    foreach ( array( '_mwseo_title', '_mwseo_desc', '_mw_seo_title', '_mw_seo_description' ) as $key ) {
                        update_post_meta( $id, $key, 'EXISTING TARGET' );
                    }
                    if ( $matching ) {
                        update_post_meta( $id, $targets[0], 'SOURCE TITLE' );
                        update_post_meta( $id, $targets[1], 'SOURCE DESCRIPTION' );
                    }
                }
            }
            $before = $snapshot();
            $attempts = array();
            $sql_writes = array();
            $observer = function ( $check, $id, $key, $value ) use ( &$attempts ) {
                $attempts[] = array( 'id' => $id, 'key' => $key, 'value' => $value );
                return $check;
            };
            foreach ( array( 'add', 'update', 'delete' ) as $operation ) {
                add_filter( $operation . '_post_metadata', $observer, 1, 4 );
            }
            $query_observer = function ( $query ) use ( &$sql_writes, $wpdb ) {
                if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) && strpos( $query, $wpdb->postmeta ) !== false ) { $sql_writes[] = $query; }
                return $query;
            };
            add_filter( 'query', $query_observer );
            $_POST = array( 'action' => 'mw_seo_import_batch', 'nonce' => wp_create_nonce( 'mw_seo_import' ), 'source' => $source, 'post_types' => array( 'dry_backend' ), 'overwrite' => $overwrite ? '1' : '0', 'offset' => '0', 'batch_size' => '50' );
            if ( $dry !== 'omitted' ) { $_POST['dry_run'] = $dry; }
            $_REQUEST = $_POST;
            ob_start();
            try { do_action( 'wp_ajax_mw_seo_import_batch' ); }
            catch ( Importer_Dry_Run_Response $response ) { /* Actual wp_send_json completed. */ }
            $response = json_decode( ob_get_clean(), true );
            foreach ( array( 'add', 'update', 'delete' ) as $operation ) { remove_filter( $operation . '_post_metadata', $observer, 1 ); }
            remove_filter( 'query', $query_observer );
            $after = $snapshot();
            $record = array( 'source' => $source, 'dry_run' => $dry, 'overwrite' => $overwrite, 'matching_target' => $matching, 'ids' => $ids, 'request' => $_POST, 'response' => $response, 'attempts' => $attempts, 'sql' => $sql_writes, 'before' => $before, 'after' => $after );
            try {
                $assert( ! empty( $response['success'] ), 'AJAX response unsuccessful' );
                $assert( isset( $response['data']['processed'] ) && $response['data']['processed'] === 2, 'Expected two fixture posts' );
                if ( $dry !== '0' ) {
                    $assert( ! $attempts && ! $sql_writes && $before === $after, 'Dry run attempted or persisted a metadata write' );
                } else {
                    $assert( count( $attempts ) > 0 && count( $sql_writes ) > 0, 'Real import did not exercise write instrumentation' );
                    foreach ( $targets as $index => $key ) {
                        $expected = $index === 0 ? 'SOURCE TITLE' : 'SOURCE DESCRIPTION';
                        $assert( get_post_meta( $ids['missing'], $key, true ) === $expected, 'Missing destination not imported' );
                        $assert( get_post_meta( $ids['existing'], $key, true ) === ( $overwrite ? $expected : 'EXISTING TARGET' ), 'Overwrite behavior changed' );
                    }
                    if ( ! $native ) {
                        $assert( ! metadata_exists( 'post', $ids['missing'], '_mwseo_title' ), 'Legacy adapter unexpectedly gained native mapping' );
                        $assert( get_post_meta( $ids['existing'], '_mwseo_title', true ) === 'EXISTING TARGET', 'Legacy adapter changed native sentinel' );
                    }
                }
                foreach ( $ids as $id ) {
                    foreach ( $sources as $source_keys ) {
                        $assert( get_post_meta( $id, $source_keys[0], true ) === 'SOURCE TITLE' && get_post_meta( $id, $source_keys[1], true ) === 'SOURCE DESCRIPTION', 'Source metadata changed' );
                    }
                }
                $record['status'] = 'PASS';
            } catch ( RuntimeException $failure ) { $record['status'] = 'FAIL'; $record['error'] = $failure->getMessage(); }
            $results[] = $record;
        }
    }
} finally {
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
}
$failed = count( array_filter( $results, function ( $result ) { return $result['status'] === 'FAIL'; } ) );
echo json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'passed' => count( $results ) - $failed, 'failed' => $failed, 'cases' => $results ), JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
