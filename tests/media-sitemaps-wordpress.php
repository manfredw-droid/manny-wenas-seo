<?php
/**
 * Image and video sitemaps list only posts that have media (1.0.9.3). Real HTTP
 * requests against a disposable site.
 *
 *     php tests/media-sitemaps-wordpress.php /path/to/wordpress
 *
 * The sitemap index must list an image or video sitemap only when it has entries,
 * every listed sitemap must contain entries, and no eligible post may be left out,
 * wherever it sits among the other posts. Covered: no media at all, a few media
 * posts far apart among thousands of posts, more than 1,000 eligible posts, and
 * media that is added or removed afterwards (content, featured image, deleted
 * attachment, trashed post, noindex).
 *
 * Existing content is inserted straight into the database, the way it is found
 * on a site that updates the plugin: nothing has recorded which posts have media
 * yet. Until the plugin has caught up, the sitemaps must keep listing every
 * eligible post (the former behaviour); afterwards they must be exact.
 *
 * Fails on the code before the media index for: image and video sitemaps listed
 * without entries (no media, or media in few of the 1,000-post slices).
 *
 * The test writes a small must-use plugin (query counter, pause switch) into the
 * disposable site and removes it again, together with all fixtures.
 */

require __DIR__ . '/regression-1093-helpers.php';

const MWT_MEDIA_MAX_QUERIES = 60;

$mu_file = WPMU_PLUGIN_DIR . '/mwt-media-probe.php';

/**
 * Remove every fixture of this test (also leftovers of an aborted run).
 */
function mwt_media_cleanup() {
	global $wpdb;
	$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'mwmd-%'" );
	foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
		$in = implode( ',', array_map( 'intval', $chunk ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore
	}
	wp_cache_flush();
}

/**
 * Forget which posts have media, as on a site that has just updated the plugin.
 */
function mwt_media_forget() {
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( '_mwseo_has_image', '_mwseo_has_video' )" );
	delete_option( 'mwseo_media_index' );
	delete_transient( 'mwseo_media_index_lock' );
	wp_cache_flush();
}

/**
 * Insert a published post row directly, without any plugin hook.
 *
 * @param string $slug    Slug (prefixed).
 * @param string $content Content.
 * @param array  $extra   Column overrides.
 * @return int
 */
function mwt_media_row( $slug, $content, array $extra = array() ) {
	global $wpdb;
	$wpdb->insert(
		$wpdb->posts,
		array_merge(
			array(
				'post_author'           => get_current_user_id(),
				'post_date'             => '2020-02-01 00:00:00',
				'post_date_gmt'         => '2020-02-01 00:00:00',
				'post_modified'         => '2020-02-01 00:00:00',
				'post_modified_gmt'     => '2020-02-01 00:00:00',
				'post_content'          => $content,
				'post_title'            => 'MWMD ' . $slug,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'post_name'             => 'mwmd-' . $slug,
				'post_type'             => 'post',
				'to_ping'               => '',
				'pinged'                => '',
				'post_content_filtered' => '',
			),
			$extra
		)
	);
	return (int) $wpdb->insert_id;
}

/**
 * GET a sitemap URL. Returns its entry URLs, the appended query count and the time taken.
 *
 * @param string $path Path of the sitemap.
 * @return array
 */
function mwt_media_get( $path ) {
	$start = microtime( true );
	$res   = mwt_fetch( $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'mwt_queries=1' );
	$time  = microtime( true ) - $start;
	preg_match( '/<!-- mwt_queries=(\d+) -->/', $res['body'], $q );
	$xml = preg_replace( '/<!-- mwt_queries=\d+ -->\s*$/', '', $res['body'] );
	preg_match_all( '#<url><loc>([^<]*)</loc>#', $xml, $m );
	libxml_use_internal_errors( true );
	return array(
		'code'    => $res['code'],
		'valid'   => false !== simplexml_load_string( $xml ),
		'locs'    => $m[1],
		'body'    => $xml,
		'queries' => isset( $q[1] ) ? (int) $q[1] : -1,
		'time'    => $time,
	);
}

/**
 * Read the sitemap index and every image and video sitemap it lists.
 *
 * @return array type => { pages: entry counts per listed sitemap, locs: all entry URLs, body, queries, times }, plus 'index'.
 */
function mwt_media_state() {
	$index = mwt_media_get( '/mwseo-sitemap.xml' );
	preg_match_all( '#<sitemap><loc>([^<]*)</loc></sitemap>#', $index['body'], $m );
	$out = array(
		'index' => $index,
		'all'   => $m[1],
	);
	foreach ( array( 'image', 'video', 'post' ) as $type ) {
		$out[ $type ] = array(
			'pages'   => array(),
			'locs'    => array(),
			'body'    => '',
			'queries' => array(),
			'times'   => array(),
			'ok'      => true,
		);
		foreach ( $m[1] as $loc ) {
			if ( ! preg_match( '#/mwseo-' . $type . '-sitemap[0-9]*\.xml$#', $loc ) || 'post' === $type ) {
				continue;
			}
			$map                        = mwt_media_get( (string) substr( $loc, strlen( untrailingslashit( home_url() ) ) ) );
			$out[ $type ]['pages'][]    = count( $map['locs'] );
			$out[ $type ]['locs']       = array_merge( $out[ $type ]['locs'], $map['locs'] );
			$out[ $type ]['body']      .= $map['body'];
			$out[ $type ]['queries'][]  = $map['queries'];
			$out[ $type ]['times'][]    = $map['time'];
			$out[ $type ]['ok']         = $out[ $type ]['ok'] && 200 === $map['code'] && $map['valid'];
		}
	}
	return $out;
}

/**
 * Has the plugin finished recording which existing posts have media?
 *
 * @return bool
 */
function mwt_media_caught_up() {
	global $wpdb;
	$raw   = $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'mwseo_media_index'" );
	$state = $raw ? maybe_unserialize( $raw ) : null;
	return is_array( $state ) && ! empty( $state['done'] );
}

/**
 * Request the sitemap index until the plugin has caught up with existing content.
 *
 * @param int $max Requests at most.
 * @return int Requests made, or -1 when it never caught up.
 */
function mwt_media_wait( $max = 40 ) {
	for ( $i = 1; $i <= $max; $i++ ) {
		mwt_fetch( '/mwseo-sitemap.xml' );
		if ( mwt_media_caught_up() ) {
			return $i;
		}
	}
	return -1;
}

/**
 * Checks shared by every exact state: only useful sitemaps are listed and nothing is missing.
 *
 * @param string   $label Phase.
 * @param array    $state Result of mwt_media_state().
 * @param string   $type  image|video.
 * @param string[] $want  Expected entry URLs.
 */
function mwt_media_exact( $label, array $state, $type, array $want ) {
	$got      = $state[ $type ];
	$expected = (int) ceil( count( $want ) / 1000 );
	mwt_check( count( $got['pages'] ) === $expected, "$label: the index lists exactly the $type sitemaps that are needed", 'listed ' . count( $got['pages'] ) . ' (entries per sitemap: ' . implode( ',', $got['pages'] ) . '), needed ' . $expected );
	mwt_check( ! in_array( 0, $got['pages'], true ) && $got['ok'], "$label: every listed $type sitemap contains entries", 'entries per listed sitemap: ' . implode( ',', $got['pages'] ) );
	$missing = array_diff( $want, $got['locs'] );
	$extra   = array_diff( $got['locs'], $want );
	mwt_check( ! $missing && ! $extra && count( $got['locs'] ) === count( array_unique( $got['locs'] ) ), "$label: the $type sitemaps hold every eligible post exactly once", count( $missing ) . ' missing, ' . count( $extra ) . ' unexpected, ' . ( count( $got['locs'] ) - count( array_unique( $got['locs'] ) ) ) . ' duplicates' );
}

$img       = static function ( $n ) {
	return '<p><img src="https://img.example.test/mwmd-' . $n . '.jpg" alt="x"></p>';
};
$vid       = static function ( $n ) {
	return '<p>https://www.youtube.com/watch?v=md' . sprintf( '%09d', $n ) . '</p>';
};
$api_posts = array();
$paused    = false;

try {
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		throw new RuntimeException( 'The disposable site must use the /%postname%/ permalink structure.' );
	}
	mwt_media_cleanup();
	delete_option( 'mwt_media_pause' );
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents(
		$mu_file,
		"<?php\n// Written by tests/media-sitemaps-wordpress.php; safe to delete.\n"
		. "if ( isset( \$_GET['mwt_queries'] ) ) {\n\tregister_shutdown_function( static function () {\n\t\techo '<!-- mwt_queries=' . (int) \$GLOBALS['wpdb']->num_queries . ' -->';\n\t} );\n}\n"
		// Pause switch: the plugin may spend no time ("1") or hardly any ("slow") catching up with existing content.
		. "if ( 'slow' === get_option( 'mwt_media_pause' ) ) {\n\tadd_filter( 'mwseo_media_index_seconds', static function () {\n\t\treturn 0.001;\n\t} );\n} elseif ( get_option( 'mwt_media_pause' ) ) {\n\tadd_filter( 'mwseo_media_index_seconds', '__return_zero' );\n}\n"
	);

	// ============================================================================
	// 1. No media at all.
	// ============================================================================
	for ( $i = 1; $i <= 3; $i++ ) {
		$api_posts[] = wp_insert_post(
			array(
				'post_status'  => 'publish',
				'post_title'   => 'MWMD plain ' . $i,
				'post_name'    => 'mwmd-plain-' . $i,
				'post_content' => '<p>No media here.</p>',
			)
		);
	}
	mwt_media_forget();
	$requests = mwt_media_wait();
	mwt_check( $requests > 0, 'no media: the plugin catches up with existing content', 'not caught up after 40 index requests' );
	$state = mwt_media_state();
	mwt_check( 200 === $state['index']['code'] && $state['index']['valid'], 'no media: the sitemap index is served as well-formed XML' );
	mwt_media_exact( 'no media', $state, 'image', array() );
	mwt_media_exact( 'no media', $state, 'video', array() );
	mwt_check( (bool) preg_grep( '#/mwseo-post-sitemap\.xml$#', $state['all'] ) && (bool) preg_grep( '#/mwseo-page-sitemap\.xml$#', $state['all'] ), 'no media: post and page sitemaps are still listed (ordinary case)' );

	// ============================================================================
	// 2. A few media posts far apart among 2,300 existing posts.
	// ============================================================================
	$want = array(
		'image' => array(),
		'video' => array(),
	);
	$rows = array();
	$wpdb->query( 'START TRANSACTION' );
	for ( $i = 1; $i <= 2300; $i++ ) {
		$has_image = in_array( $i, array( 5, 1150, 2290 ), true );
		$has_video = in_array( $i, array( 40, 2200 ), true );
		$rows[ $i ] = mwt_media_row( 'old-' . $i, '<p>Existing post ' . $i . '.</p>' . ( $has_image ? $img( $i ) : '' ) . ( $has_video ? $vid( $i ) : '' ) );
		if ( $has_image ) {
			$want['image'][] = $rows[ $i ];
		}
		if ( $has_video ) {
			$want['video'][] = $rows[ $i ];
		}
	}
	$wpdb->query( 'COMMIT' );
	wp_cache_flush();
	$urls = static function ( array $ids ) {
		$out = array();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			foreach ( $chunk as $id ) {
				$out[] = get_permalink( $id );
			}
		}
		return $out;
	};

	// 2a. Before the plugin has caught up, nothing may be left out.
	update_option( 'mwt_media_pause', 1 );
	$paused = true;
	mwt_media_forget();
	$state = mwt_media_state();
	mwt_check( ! mwt_media_caught_up(), 'sparse media, not caught up yet: the pause switch of this test works' );
	foreach ( array( 'image', 'video' ) as $type ) {
		$missing = array_diff( $urls( $want[ $type ] ), $state[ $type ]['locs'] );
		mwt_check( ! $missing && count( $state[ $type ]['locs'] ) === count( $want[ $type ] ), "sparse media, not caught up yet: no eligible $type post is left out", count( $missing ) . ' missing; listed entries: ' . count( $state[ $type ]['locs'] ) );
	}
	// 2b. A request that cannot finish the work leaves a scheduled event, and that event finishes it
	// without any further sitemap request.
	update_option( 'mwt_media_pause', 'slow' );
	mwt_fetch( '/mwseo-sitemap.xml' );
	delete_option( 'mwt_media_pause' );
	$paused = false;
	wp_cache_flush();
	$event = wp_next_scheduled( 'mwseo_media_index_step' );
	mwt_check( ! mwt_media_caught_up() && false !== $event, 'sparse media: an unfinished catch-up leaves one scheduled event', 'caught up: ' . var_export( mwt_media_caught_up(), true ) . ', scheduled: ' . var_export( $event, true ) );
	do_action( 'mwseo_media_index_step' );
	mwt_check( mwt_media_caught_up(), 'sparse media: the scheduled event finishes catching up with 2,300 existing posts' );
	wp_clear_scheduled_hook( 'mwseo_media_index_step' );

	// 2c. Caught up: exact.
	$requests = mwt_media_wait();
	mwt_check( $requests > 0, 'sparse media: the plugin has caught up', 'not caught up after 40 index requests' );
	$state = mwt_media_state();
	mwt_media_exact( 'sparse media', $state, 'image', $urls( $want['image'] ) );
	mwt_media_exact( 'sparse media', $state, 'video', $urls( $want['video'] ) );

	// ============================================================================
	// 3. More than 1,000 eligible posts.
	// ============================================================================
	$wpdb->query( 'START TRANSACTION' );
	for ( $i = 1; $i <= 1010; $i++ ) {
		$id              = mwt_media_row( 'many-' . $i, '<p>Media post ' . $i . '.</p>' . $img( 'm' . $i ) . ( $i <= 1004 ? $vid( 100000 + $i ) : '' ) );
		$want['image'][] = $id;
		if ( $i <= 1004 ) {
			$want['video'][] = $id;
		}
	}
	$wpdb->query( 'COMMIT' );
	wp_cache_flush();
	mwt_media_forget();
	$requests = mwt_media_wait();
	mwt_check( $requests > 0, 'many media posts: the plugin catches up', 'not caught up after 40 index requests' );
	echo 'INFO many media posts: caught up with 3,300 existing posts after ' . $requests . " sitemap index request(s)\n";
	$state = mwt_media_state();
	mwt_media_exact( 'many media posts (1,013 image, 1,006 video)', $state, 'image', $urls( $want['image'] ) );
	mwt_media_exact( 'many media posts (1,013 image, 1,006 video)', $state, 'video', $urls( $want['video'] ) );
	mwt_check( array( 1000, 13 ) === $state['image']['pages'] && array( 1000, 6 ) === $state['video']['pages'], 'many media posts: full sitemaps hold 1,000 entries and the rest follows', 'image ' . implode( ',', $state['image']['pages'] ) . '; video ' . implode( ',', $state['video']['pages'] ) );

	// Cost in the settled state.
	$all_queries = array_merge( array( $state['index']['queries'] ), $state['image']['queries'], $state['video']['queries'] );
	mwt_check( min( $all_queries ) >= 0 && max( $all_queries ) <= MWT_MEDIA_MAX_QUERIES, 'cost: the index and every image and video sitemap need at most ' . MWT_MEDIA_MAX_QUERIES . ' database queries', 'index ' . $state['index']['queries'] . '; image ' . implode( ',', $state['image']['queries'] ) . '; video ' . implode( ',', $state['video']['queries'] ) );
	printf(
		"INFO cost with 3,313 posts: index %d queries in %.2fs; image sitemaps %s queries in %s s; video sitemaps %s queries in %s s\n",
		$state['index']['queries'],
		$state['index']['time'],
		implode( ',', $state['image']['queries'] ),
		implode( ',', array_map( static fn( $t ) => number_format( $t, 2 ), $state['image']['times'] ) ),
		implode( ',', $state['video']['queries'] ),
		implode( ',', array_map( static fn( $t ) => number_format( $t, 2 ), $state['video']['times'] ) )
	);

	// ============================================================================
	// 4. Media added and removed afterwards, the way WordPress does it.
	// ============================================================================
	$listed = static function ( $type, $id ) {
		$state = mwt_media_state();
		return array( in_array( get_permalink( $id ), $state[ $type ]['locs'], true ), count( $state[ $type ]['locs'] ), $state );
	};
	$images = count( $want['image'] );
	$videos = count( $want['video'] );

	// Content gains and loses an image.
	$a = $rows[100];
	wp_update_post(
		array(
			'ID'           => $a,
			'post_content' => '<p>Now with an image.</p>' . $img( 'added' ),
		)
	);
	list( $in, $count ) = $listed( 'image', $a );
	mwt_check( $in && $images + 1 === $count, 'change: a post that gains an image in its content is listed', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );
	wp_update_post(
		array(
			'ID'           => $a,
			'post_content' => '<p>Image removed again.</p>',
		)
	);
	list( $in, $count ) = $listed( 'image', $a );
	mwt_check( ! $in && $images === $count, 'change: a post that loses its only image is no longer listed', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );

	// Featured image set, removed, and its attachment deleted.
	$attachment = wp_insert_attachment(
		array(
			'post_title'     => 'MWMD attachment',
			'post_name'      => 'mwmd-attachment',
			'post_mime_type' => 'image/jpeg',
			'post_status'    => 'inherit',
		),
		'2020/02/mwmd-featured.jpg'
	);
	$api_posts[] = $attachment;
	$b           = $rows[101];
	set_post_thumbnail( $b, $attachment );
	list( $in, $count, $state ) = $listed( 'image', $b );
	mwt_check( $in && $images + 1 === $count && false !== strpos( $state['image']['body'], 'mwmd-featured.jpg</image:loc>' ), 'change: a post that gets a featured image is listed with the image URL', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );
	delete_post_thumbnail( $b );
	list( $in, $count ) = $listed( 'image', $b );
	mwt_check( ! $in && $images === $count, 'change: a post whose featured image is removed is no longer listed', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );
	set_post_thumbnail( $b, $attachment );
	wp_delete_attachment( $attachment, true );
	list( $in, $count, $state ) = $listed( 'image', $b );
	mwt_check( ! $in && $images === $count && ! in_array( 0, $state['image']['pages'], true ), 'change: a post whose featured image file is deleted from the library is no longer listed', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );

	// Video added and removed.
	$c = $rows[102];
	wp_update_post(
		array(
			'ID'           => $c,
			'post_content' => '<p>Now with a video.</p>' . $vid( 999 ),
		)
	);
	list( $in, $count ) = $listed( 'video', $c );
	mwt_check( $in && $videos + 1 === $count, 'change: a post that gains a video is listed', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );
	wp_update_post(
		array(
			'ID'           => $c,
			'post_content' => '<p>Video removed again.</p>',
		)
	);
	list( $in, $count ) = $listed( 'video', $c );
	mwt_check( ! $in && $videos === $count, 'change: a post that loses its only video is no longer listed', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );

	// A new post with media, then trashed.
	$d           = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_title'   => 'MWMD new media',
			'post_name'    => 'mwmd-new-media',
			'post_content' => '<p>Fresh.</p>' . $img( 'fresh' ) . $vid( 998 ),
		)
	);
	$api_posts[] = $d;
	$link        = get_permalink( $d );
	$state       = mwt_media_state();
	mwt_check( in_array( $link, $state['image']['locs'], true ) && in_array( $link, $state['video']['locs'], true ), 'change: a newly published post with media is listed in both sitemaps' );
	wp_trash_post( $d );
	$state = mwt_media_state();
	mwt_check( ! in_array( $link, $state['image']['locs'], true ) && ! in_array( $link, $state['video']['locs'], true ) && $images === count( $state['image']['locs'] ), 'change: a trashed post is no longer listed' );

	// Which posts qualify is unchanged: noindex and password-protected posts stay out.
	$e = $want['image'][0];
	update_post_meta( $e, '_mwseo_noindex', '1' );
	list( $in, $count ) = $listed( 'image', $e );
	mwt_check( ! $in && $images - 1 === $count, 'unchanged rule: a noindex post with an image stays out', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );
	delete_post_meta( $e, '_mwseo_noindex' );
	$wpdb->update( $wpdb->posts, array( 'post_password' => 'secret' ), array( 'ID' => $e ) );
	clean_post_cache( $e );
	list( $in, $count, $state ) = $listed( 'image', $e );
	mwt_check( ! $in && $images - 1 === $count, 'unchanged rule: a password-protected post with an image stays out', 'listed: ' . var_export( $in, true ) . ', entries ' . $count );
	$wpdb->update( $wpdb->posts, array( 'post_password' => '' ), array( 'ID' => $e ) );
	clean_post_cache( $e );
	mwt_check( false !== strpos( $state['image']['body'], '<image:loc>https://img.example.test/mwmd-1150.jpg</image:loc>' ) && false !== strpos( $state['video']['body'], '<video:player_loc>https://www.youtube.com/embed/md000002200</video:player_loc>' ), 'unchanged output: image and video entries keep their image and player URLs' );

	// ============================================================================
	// 5. All media gone again: the sitemaps disappear from the index.
	// ============================================================================
	foreach ( $api_posts as $id ) {
		wp_delete_post( $id, true );
	}
	$api_posts = array();
	mwt_media_cleanup();
	$state = mwt_media_state();
	mwt_media_exact( 'all media removed', $state, 'image', array() );
	mwt_media_exact( 'all media removed', $state, 'video', array() );
} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	mwt_check( false, 'test ran to completion', get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getLine() );
} finally {
	delete_option( 'mwt_media_pause' );
	wp_clear_scheduled_hook( 'mwseo_media_index_step' );
	foreach ( $api_posts as $id ) {
		wp_delete_post( $id, true );
	}
	mwt_media_cleanup();
	if ( file_exists( $mu_file ) ) {
		unlink( $mu_file );
	}
}

mwt_finish( 'media-sitemaps' );
