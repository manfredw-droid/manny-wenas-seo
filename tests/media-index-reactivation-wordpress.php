<?php
/**
 * Media published while the plugin is deactivated (1.0.9.3). Real HTTP requests
 * against a disposable site.
 *
 *     php tests/media-index-reactivation-wordpress.php /path/to/wordpress
 *
 * While the plugin is deactivated nothing records which posts have an image or a
 * video. A post published in that time must still reach the image and video
 * sitemaps once the plugin is active again: reactivation has to make the plugin
 * read the content again, the sitemaps must keep listing every eligible post
 * while it does, and afterwards every post must be listed exactly once.
 *
 * Fails on the code before this fix: after deactivating, publishing a post with
 * an image and a video, and reactivating, that post is in neither sitemap and
 * stays out, because the plugin still believes it has read everything.
 *
 * The post is published by a second PHP process (this file, started with the
 * word "publish"), because the plugin stays loaded in the process that
 * deactivated it. The test deactivates and reactivates the plugin on the
 * disposable site, writes a small must-use plugin (pause switch) and removes
 * its fixtures again.
 */

if ( PHP_SAPI === 'cli' && isset( $argv[2] ) && 'publish' === $argv[2] ) {
	// Child process: WordPress without the (deactivated) plugin.
	require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
	if ( 0 !== strpos( $wpdb->prefix, 'mwfix_' ) || class_exists( 'MWSEO_Plugin', false ) ) {
		fwrite( STDERR, "Disposable mwfix_ site with the plugin deactivated required.\n" );
		exit( 2 );
	}
	$mwt_admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
		)
	);
	wp_set_current_user( $mwt_admins ? $mwt_admins[0]->ID : 0 );
	$mwt_id = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_title'   => 'MWRA published while deactivated',
			'post_name'    => 'mwra-while-deactivated',
			'post_content' => '<p>Published while the plugin was deactivated.</p><p><img src="https://img.example.test/mwra-new.jpg" alt="x"></p><p>https://www.youtube.com/watch?v=mwraNEW0001</p>',
		)
	);
	echo 'published:' . (int) $mwt_id;
	exit( 0 );
}

require __DIR__ . '/regression-1093-helpers.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

const MWRA_PLUGIN = 'manny-wenas-seo/manny-wenas-seo.php';

$mu_file = WPMU_PLUGIN_DIR . '/mwt-media-pause.php';

/**
 * Remove every fixture of this test (also leftovers of an aborted run).
 */
function mwra_cleanup() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'mwra-%'" );
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore
	}
	wp_cache_flush();
}

/**
 * Stored progress of the media index, read past every cache.
 *
 * @return array|null
 */
function mwra_progress() {
	global $wpdb;
	$raw   = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'mwseo_media_index'" );
	$state = $raw ? maybe_unserialize( $raw ) : null;
	return is_array( $state ) ? $state : null;
}

/**
 * Entry URLs of every image and video sitemap the index lists.
 *
 * @return array type => { pages: entries per listed sitemap, locs: all entry URLs }
 */
function mwra_listed() {
	$index = mwt_fetch( '/mwseo-sitemap.xml' );
	preg_match_all( '#<sitemap><loc>([^<]*)</loc></sitemap>#', $index['body'], $m );
	$out = array(
		'code'  => $index['code'],
		'image' => array(
			'pages' => array(),
			'locs'  => array(),
		),
		'video' => array(
			'pages' => array(),
			'locs'  => array(),
		),
	);
	foreach ( $m[1] as $loc ) {
		if ( ! preg_match( '#/mwseo-(image|video)-sitemap[0-9]*\.xml$#', $loc, $t ) ) {
			continue;
		}
		$map = mwt_fetch( (string) substr( $loc, strlen( untrailingslashit( home_url() ) ) ) );
		preg_match_all( '#<url><loc>([^<]*)</loc>#', $map['body'], $u );
		$out[ $t[1] ]['pages'][] = count( $u[1] );
		$out[ $t[1] ]['locs']    = array_merge( $out[ $t[1] ]['locs'], $u[1] );
	}
	return $out;
}

/**
 * How often a URL is listed.
 *
 * @param string[] $locs Entry URLs.
 * @param string   $url  URL.
 * @return int
 */
function mwra_times( array $locs, $url ) {
	return count( array_keys( $locs, $url, true ) );
}

$was_active = is_plugin_active( MWRA_PLUGIN );

try {
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) || ! $was_active ) {
		throw new RuntimeException( 'The disposable site must have the plugin active and use the /%postname%/ permalink structure.' );
	}
	mwra_cleanup();
	delete_option( 'mwt_media_pause' );
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents(
		$mu_file,
		"<?php\n// Written by tests/media-index-reactivation-wordpress.php; safe to delete.\n"
		. "if ( get_option( 'mwt_media_pause' ) ) {\n\tadd_filter( 'mwseo_media_index_seconds', '__return_zero' );\n}\n"
	);

	// --- Existing content: 1,205 posts, five of them with an image and a video ----
	$media = array();
	$wpdb->query( 'START TRANSACTION' );
	for ( $i = 1; $i <= 1205; $i++ ) {
		$has = in_array( $i, array( 3, 400, 801, 1100, 1204 ), true );
		$wpdb->insert(
			$wpdb->posts,
			array(
				'post_author'           => get_current_user_id(),
				'post_date'             => '2020-04-01 00:00:00',
				'post_date_gmt'         => '2020-04-01 00:00:00',
				'post_modified'         => '2020-04-01 00:00:00',
				'post_modified_gmt'     => '2020-04-01 00:00:00',
				'post_content'          => '<p>Existing post ' . $i . '.</p>' . ( $has ? '<p><img src="https://img.example.test/mwra-' . $i . '.jpg" alt="x"></p><p>https://www.youtube.com/watch?v=mwra' . sprintf( '%07d', $i ) . '</p>' : '' ),
				'post_title'            => 'MWRA ' . $i,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'post_name'             => 'mwra-old-' . $i,
				'post_type'             => 'post',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			)
		);
		if ( $has ) {
			$media[] = (int) $wpdb->insert_id;
		}
	}
	$wpdb->query( 'COMMIT' );
	wp_cache_flush();
	delete_option( 'mwseo_media_index' );
	$old_urls = array_map( 'get_permalink', $media );

	mwt_check( mwt_settle_media_index(), 'before: the plugin has read the existing content' );
	$state = mwra_listed();
	foreach ( array( 'image', 'video' ) as $type ) {
		sort( $state[ $type ]['locs'] );
		$want = $old_urls;
		sort( $want );
		mwt_check( $want === $state[ $type ]['locs'] && array( 5 ) === $state[ $type ]['pages'], "before: the $type sitemap lists exactly the five existing media posts", 'entries per listed sitemap: ' . implode( ',', $state[ $type ]['pages'] ) );
	}

	// --- Deactivate, publish a media post without the plugin, reactivate ----------
	// A catch-up that was still pending when the plugin is switched off must not stay scheduled.
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'mwseo_media_index_step' );
	deactivate_plugins( MWRA_PLUGIN );
	wp_cache_flush();
	mwt_check( ! is_plugin_active( MWRA_PLUGIN ) && false === wp_next_scheduled( 'mwseo_media_index_step' ), 'deactivation clears the scheduled catch-up event', 'next scheduled: ' . var_export( wp_next_scheduled( 'mwseo_media_index_step' ), true ) );
	wp_clear_scheduled_hook( 'mwseo_media_index_step' );

	$command = escapeshellarg( PHP_BINARY ) . ( php_ini_loaded_file() ? ' -c ' . escapeshellarg( php_ini_loaded_file() ) : '' ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $argv[1] ) . ' publish';
	$output  = (string) shell_exec( $command );
	$new     = preg_match( '/published:(\d+)/', $output, $m ) ? (int) $m[1] : 0;
	wp_cache_flush();
	$new_url = $new ? get_permalink( $new ) : '';
	mwt_check( $new > 0 && 'publish' === get_post_status( $new ), 'a post with an image and a video is published while the plugin is deactivated', 'child process said: ' . trim( $output ) );
	mwt_check( ! metadata_exists( 'post', $new, '_mwseo_has_image' ) && ! metadata_exists( 'post', $new, '_mwseo_has_video' ), 'nothing recorded that this post has media (the plugin was not running)' );

	// Reactivate, but give the plugin no time to read content yet: the state right after reactivation.
	update_option( 'mwt_media_pause', 1 );
	$activated = activate_plugin( MWRA_PLUGIN );
	wp_cache_flush();
	mwt_check( ! is_wp_error( $activated ) && is_plugin_active( MWRA_PLUGIN ), 'the plugin is reactivated' );

	$state    = mwra_listed();
	$progress = mwra_progress();
	mwt_check( 200 === $state['code'] && ( null === $progress || empty( $progress['done'] ) ), 'after reactivation: the plugin no longer claims to have read all content', 'stored progress: ' . wp_json_encode( $progress ) );
	foreach ( array( 'image', 'video' ) as $type ) {
		mwt_check( 1 === mwra_times( $state[ $type ]['locs'], $new_url ), "after reactivation, while content is read again: the new post is in the $type sitemap, once", 'listed ' . mwra_times( $state[ $type ]['locs'], $new_url ) . ' time(s); entries per listed sitemap: ' . implode( ',', $state[ $type ]['pages'] ) );
		$once = true;
		foreach ( $old_urls as $url ) {
			$once = $once && 1 === mwra_times( $state[ $type ]['locs'], $url );
		}
		mwt_check( $once && count( $state[ $type ]['locs'] ) === count( array_unique( $state[ $type ]['locs'] ) ), "after reactivation, while content is read again: every existing media post stays in the $type sitemap, once" );
	}

	// Let it finish.
	delete_option( 'mwt_media_pause' );
	mwt_check( mwt_settle_media_index(), 'after reactivation: the plugin reads the content again and finishes' );
	$state = mwra_listed();
	$want  = array_merge( $old_urls, array( $new_url ) );
	sort( $want );
	foreach ( array( 'image', 'video' ) as $type ) {
		$got = $state[ $type ]['locs'];
		sort( $got );
		mwt_check( $want === $got, "finished: the $type sitemap lists the five existing posts and the new one, each exactly once", 'expected ' . count( $want ) . ' entries, got ' . count( $got ) . '; new post listed ' . mwra_times( $got, $new_url ) . ' time(s)' );
		mwt_check( array( 6 ) === $state[ $type ]['pages'], "finished: the index lists one $type sitemap and it has entries", 'entries per listed sitemap: ' . implode( ',', $state[ $type ]['pages'] ) );
	}
} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	mwt_check( false, 'test ran to completion', get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getLine() );
} finally {
	delete_option( 'mwt_media_pause' );
	if ( $was_active && ! is_plugin_active( MWRA_PLUGIN ) ) {
		activate_plugin( MWRA_PLUGIN );
	}
	wp_clear_scheduled_hook( 'mwseo_media_index_step' );
	mwra_cleanup();
	if ( file_exists( $mu_file ) ) {
		unlink( $mu_file );
	}
}

mwt_finish( 'media-index-reactivation' );
