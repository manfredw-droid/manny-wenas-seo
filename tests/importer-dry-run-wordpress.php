<?php
/**
 * Real WordPress importer regression. Run only against a dedicated disposable site:
 * php tests/importer-dry-run-wordpress.php /path/to/wordpress
 * Requires an installed, active Manny plugin, an administrator, and a table prefix
 * starting solstice_dry_. Creates and permanently deletes only its own fixtures.
 *
 * Only Yoast and Rank Math are offered. Their Preview / Dry run / import / Overwrite
 * behavior is checked as before. AIOSEO, SEOPress, The SEO Framework and an unknown
 * source must be refused on the server for every action with zero metadata writes,
 * and the page must offer only the two supported sources even when all five source
 * plugins are (simulated as) present. Plugin detection (constants) and all source
 * metadata are simulated; no source plugin is installed.
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
    'nosuchplugin' => array( '_nosuch_title', '_nosuch_description' ),
);
$supported = array( 'yoast', 'rankmath' );
$refused = array( 'aioseo', 'seopress', 'tsf', 'nosuchplugin' );
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
    foreach ( $supported as $source ) {
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
            $targets = array( '_mwseo_title', '_mwseo_desc' );
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

    // ---- Disabled and unknown sources: refused on the server, zero metadata writes ----
    $run_request = function ( $post ) use ( $snapshot, $wpdb ) {
        $attempts = array();
        $sql_writes = array();
        $observer = function ( $check, $id, $key, $value ) use ( &$attempts ) {
            $attempts[] = array( 'id' => $id, 'key' => $key, 'value' => $value );
            return $check;
        };
        foreach ( array( 'add', 'update', 'delete' ) as $operation ) { add_filter( $operation . '_post_metadata', $observer, 1, 4 ); }
        $query_observer = function ( $query ) use ( &$sql_writes, $wpdb ) {
            if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) && strpos( $query, $wpdb->postmeta ) !== false ) { $sql_writes[] = $query; }
            return $query;
        };
        add_filter( 'query', $query_observer );
        $before = $snapshot();
        $_POST = $post;
        $_REQUEST = $_POST;
        ob_start();
        try { do_action( 'wp_ajax_mw_seo_import_batch' ); }
        catch ( Importer_Dry_Run_Response $response ) { /* Actual wp_send_json / wp_die completed. */ }
        $response = json_decode( ob_get_clean(), true );
        foreach ( array( 'add', 'update', 'delete' ) as $operation ) { remove_filter( $operation . '_post_metadata', $observer, 1 ); }
        remove_filter( 'query', $query_observer );
        return array( 'response' => $response, 'attempts' => $attempts, 'sql' => $sql_writes, 'before' => $before, 'after' => $snapshot() );
    };
    $seed_refusal_fixtures = function () use ( &$ids, $sources ) {
        foreach ( $ids as $state => $id ) {
            foreach ( array_keys( get_post_meta( $id ) ) as $key ) { delete_post_meta( $id, $key ); }
            foreach ( $sources as $source_keys ) {
                update_post_meta( $id, $source_keys[0], 'SOURCE TITLE' );
                update_post_meta( $id, $source_keys[1], 'SOURCE DESCRIPTION' );
            }
            // Historical importer records and native records must stay exactly as they are.
            update_post_meta( $id, '_mw_seo_title', 'LEGACY RECORD' );
            update_post_meta( $id, '_mw_seo_description', 'LEGACY RECORD' );
            update_post_meta( $id, '_mw_seo_robots', array( 'noindex' ) );
            if ( $state === 'existing' ) {
                update_post_meta( $id, '_mwseo_title', 'EXISTING TARGET' );
                update_post_meta( $id, '_mwseo_desc', 'EXISTING TARGET' );
            }
        }
    };
    $request_for = function ( $source, $overwrite, $dry, $nonce = null ) use ( $ids ) {
        $post = array( 'action' => 'mw_seo_import_batch', 'nonce' => $nonce ?? wp_create_nonce( 'mw_seo_import' ), 'source' => $source, 'post_types' => array( 'dry_backend' ), 'overwrite' => $overwrite ? '1' : '0', 'offset' => '0', 'batch_size' => '50' );
        if ( $dry !== 'omitted' ) { $post['dry_run'] = $dry; }
        return $post;
    };
    $check = function ( $name, $fn, $extra = array() ) use ( &$results ) {
        $record = array_merge( array( 'source' => $name ), $extra );
        try { $fn(); $record['status'] = 'PASS'; }
        catch ( RuntimeException $failure ) { $record['status'] = 'FAIL'; $record['error'] = $failure->getMessage(); }
        $results[] = $record;
    };
    foreach ( $refused as $source ) {
        foreach ( array( false, true ) as $overwrite ) {
            // '1' is what Preview and a checked Dry run send; '0' is a real import; omitted is the safe default.
            foreach ( array( '1', 'omitted', '0' ) as $dry ) {
                $seed_refusal_fixtures();
                $run = $run_request( $request_for( $source, $overwrite, $dry ) );
                $check( 'refused: ' . $source . ' dry_run=' . $dry . ' overwrite=' . ( $overwrite ? '1' : '0' ), function () use ( $run, $source, $ids, $assert ) {
                    $response = $run['response'];
                    $assert( is_array( $response ) && isset( $response['success'] ) && false === $response['success'], 'Request was not refused' );
                    $assert( isset( $response['data'] ) && is_string( $response['data'] ), 'Refusal carries no user message' );
                    if ( $source === 'nosuchplugin' ) {
                        $assert( strpos( $response['data'], 'Unknown source plugin' ) !== false, 'Unknown source not named as unknown' );
                    } else {
                        $assert( strpos( $response['data'], 'Yoast SEO and Rank Math' ) !== false && strpos( $response['data'], 'switched off' ) !== false, 'Disabled source lacks the explanation' );
                    }
                    $assert( ! $run['attempts'] && ! $run['sql'], 'Refused request attempted a metadata write' );
                    $assert( $run['before'] === $run['after'], 'Refused request changed stored metadata' );
                    foreach ( $ids as $id ) {
                        $assert( get_post_meta( $id, '_mw_seo_title', true ) === 'LEGACY RECORD' && get_post_meta( $id, '_mw_seo_description', true ) === 'LEGACY RECORD' && get_post_meta( $id, '_mw_seo_robots', true ) === array( 'noindex' ), 'Legacy record changed' );
                    }
                    $assert( get_post_meta( $ids['existing'], '_mwseo_title', true ) === 'EXISTING TARGET' && ! metadata_exists( 'post', $ids['missing'], '_mwseo_title' ), 'Native data changed' );
                } );
            }
        }
    }

    // ---- Nonce and capability checks stay in force for a supported source ----
    $seed_refusal_fixtures();
    $run = $run_request( $request_for( 'yoast', true, '0', 'not-a-valid-nonce' ) );
    $check( 'nonce: invalid nonce is rejected before any work', function () use ( $run, $assert ) {
        $assert( ! ( is_array( $run['response'] ) && ! empty( $run['response']['success'] ) ), 'Invalid nonce was accepted' );
        $assert( ! $run['attempts'] && ! $run['sql'] && $run['before'] === $run['after'], 'Invalid nonce led to a metadata write' );
    } );
    wp_set_current_user( 0 );
    $run = $run_request( $request_for( 'yoast', true, '0' ) );
    wp_set_current_user( $admins[0]->ID );
    $check( 'capability: a visitor without manage_options is rejected', function () use ( $run, $assert ) {
        $assert( is_array( $run['response'] ) && isset( $run['response']['success'] ) && false === $run['response']['success'], 'Request without capability was not refused' );
        $assert( ! $run['attempts'] && ! $run['sql'] && $run['before'] === $run['after'], 'Request without capability led to a metadata write' );
    } );

    // ---- The page offers only Yoast and Rank Math, also when all five plugins are present ----
    $offered = function () {
        ob_start();
        MWSEO_Importer::render_admin_page();
        $html = ob_get_clean();
        preg_match_all( '#<option value="([^"]+)"#', $html, $m );
        return array( $m[1], $html );
    };
    $script_maps = function () {
        $method = new ReflectionMethod( 'MWSEO_Importer', 'script_data' );
        $method->setAccessible( true );
        return array_keys( $method->invoke( null )['fieldMaps'] );
    };
    $detect_constants = array( 'WPSEO_VERSION', 'RANK_MATH_VERSION', 'AIOSEO_VERSION', 'SEOPRESS_VERSION', 'THE_SEO_FRAMEWORK_VERSION' );
    $none_defined = true;
    foreach ( $detect_constants as $constant ) { $none_defined = $none_defined && ! defined( $constant ); }
    list( $options, $html ) = $offered();
    $check( 'ui: no source plugin present still offers Yoast and Rank Math', function () use ( $options, $none_defined, $assert ) {
        $assert( $none_defined, 'Precondition: no detection constant may be defined yet' );
        $assert( $options === array( 'yoast', 'rankmath' ), 'Unexpected sources offered: ' . implode( ',', $options ) );
    } );
    foreach ( $detect_constants as $constant ) { if ( ! defined( $constant ) ) { define( $constant, 'simulated' ); } }
    list( $options, $html ) = $offered();
    $check( 'ui: all five plugins simulated present, only Yoast and Rank Math are offered', function () use ( $options, $html, $script_maps, $assert ) {
        $assert( count( MWSEO_Importer::detect_active_plugins() ) === 5, 'Simulation of all five plugins did not take effect' );
        $assert( $options === array( 'yoast', 'rankmath' ), 'Unexpected sources offered: ' . implode( ',', $options ) );
        $assert( $script_maps() === array( 'yoast', 'rankmath' ), 'Field maps still describe other sources' );
        $assert( strpos( $html, 'switched off' ) !== false && strpos( $html, 'All in One SEO' ) !== false, 'Page does not explain that the other sources are switched off' );
    } );
} finally {
    foreach ( $ids as $id ) { wp_delete_post( $id, true ); }
}
$failed = count( array_filter( $results, function ( $result ) { return $result['status'] === 'FAIL'; } ) );
echo json_encode( array( 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'passed' => count( $results ) - $failed, 'failed' => $failed, 'cases' => $results ), JSON_PRETTY_PRINT ) . "\n";
exit( $failed ? 1 : 0 );
