<?php
/**
 * Sitemap pagination and query-count regression (1.0.9.3). Real HTTP requests
 * against a disposable site.
 *
 *     php tests/sitemap-pagination-wordpress.php /path/to/wordpress
 *
 * Builds more than 1,000 qualifying entries for every sitemap type (posts with
 * images and videos, tags, categories, authors), then reads the sitemap index
 * and every sitemap it lists. For each type the URLs of all listed sitemaps
 * together must be exactly the expected set: nothing missing, nothing twice.
 * Which content qualifies is pinned as it was in 1.0.9.2 (published, no
 * password, not noindex; non-empty terms plus empty parents of non-empty
 * children; users with published posts).
 *
 * Fails on 1.0.9.2 for: image, video, tag, category and author sitemaps beyond
 * 1,000 entries (the index listed one sitemap per type and the rest was dropped)
 * and for the number of database queries per sitemap (about one or two per URL).
 *
 * The test writes a small must-use plugin (query counter) into the disposable
 * site and removes it again, together with all fixtures.
 */

require __DIR__ . '/regression-1093-helpers.php';

const MWT_N          = 1005; // Fixture posts, authors, tags and categories.
const MWT_PER_PAGE   = 1000; // Entries per sitemap, as documented by the plugin.
const MWT_MAX_QUERIES = 60;  // Per sitemap request; 1.0.9.2 needed 1,000 to 2,000.

$mu_file = WPMU_PLUGIN_DIR . '/mwt-query-counter.php';

/**
 * Remove every fixture this test creates (also leftovers of an aborted run).
 */
function mwt_sitemap_cleanup() {
	global $wpdb;
	$post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_name LIKE 'mwsm-%'" );
	if ( $post_ids ) {
		$in = implode( ',', array_map( 'intval', $post_ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore
	}
	$term_ids = $wpdb->get_col( "SELECT term_id FROM {$wpdb->terms} WHERE slug LIKE 'mwsm-%'" );
	if ( $term_ids ) {
		$in = implode( ',', array_map( 'intval', $term_ids ) );
		$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ( SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id IN ($in) )" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->term_taxonomy} WHERE term_id IN ($in)" ); // phpcs:ignore
		$wpdb->query( "DELETE FROM {$wpdb->terms} WHERE term_id IN ($in)" ); // phpcs:ignore
	}
	$wpdb->query( "DELETE FROM {$wpdb->users} WHERE user_login LIKE 'mwsm\\_u%'" );
	wp_cache_flush();
	clean_taxonomy_cache( 'category' );
	clean_taxonomy_cache( 'post_tag' );
}

/**
 * Insert a term with its taxonomy row directly (fast).
 *
 * @param string $taxonomy Taxonomy.
 * @param string $slug     Slug.
 * @param int    $count    Stored object count.
 * @param int    $parent   Parent term ID.
 * @return int[] term_id, term_taxonomy_id
 */
function mwt_insert_term( $taxonomy, $slug, $count, $parent = 0 ) {
	global $wpdb;
	$wpdb->insert(
		$wpdb->terms,
		array(
			'name' => 'MWSM ' . $slug,
			'slug' => $slug,
		)
	);
	$term_id = (int) $wpdb->insert_id;
	$wpdb->insert(
		$wpdb->term_taxonomy,
		array(
			'term_id'     => $term_id,
			'taxonomy'    => $taxonomy,
			'description' => '',
			'parent'      => $parent,
			'count'       => $count,
		)
	);
	return array( $term_id, (int) $wpdb->insert_id );
}

/**
 * Insert a post row directly (fast). All fixtures share one modified time on
 * purpose: pagination must stay exact when the sort key is tied.
 *
 * @param string $slug    Slug.
 * @param string $content Content.
 * @param int    $author  Author ID.
 * @param array  $extra   Column overrides.
 * @return int
 */
function mwt_insert_post_row( $slug, $content, $author, array $extra = array() ) {
	global $wpdb;
	$wpdb->insert(
		$wpdb->posts,
		array_merge(
			array(
				'post_author'           => $author,
				'post_date'             => '2020-01-01 00:00:00',
				'post_date_gmt'         => '2020-01-01 00:00:00',
				'post_modified'         => '2020-01-01 00:00:00',
				'post_modified_gmt'     => '2020-01-01 00:00:00',
				'post_content'          => $content,
				'post_title'            => 'MWSM ' . $slug,
				'post_excerpt'          => '',
				'post_status'           => 'publish',
				'post_name'             => $slug,
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
 * Sitemap entry URLs (<url><loc>) of one sitemap, plus the appended query count.
 *
 * @param string $url Absolute sitemap URL.
 * @return array
 */
function mwt_read_sitemap( $url ) {
	$path = substr( $url, strlen( untrailingslashit( home_url() ) ) );
	$res  = mwt_fetch( $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'mwt_queries=1' );
	$xml  = preg_replace( '/<!-- mwt_queries=\d+ -->\s*$/', '', $res['body'] );
	preg_match_all( '#<url><loc>([^<]*)</loc>#', $xml, $m );
	preg_match( '/<!-- mwt_queries=(\d+) -->/', $res['body'], $q );
	libxml_use_internal_errors( true );
	return array(
		'code'    => $res['code'],
		'type'    => $res['type'],
		'valid'   => false !== simplexml_load_string( $xml ),
		'locs'    => array_map( static fn( $loc ) => html_entity_decode( $loc, ENT_QUOTES | ENT_XML1, 'UTF-8' ), $m[1] ),
		'queries' => isset( $q[1] ) ? (int) $q[1] : -1,
		'xml'     => $xml,
	);
}

try {
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		throw new RuntimeException( 'The disposable site must use the /%postname%/ permalink structure.' );
	}
	mwt_sitemap_cleanup();
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents(
		$mu_file,
		"<?php\n// Written by tests/sitemap-pagination-wordpress.php; safe to delete.\nif ( isset( \$_GET['mwt_queries'] ) ) {\n\tregister_shutdown_function( static function () {\n\t\techo '<!-- mwt_queries=' . (int) \$GLOBALS['wpdb']->num_queries . ' -->';\n\t} );\n}\n"
		// Another plugin that filters loaded posts (the_posts). 1.0.9.2 selected sitemap posts by ID, so such a filter never applied.
		. "if ( isset( \$_GET['mwt_the_posts'] ) ) {\n\tadd_filter( 'the_posts', static function ( \$posts ) {\n\t\treturn array_values( array_filter( (array) \$posts, static function ( \$p ) {\n\t\t\treturn ! is_object( \$p ) || 'mwsm-post-0002' !== \$p->post_name;\n\t\t} ) );\n\t} );\n}\n"
	);

	// --- Fixtures ---------------------------------------------------------------
	$wpdb->query( 'START TRANSACTION' );
	$expect = array(
		'post'     => array(),
		'image'    => array(),
		'video'    => array(),
		'tag'      => array(),
		'category' => array(),
		'author'   => array(),
	);
	for ( $i = 1; $i <= MWT_N; $i++ ) {
		$n = sprintf( '%04d', $i );
		$wpdb->insert(
			$wpdb->users,
			array(
				'user_login'      => 'mwsm_u' . $n,
				'user_pass'       => '!',
				'user_nicename'   => 'mwsm-u' . $n,
				'user_email'      => 'mwsm' . $n . '@example.test',
				'user_registered' => '2020-01-01 00:00:00',
				'display_name'    => 'MWSM User ' . $n,
			)
		);
		$user_id   = (int) $wpdb->insert_id;
		$has_image = 0 !== $i % 5;
		$has_video = 0 === $i % 3;
		$content   = '<p>Sitemap fixture ' . $n . '.</p>'
			. ( $has_image ? '<p><img src="https://img.example.test/mwsm-' . $n . '.jpg" alt="x"></p>' : '' )
			. ( $has_video ? '<p>https://www.youtube.com/watch?v=mwsm' . $n . 'abc</p>' : '' );
		$post_id   = mwt_insert_post_row( 'mwsm-post-' . $n, $content, $user_id );
		list( $tag_id, $tag_tt ) = mwt_insert_term( 'post_tag', 'mwsm-tag-' . $n, 1 );
		list( $cat_id, $cat_tt ) = mwt_insert_term( 'category', 'mwsm-cat-' . $n, 1 );
		foreach ( array( $tag_tt, $cat_tt ) as $tt ) {
			$wpdb->insert(
				$wpdb->term_relationships,
				array(
					'object_id'        => $post_id,
					'term_taxonomy_id' => $tt,
				)
			);
		}
		$expect['post'][ $post_id ] = true;
		if ( $has_image ) {
			$expect['image'][ $post_id ] = true;
		}
		if ( $has_video ) {
			$expect['video'][ $post_id ] = true;
		}
		$expect['tag'][ $tag_id ]      = true;
		$expect['category'][ $cat_id ] = true;
		$expect['author'][ $user_id ]  = true;
	}
	// Terms that pin which terms qualify: empty ones are left out, an empty parent of a non-empty child stays in.
	mwt_insert_term( 'post_tag', 'mwsm-tag-empty', 0 );
	mwt_insert_term( 'category', 'mwsm-cat-empty', 0 );
	list( $parent_id )           = mwt_insert_term( 'category', 'mwsm-cat-parent', 0 );
	list( $child_id, $child_tt ) = mwt_insert_term( 'category', 'mwsm-cat-child', 1, $parent_id );
	$first_post                  = (int) array_key_first( $expect['post'] );
	$wpdb->insert(
		$wpdb->term_relationships,
		array(
			'object_id'        => $first_post,
			'term_taxonomy_id' => $child_tt,
		)
	);
	$expect['category'][ $parent_id ] = true;
	$expect['category'][ $child_id ]  = true;

	// Posts that must stay out of every post-based sitemap.
	$admin   = get_current_user_id();
	$media   = '<p><img src="https://img.example.test/mwsm-out.jpg" alt="x"></p><p>https://www.youtube.com/watch?v=mwsmOUT0abc</p>';
	$noindex = mwt_insert_post_row( 'mwsm-noindex', $media, $admin );
	add_post_meta( $noindex, '_mwseo_noindex', '1' );
	$locked = mwt_insert_post_row( 'mwsm-password', $media, $admin, array( 'post_password' => 'secret' ) );
	$draft  = mwt_insert_post_row( 'mwsm-draft', $media, $admin, array( 'post_status' => 'draft' ) );
	// An explicit "index" flag (stored false) and a page stay in.
	$allowed = mwt_insert_post_row( 'mwsm-index-flag', $media, $admin );
	add_post_meta( $allowed, '_mwseo_noindex', '' );
	$expect['post'][ $allowed ]  = true;
	$expect['image'][ $allowed ] = true;
	$expect['video'][ $allowed ] = true;
	$page                        = mwt_insert_post_row( 'mwsm-page', $media, $admin, array( 'post_type' => 'page' ) );
	$expect['image'][ $page ]    = true;
	$expect['video'][ $page ]    = true;
	// Featured image only (no inline image): must be in the image sitemap with the attachment URL.
	$attachment = mwt_insert_post_row(
		'mwsm-attachment',
		'',
		$admin,
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/jpeg',
			'guid'           => 'https://img.example.test/mwsm-featured.jpg',
		)
	);
	add_post_meta( $attachment, '_wp_attached_file', '2020/01/mwsm-featured.jpg' );
	$featured = mwt_insert_post_row( 'mwsm-featured', '<p>Featured image only.</p>', $admin );
	add_post_meta( $featured, '_thumbnail_id', $attachment );
	$expect['post'][ $featured ]  = true;
	$expect['image'][ $featured ] = true;
	$wpdb->query( 'COMMIT' );
	wp_cache_flush();
	clean_taxonomy_cache( 'category' );
	clean_taxonomy_cache( 'post_tag' );

	// Expected URLs, computed independently of the plugin's sitemap code.
	$other_posts = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_password = '' AND post_name NOT LIKE 'mwsm-%' AND ID NOT IN ( SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mwseo_noindex' AND meta_value = '1' )" );
	foreach ( $other_posts as $id ) {
		$expect['post'][ (int) $id ] = true;
	}
	foreach ( $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'category' AND count > 0" ) as $id ) {
		$expect['category'][ (int) $id ] = true;
	}
	foreach ( $wpdb->get_col( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = 'post_tag' AND count > 0" ) as $id ) {
		$expect['tag'][ (int) $id ] = true;
	}
	foreach ( $wpdb->get_col( "SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( 'post', 'page' )" ) as $id ) {
		$expect['author'][ (int) $id ] = true;
	}
	$expected_urls = array();
	foreach ( array( 'post', 'image', 'video' ) as $type ) {
		foreach ( array_chunk( array_keys( $expect[ $type ] ), 500 ) as $chunk ) {
			_prime_post_caches( $chunk, false, false );
			foreach ( $chunk as $id ) {
				$expected_urls[ $type ][] = get_permalink( $id );
			}
		}
	}
	foreach ( array_keys( $expect['tag'] ) as $id ) {
		$expected_urls['tag'][] = get_term_link( (int) $id, 'post_tag' );
	}
	foreach ( array_keys( $expect['category'] ) as $id ) {
		$expected_urls['category'][] = get_term_link( (int) $id, 'category' );
	}
	foreach ( array_keys( $expect['author'] ) as $id ) {
		$expected_urls['author'][] = get_author_posts_url( (int) $id );
	}
	$excluded = array( get_permalink( $noindex ), get_permalink( $locked ), get_permalink( $draft ) );

	// The fixtures were written straight into the database; let the plugin read them for its
	// media flags first, so the checks and the query counts below describe the settled state.
	delete_option( 'mwseo_media_index' );
	$settled = mwt_settle_media_index();
	echo 'INFO media flags: ' . ( $settled ? 'settled' : 'not used by this version' ) . PHP_EOL;

	// --- Index ------------------------------------------------------------------
	$index = mwt_fetch( '/mwseo-sitemap.xml?mwt_queries=1' );
	mwt_check( 200 === $index['code'] && false !== strpos( $index['type'], 'xml' ), 'sitemap index is served as XML', 'HTTP ' . $index['code'] . ' ' . $index['type'] );
	preg_match_all( '#<sitemap><loc>([^<]*)</loc></sitemap>#', $index['body'], $m );
	$listed = array();
	foreach ( $m[1] as $loc ) {
		if ( preg_match( '#/mwseo-([a-z0-9_]+)-sitemap([0-9]*)\.xml$#', $loc, $p ) ) {
			$listed[ $p[1] ][] = $loc;
		}
	}
	mwt_check( count( $m[1] ) === count( array_unique( $m[1] ) ), 'sitemap index lists no sitemap twice' );
	preg_match( '/<!-- mwt_queries=(\d+) -->/', $index['body'], $q );
	mwt_check( isset( $q[1] ) && (int) $q[1] <= MWT_MAX_QUERIES, 'sitemap index needs at most ' . MWT_MAX_QUERIES . ' database queries', 'queries: ' . ( isset( $q[1] ) ? $q[1] : 'not reported' ) );
	mwt_check( isset( $listed['page'], $listed['news'] ) && 1 === count( $listed['page'] ) && 1 === count( $listed['news'] ), 'page and news sitemaps are listed once (ordinary case)' );

	// --- Every listed sitemap, per type -----------------------------------------
	$bodies = array();
	foreach ( array( 'post', 'image', 'video', 'tag', 'category', 'author' ) as $type ) {
		$urls   = isset( $listed[ $type ] ) ? $listed[ $type ] : array();
		$want   = $expected_urls[ $type ];
		$needed = (int) ceil( count( $want ) / MWT_PER_PAGE );
		mwt_check( count( $urls ) >= $needed, "$type: the index lists enough sitemaps for " . count( $want ) . ' entries', 'listed ' . count( $urls ) . ', needed at least ' . $needed );

		$all      = array();
		$ok       = true;
		$too_big  = 0;
		$queries  = array();
		foreach ( $urls as $url ) {
			$map = mwt_read_sitemap( $url );
			if ( 200 !== $map['code'] || ! $map['valid'] || false === strpos( $map['type'], 'xml' ) ) {
				$ok = false;
			}
			if ( count( $map['locs'] ) > MWT_PER_PAGE ) {
				++$too_big;
			}
			$queries[]       = $map['queries'];
			$all             = array_merge( $all, $map['locs'] );
			$bodies[ $type ] = ( isset( $bodies[ $type ] ) ? $bodies[ $type ] : '' ) . $map['xml'];
		}
		mwt_check( $ok && $urls, "$type: every listed sitemap is served as well-formed XML" );
		mwt_check( 0 === $too_big, "$type: no sitemap holds more than " . MWT_PER_PAGE . ' entries' );
		$dupes = count( $all ) - count( array_unique( $all ) );
		mwt_check( 0 === $dupes, "$type: no entry appears twice across the listed sitemaps", $dupes . ' duplicate entries' );
		$missing = array_diff( $want, $all );
		$extra   = array_diff( $all, $want );
		mwt_check( ! $missing, "$type: no qualifying entry is omitted", count( $missing ) . ' of ' . count( $want ) . ' missing, for example ' . reset( $missing ) );
		mwt_check( ! $extra, "$type: nothing that does not qualify is listed", count( $extra ) . ' unexpected, for example ' . reset( $extra ) );
		mwt_check( $queries && max( $queries ) <= MWT_MAX_QUERIES && min( $queries ) >= 0, "$type: each sitemap needs at most " . MWT_MAX_QUERIES . ' database queries', 'queries per sitemap: ' . implode( ', ', $queries ) );
		echo "INFO $type: " . count( $all ) . ' entries in ' . count( $urls ) . ' listed sitemap(s); database queries per sitemap: ' . implode( ', ', $queries ) . "\n";
		if ( in_array( $type, array( 'post', 'image', 'video' ), true ) ) {
			mwt_check( ! array_intersect( $excluded, $all ), "$type: noindex, password-protected and draft posts stay out" );
		}
	}

	// --- Selection is by ID, as in 1.0.9.2: a the_posts filter of another plugin changes nothing ---
	$marker = get_permalink( (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_name = 'mwsm-post-0002'" ) );
	foreach ( array( 'post', 'image' ) as $type ) {
		$found = false;
		foreach ( isset( $listed[ $type ] ) ? $listed[ $type ] : array() as $url ) {
			$map   = mwt_read_sitemap( $url . '?mwt_the_posts=1' );
			$found = $found || in_array( $marker, $map['locs'], true );
		}
		mwt_check( $found, "$type: a the_posts filter of another plugin does not remove a post (selection by ID, as in 1.0.9.2)" );
	}

	// --- Content of the entries (ordinary cases) ---------------------------------
	$images = isset( $bodies['image'] ) ? $bodies['image'] : '';
	$videos = isset( $bodies['video'] ) ? $bodies['video'] : '';
	mwt_check( false !== strpos( $images, '<image:loc>https://img.example.test/mwsm-0001.jpg</image:loc>' ), 'image: an inline image is listed with its URL' );
	mwt_check( false !== strpos( $images, 'mwsm-featured.jpg</image:loc>' ), 'image: a featured image is listed with the attachment URL' );
	mwt_check( false !== strpos( $videos, '<video:player_loc>https://www.youtube.com/embed/mwsm0003abc</video:player_loc>' ), 'video: a YouTube video is listed with its player URL' );
	mwt_check( false !== strpos( isset( $bodies['post'] ) ? $bodies['post'] : '', '<lastmod>2020-01-01T00:00:00+00:00</lastmod>' ), 'post: entries keep their last-modified date' );
} catch ( Throwable $e ) {
	$wpdb->query( 'ROLLBACK' );
	mwt_check( false, 'test ran to completion', get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	mwt_sitemap_cleanup();
	if ( file_exists( $mu_file ) ) {
		unlink( $mu_file );
	}
}

mwt_finish( 'sitemap-pagination' );
