<?php
/**
 * Media published on a subsite while the plugin is network-deactivated (1.0.9.3).
 * Real HTTP requests against a disposable Multisite network.
 *
 *     php tests/media-index-multisite-wordpress.php /path/to/wordpress host[:port]
 *
 * WordPress runs a plugin's deactivation and activation code once for a whole
 * network, in the site the administrator happens to be on. Every other site
 * must still notice that the plugin was switched off: a post with an image or a
 * video published on a subsite in that time has to reach that subsite's image
 * and video sitemaps after the network reactivation, the sitemaps must keep
 * listing every eligible post while the subsite reads its content again, and a
 * subsite where nothing changed must stay correct.
 *
 * Fails on the code before this fix: after network deactivation, publishing a
 * media post on a subsite, and network reactivation, that post is in neither
 * sitemap of the subsite and stays out.
 *
 * Needs a subdirectory Multisite network (table prefix mwfix_) that is served
 * over HTTP at the given host, with the plugin network-activated. The post is
 * published by a second PHP process, because the plugin stays loaded in the
 * process that deactivated it. The test creates two subsites, network-
 * deactivates and reactivates the plugin, writes a small must-use plugin (pause
 * switch) and removes all of it again.
 */

if ( PHP_SAPI !== 'cli' || empty( $argv[1] ) || empty( $argv[2] ) ) {
	exit( "Usage: php media-index-multisite-wordpress.php /path/to/wordpress host[:port]\n" );
}
// WordPress finds the network and the site from the requested host and path.
$_SERVER['HTTP_HOST']   = $argv[2];
$_SERVER['REQUEST_URI'] = '/';

if ( isset( $argv[3] ) && 'publish' === $argv[3] ) {
	// Child process: the network without the (deactivated) plugin.
	require rtrim( $argv[1], '/\\' ) . '/wp-load.php';
	if ( 0 !== strpos( $wpdb->base_prefix, 'mwfix_' ) || ! is_multisite() || class_exists( 'MWSEO_Plugin', false ) ) {
		fwrite( STDERR, "Disposable mwfix_ network with the plugin network-deactivated required.\n" );
		exit( 2 );
	}
	$mwt_admins = get_super_admins();
	$mwt_user   = $mwt_admins ? get_user_by( 'login', $mwt_admins[0] ) : false;
	wp_set_current_user( $mwt_user ? $mwt_user->ID : 0 );
	switch_to_blog( (int) $argv[4] );
	$mwt_id = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_title'   => 'MWMS published while deactivated',
			'post_name'    => 'mwms-while-deactivated',
			'post_content' => '<p>Published while the plugin was network-deactivated.</p><p><img src="https://img.example.test/mwms-new.jpg" alt="x"></p><p>https://www.youtube.com/watch?v=mwmsNEW0001</p>',
		)
	);
	echo 'published:' . (int) $mwt_id . '|' . get_permalink( $mwt_id );
	exit( 0 );
}

require __DIR__ . '/regression-1093-helpers.php';
if ( ! is_multisite() || is_subdomain_install() ) {
	fwrite( STDERR, "A subdirectory Multisite network is required. This test did not run.\n" );
	exit( 2 );
}
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/ms.php';

const MWMS_PLUGIN = 'manny-wenas-seo/manny-wenas-seo.php';

$mu_file = WPMU_PLUGIN_DIR . '/mwt-media-pause-network.php';

/**
 * GET a path of a subsite without following redirects.
 *
 * @param string $url Absolute URL.
 * @return array { int code, string body }
 */
function mwms_get( $url ) {
	$response = wp_remote_get(
		$url,
		array(
			'timeout'     => 120,
			'redirection' => 0,
		)
	);
	if ( is_wp_error( $response ) ) {
		throw new RuntimeException( 'HTTP request failed for ' . $url . ': ' . $response->get_error_message() );
	}
	return array(
		'code' => (int) wp_remote_retrieve_response_code( $response ),
		'body' => (string) wp_remote_retrieve_body( $response ),
	);
}

/**
 * Entry URLs of every image and video sitemap the index of a site lists.
 *
 * @param int $blog_id Site ID.
 * @return array type => { pages: entries per listed sitemap, locs: all entry URLs }, plus 'code'.
 */
function mwms_listed( $blog_id ) {
	$index = mwms_get( get_home_url( $blog_id, '/mwseo-sitemap.xml' ) );
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
		$map = mwms_get( $loc );
		preg_match_all( '#<url><loc>([^<]*)</loc>#', $map['body'], $u );
		$out[ $t[1] ]['pages'][] = count( $u[1] );
		$out[ $t[1] ]['locs']    = array_merge( $out[ $t[1] ]['locs'], $u[1] );
	}
	return $out;
}

/**
 * Request a site's sitemap index until the plugin has read that site's content.
 *
 * "Read" means: the site's stored progress says done and was written for the network's
 * current activation (sites of a network notice a reactivation through a network-wide marker).
 *
 * @param int $blog_id Site ID.
 * @param int $max     Requests at most.
 * @return bool
 */
function mwms_settle( $blog_id, $max = 40 ) {
	global $wpdb;
	$options = $wpdb->get_blog_prefix( $blog_id ) . 'options';
	for ( $i = 0; $i < $max; $i++ ) {
		mwms_get( get_home_url( $blog_id, '/mwseo-sitemap.xml' ) );
		$raw   = $wpdb->get_var( "SELECT option_value FROM {$options} WHERE option_name = 'mwseo_media_index'" ); // phpcs:ignore
		$state = $raw ? maybe_unserialize( $raw ) : null;
		$epoch = $wpdb->get_var( "SELECT meta_value FROM {$wpdb->sitemeta} WHERE meta_key = 'mwseo_media_index_epoch'" );
		if ( is_array( $state ) && ! empty( $state['done'] ) && ( null === $epoch || ( isset( $state['epoch'] ) && (string) $state['epoch'] === (string) $epoch ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Is every URL listed exactly once and nothing else?
 *
 * @param string[] $locs Listed entry URLs.
 * @param string[] $want Expected URLs.
 * @return bool
 */
function mwms_exact( array $locs, array $want ) {
	sort( $locs );
	sort( $want );
	return $locs === $want;
}

/**
 * Is every URL listed exactly once (other URLs may be listed too)?
 *
 * @param string[] $locs Listed entry URLs.
 * @param string[] $want URLs that must be there.
 * @return bool
 */
function mwms_each_once( array $locs, array $want ) {
	foreach ( $want as $url ) {
		if ( 1 !== count( array_keys( $locs, $url, true ) ) ) {
			return false;
		}
	}
	return count( $locs ) === count( array_unique( $locs ) );
}

/**
 * Create a subsite with a few posts, some of them with an image and a video.
 *
 * @param string $slug  Path segment.
 * @param int    $media Number of media posts.
 * @return array { int id, string[] urls of the media posts }
 */
function mwms_site( $slug, $media ) {
	$network = get_network();
	$path    = $network->path . $slug . '/';
	$old     = domain_exists( $network->domain, $path, $network->id );
	if ( $old ) {
		wpmu_delete_blog( (int) $old, true );
	}
	$id = wpmu_create_blog( $network->domain, $path, 'MWMS ' . $slug, get_current_user_id(), array( 'public' => 1 ), $network->id );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$urls = array();
	switch_to_blog( $id );
	for ( $i = 1; $i <= $media + 3; $i++ ) {
		$has  = $i <= $media;
		$post = wp_insert_post(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'MWMS ' . $slug . ' ' . $i,
				'post_name'    => 'mwms-' . $slug . '-' . $i,
				'post_content' => '<p>Subsite post ' . $i . '.</p>' . ( $has ? '<p><img src="https://img.example.test/mwms-' . $slug . '-' . $i . '.jpg" alt="x"></p><p>https://www.youtube.com/watch?v=mwms' . sprintf( '%07d', $i ) . '</p>' : '' ),
			)
		);
		if ( $has ) {
			$urls[] = get_permalink( $post );
		}
	}
	restore_current_blog();
	return array( (int) $id, $urls );
}

$was_network_active = is_plugin_active_for_network( MWMS_PLUGIN );
$sites              = array();

try {
	if ( ! $was_network_active ) {
		throw new RuntimeException( 'The plugin must be network-activated before this test starts.' );
	}
	delete_site_option( 'mwt_media_pause' );
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents(
		$mu_file,
		"<?php\n// Written by tests/media-index-multisite-wordpress.php; safe to delete.\n"
		. "if ( get_site_option( 'mwt_media_pause' ) ) {\n\tadd_filter( 'mwseo_media_index_seconds', '__return_zero' );\n}\n"
	);

	// --- Two subsites, both read completely --------------------------------------
	list( $a, $a_urls ) = mwms_site( 'mwms-a', 3 );
	list( $b, $b_urls ) = mwms_site( 'mwms-b', 2 );
	$sites              = array( $a, $b );
	mwt_check( mwms_settle( $a ) && mwms_settle( $b ), 'before: the plugin has read the content of both subsites' );
	$state_a = mwms_listed( $a );
	$state_b = mwms_listed( $b );
	foreach ( array( 'image', 'video' ) as $type ) {
		mwt_check( mwms_exact( $state_a[ $type ]['locs'], $a_urls ) && array( 3 ) === $state_a[ $type ]['pages'], "before: subsite A lists exactly its three media posts in the $type sitemap", 'entries per listed sitemap: ' . implode( ',', $state_a[ $type ]['pages'] ) );
		mwt_check( mwms_exact( $state_b[ $type ]['locs'], $b_urls ) && array( 2 ) === $state_b[ $type ]['pages'], "before: subsite B lists exactly its two media posts in the $type sitemap", 'entries per listed sitemap: ' . implode( ',', $state_b[ $type ]['pages'] ) );
	}

	// --- Network deactivation, a media post on subsite A, network reactivation ------
	deactivate_plugins( MWMS_PLUGIN, false, true );
	wp_cache_flush();
	mwt_check( ! is_plugin_active_for_network( MWMS_PLUGIN ), 'the plugin is network-deactivated' );

	$command = escapeshellarg( PHP_BINARY ) . ( php_ini_loaded_file() ? ' -c ' . escapeshellarg( php_ini_loaded_file() ) : '' ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . escapeshellarg( $argv[1] ) . ' ' . escapeshellarg( $argv[2] ) . ' publish ' . (int) $a;
	$output  = (string) shell_exec( $command );
	$new_url = preg_match( '/published:(\d+)\|(\S+)/', $output, $m ) ? $m[2] : '';
	mwt_check( '' !== $new_url && (int) $m[1] > 0, 'a post with an image and a video is published on subsite A while the plugin is network-deactivated', 'child process said: ' . trim( $output ) );

	// Reactivate for the network, but give the plugin no time to read content yet.
	update_site_option( 'mwt_media_pause', 1 );
	$activated = activate_plugin( MWMS_PLUGIN, '', true );
	wp_cache_flush();
	mwt_check( ! is_wp_error( $activated ) && is_plugin_active_for_network( MWMS_PLUGIN ), 'the plugin is network-reactivated (from the main site)' );

	$state_a = mwms_listed( $a );
	$state_b = mwms_listed( $b );
	foreach ( array( 'image', 'video' ) as $type ) {
		$times = count( array_keys( $state_a[ $type ]['locs'], $new_url, true ) );
		mwt_check( 200 === $state_a['code'] && 1 === $times, "after reactivation, before subsite A has read its content again: the new post is in its $type sitemap, once", 'listed ' . $times . ' time(s); entries per listed sitemap: ' . implode( ',', $state_a[ $type ]['pages'] ) );
		mwt_check( mwms_each_once( $state_a[ $type ]['locs'], $a_urls ), "after reactivation, before subsite A has read its content again: its existing media posts stay in the $type sitemap, once each" );
		mwt_check( 200 === $state_b['code'] && mwms_each_once( $state_b[ $type ]['locs'], $b_urls ), "after reactivation, before subsite B has read its content again: its media posts stay in the $type sitemap, once each", 'entries per listed sitemap: ' . implode( ',', $state_b[ $type ]['pages'] ) );
	}

	// Let both subsites finish.
	delete_site_option( 'mwt_media_pause' );
	wp_cache_flush();
	mwt_check( mwms_settle( $a ), 'subsite A reads its content again and finishes' );
	mwt_check( mwms_settle( $b ), 'subsite B reads its content again and finishes' );
	$state_a = mwms_listed( $a );
	$state_b = mwms_listed( $b );
	foreach ( array( 'image', 'video' ) as $type ) {
		$times = count( array_keys( $state_a[ $type ]['locs'], $new_url, true ) );
		mwt_check( mwms_exact( $state_a[ $type ]['locs'], array_merge( $a_urls, array( $new_url ) ) ), "finished: subsite A lists its three existing media posts and the new one in the $type sitemap, each exactly once", 'expected 4 entries, got ' . count( $state_a[ $type ]['locs'] ) . '; new post listed ' . $times . ' time(s)' );
		mwt_check( array( 4 ) === $state_a[ $type ]['pages'], "finished: subsite A's index lists one $type sitemap and it has entries", 'entries per listed sitemap: ' . implode( ',', $state_a[ $type ]['pages'] ) );
		mwt_check( mwms_exact( $state_b[ $type ]['locs'], $b_urls ) && array( 2 ) === $state_b[ $type ]['pages'], "finished: subsite B, where nothing changed, still lists exactly its two media posts in one $type sitemap", 'entries per listed sitemap: ' . implode( ',', $state_b[ $type ]['pages'] ) );
	}
	$main = mwms_get( get_home_url( get_main_site_id(), '/mwseo-sitemap.xml' ) );
	mwt_check( 200 === $main['code'] && false !== strpos( $main['body'], '<sitemapindex' ), 'the main site still serves its sitemap index (ordinary case)' );
} catch ( Throwable $e ) {
	mwt_check( false, 'test ran to completion', get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getLine() );
} finally {
	while ( ms_is_switched() ) {
		restore_current_blog();
	}
	delete_site_option( 'mwt_media_pause' );
	if ( $was_network_active && ! is_plugin_active_for_network( MWMS_PLUGIN ) ) {
		activate_plugin( MWMS_PLUGIN, '', true );
	}
	foreach ( $sites as $id ) {
		wpmu_delete_blog( $id, true );
	}
	if ( file_exists( $mu_file ) ) {
		unlink( $mu_file );
	}
}

mwt_finish( 'media-index-multisite' );
