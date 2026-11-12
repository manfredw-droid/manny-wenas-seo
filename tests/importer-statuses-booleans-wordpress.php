<?php
/**
 * Importer regression (1.0.9.3): post statuses, preview/actual agreement and
 * false flags. Runs the real AJAX handler in-process and checks the front end
 * over HTTP against a disposable site.
 *
 *     php tests/importer-statuses-booleans-wordpress.php /path/to/wordpress
 *
 * Source metadata is written as Yoast SEO and Rank Math store it; neither
 * plugin is installed.
 *
 * Fails on 1.0.9.2 for: draft, pending, scheduled and private posts (they were
 * never read, so a legacy noindex/nofollow was lost when such a post was later
 * published), and for explicit "index"/"follow"/"not cornerstone" settings
 * (the preview said "would change", the import said "Write failed", a second
 * run said "conflict"). Published posts, true flags, text fields, the
 * overwrite rule and the dry run must keep working as before.
 */

if ( PHP_SAPI === 'cli' && ! defined( 'DOING_AJAX' ) ) {
	define( 'DOING_AJAX', true );
}
require __DIR__ . '/regression-1093-helpers.php';

class MWT_Ajax_Response extends RuntimeException {}
add_filter(
	'wp_die_ajax_handler',
	static function () {
		return static function () {
			throw new MWT_Ajax_Response();
		};
	}
);

/**
 * One request to the real batch handler.
 *
 * @param array $post Request fields.
 * @return array Decoded JSON response.
 */
function mwt_import_request( array $post ) {
	$_POST    = array_merge( array( 'nonce' => wp_create_nonce( 'mw_seo_import' ) ), $post );
	$_REQUEST = $_POST;
	ob_start();
	try {
		MWSEO_Importer::ajax_import_batch();
	} catch ( MWT_Ajax_Response $e ) {
		unset( $e );
	}
	$json = json_decode( ob_get_clean(), true );
	if ( ! is_array( $json ) ) {
		throw new RuntimeException( 'The importer did not return JSON.' );
	}
	return $json;
}

/**
 * Run a complete preview or import the way the admin script does: batch after batch.
 *
 * @param string $source    yoast|rankmath.
 * @param bool   $dry_run   Preview only.
 * @param bool   $overwrite Overwrite existing values.
 * @return array Totals, field outcomes and per-post field details.
 */
function mwt_import( $source, $dry_run, $overwrite = false ) {
	$out    = array(
		'processed' => 0,
		'total'     => null,
		'totals'    => array(),
		'errors'    => 0,
		'outcomes'  => array(),
		'fields'    => array(),
		'batches'   => 0,
	);
	$offset = 0;
	do {
		$res = mwt_import_request(
			array(
				'source'     => $source,
				'post_types' => array( 'post', 'page' ),
				'overwrite'  => $overwrite ? '1' : '0',
				'dry_run'    => $dry_run ? '1' : '0',
				'offset'     => (string) $offset,
				'batch_size' => '7', // Small on purpose: several batches, like a large site.
			)
		);
		if ( empty( $res['success'] ) ) {
			throw new RuntimeException( 'Importer error: ' . wp_json_encode( $res ) );
		}
		$d                 = $res['data'];
		$out['processed']  = $d['processed'];
		$out['totals'][]   = $d['total'];
		$out['total']      = $d['total'];
		$out['errors']    += $d['errors'];
		foreach ( $d['field_outcomes'] as $status => $count ) {
			$out['outcomes'][ $status ] = ( isset( $out['outcomes'][ $status ] ) ? $out['outcomes'][ $status ] : 0 ) + $count;
		}
		foreach ( $d['details'] as $item ) {
			$out['fields'][ $item['post_id'] ][ $item['field'] ] = $item['status'];
		}
		$offset = $d['next_offset'];
		++$out['batches'];
	} while ( ! $d['done'] && $out['batches'] < 500 );
	return $out;
}

/**
 * Stored native value, or the marker "(absent)".
 *
 * @param int    $id  Post ID.
 * @param string $key Field key without prefix.
 * @return mixed
 */
function mwt_native( $id, $key ) {
	return metadata_exists( 'post', $id, '_mwseo_' . $key ) ? get_post_meta( $id, '_mwseo_' . $key, true ) : '(absent)';
}

/**
 * Is a stored native flag a row that reads as "off"?
 *
 * @param int    $id  Post ID.
 * @param string $key Field key without prefix.
 * @return bool
 */
function mwt_flag_off( $id, $key ) {
	return in_array( mwt_native( $id, $key ), array( '', '0' ), true ) && false === MWSEO_Meta::get( $id, $key );
}

/**
 * Field statuses of the given posts only.
 *
 * @param array $run Result of mwt_import().
 * @param int[] $ids Post IDs.
 * @return array status => count
 */
function mwt_statuses( array $run, array $ids ) {
	$out = array();
	foreach ( $ids as $id ) {
		foreach ( isset( $run['fields'][ $id ] ) ? $run['fields'][ $id ] : array() as $field => $status ) {
			if ( 0 === strpos( $field, '_mwseo_' ) ) {
				$out[ $status ] = ( isset( $out[ $status ] ) ? $out[ $status ] : 0 ) + 1;
			}
		}
	}
	ksort( $out );
	return $out;
}

/**
 * Robots meta content of a front-end page.
 *
 * @param string $url Absolute URL.
 * @return string
 */
function mwt_robots( $url ) {
	$res = mwt_fetch( substr( $url, strlen( untrailingslashit( home_url() ) ) ) );
	return 200 === $res['code'] && preg_match( "#<meta name='robots' content='([^']*)'#", $res['body'], $m ) ? $m[1] : 'HTTP ' . $res['code'];
}

$ids = array();
$mk  = static function ( $name, $status, array $meta, array $extra = array() ) use ( &$ids ) {
	$id = wp_insert_post(
		array_merge(
			array(
				'post_type'    => 'post',
				'post_status'  => $status,
				'post_title'   => 'MWIM ' . $name,
				'post_name'    => 'mwim-' . $name,
				'post_content' => '<p>Importer fixture ' . $name . '.</p>',
			),
			$extra
		),
		true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	foreach ( $meta as $key => $value ) {
		add_metadata( 'post', $id, $key, $value ); // add_metadata also accepts revisions.
	}
	$ids[ $name ] = $id;
	return $id;
};

try {
	// --- Fixtures ---------------------------------------------------------------
	$future = array( 'post_date' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) );
	// Yoast SEO.
	$mk( 'y-pub-noindex', 'publish', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_metadesc' => 'A literal Yoast description.', '_yoast_wpseo_focuskw' => 'yoast phrase' ) );
	$mk( 'y-pub-index', 'publish', array( '_yoast_wpseo_meta-robots-noindex' => '2', '_yoast_wpseo_meta-robots-nofollow' => '0', '_yoast_wpseo_title' => 'Hand written title' ) );
	$mk( 'y-draft', 'draft', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_meta-robots-nofollow' => '1', '_yoast_wpseo_title' => 'Draft custom title' ) );
	$mk( 'y-pending', 'pending', array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
	$mk( 'y-future', 'future', array( '_yoast_wpseo_meta-robots-nofollow' => '1', '_yoast_wpseo_metadesc' => 'Scheduled description.' ), $future );
	$mk( 'y-private', 'private', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_is_cornerstone' => '1' ) );
	$mk( 'y-page-draft', 'draft', array( '_yoast_wpseo_meta-robots-noindex' => '1' ), array( 'post_type' => 'page' ) );
	$mk( 'y-trash', 'trash', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_title' => 'Trashed title' ) );
	$mk( 'y-auto-draft', 'auto-draft', array( '_yoast_wpseo_meta-robots-noindex' => '1' ) );
	$mk( 'y-revision', 'inherit', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_yoast_wpseo_title' => 'Revision title' ), array( 'post_type' => 'revision', 'post_parent' => $ids['y-pub-noindex'] ) );
	// Rank Math.
	$mk( 'r-pub-index', 'publish', array( 'rank_math_robots' => array( 'index' ), 'rank_math_title' => '%title% - %sitename%', 'rank_math_focus_keyword' => 'rank phrase,second phrase' ) );
	$mk( 'r-pub-noindex', 'publish', array( 'rank_math_robots' => array( 'noindex', 'follow' ), 'rank_math_pillar_content' => 'off' ) );
	$mk( 'r-draft', 'draft', array( 'rank_math_robots' => array( 'noindex', 'nofollow' ), 'rank_math_pillar_content' => 'on', 'rank_math_description' => 'A literal Rank Math description.' ) );
	$mk( 'r-pending', 'pending', array( 'rank_math_robots' => array( 'index', 'nofollow' ) ) );
	$mk( 'r-trash', 'trash', array( 'rank_math_robots' => array( 'noindex' ) ) );

	$included = array( 'y-pub-noindex', 'y-pub-index', 'y-draft', 'y-pending', 'y-future', 'y-private', 'y-page-draft', 'r-pub-index', 'r-pub-noindex', 'r-draft', 'r-pending' );
	$excluded = array( 'y-trash', 'y-auto-draft', 'y-revision', 'r-trash' );
	$yoast    = array_values( array_intersect_key( $ids, array_flip( array( 'y-pub-noindex', 'y-pub-index', 'y-draft', 'y-pending', 'y-future', 'y-private', 'y-page-draft' ) ) ) );
	$rank     = array_values( array_intersect_key( $ids, array_flip( array( 'r-pub-index', 'r-pub-noindex', 'r-draft', 'r-pending' ) ) ) );
	$native   = array( 'title', 'desc', 'focus', 'noindex', 'nofollow', 'cornerstone' );
	$snapshot = static function () use ( $ids, $native ) {
		$out = array();
		foreach ( $ids as $name => $id ) {
			foreach ( $native as $key ) {
				$out[ $name . '.' . $key ] = mwt_native( $id, $key );
			}
		}
		return $out;
	};
	// What "every importable post" means, counted independently of the importer.
	$expected_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( 'post', 'page' ) AND post_status IN ( 'publish', 'draft', 'pending', 'future', 'private' )" );

	foreach (
		array(
			'yoast'    => $yoast,
			'rankmath' => $rank,
		) as $source => $source_ids
	) {
		// --- Preview --------------------------------------------------------------
		$before  = $snapshot();
		$preview = mwt_import( $source, true );
		mwt_check( $snapshot() === $before, "$source preview: writes nothing" );
		mwt_check( $preview['processed'] === $expected_total, "$source preview: processes published, draft, pending, scheduled and private posts", 'processed ' . $preview['processed'] . ', importable posts ' . $expected_total );
		mwt_check( array( $expected_total ) === array_values( array_unique( $preview['totals'] ) ), "$source preview: the reported total equals the posts processed, in every batch", 'totals reported: ' . implode( ',', array_unique( $preview['totals'] ) ) . ', importable posts ' . $expected_total );
		$touched = array_intersect( array_keys( $preview['fields'] ), array_values( array_intersect_key( $ids, array_flip( $excluded ) ) ) );
		mwt_check( ! $touched, "$source preview: trash, auto-draft and revision are not read", 'post IDs: ' . implode( ',', $touched ) );
		$planned = mwt_statuses( $preview, $source_ids );
		mwt_check( array( 'would_change' ) === array_keys( $planned ), "$source preview: every importable field is announced as a planned change", wp_json_encode( $planned ) );
		$unseen = array_diff( $source_ids, array_keys( $preview['fields'] ) );
		mwt_check( ! $unseen, "$source preview: no fixture post is skipped", 'missing post IDs: ' . implode( ',', $unseen ) );

		// --- Import ---------------------------------------------------------------
		$run  = mwt_import( $source, false );
		$done = mwt_statuses( $run, $source_ids );
		mwt_check( $run['processed'] === $preview['processed'] && $run['total'] === $preview['total'], "$source import: processes the same posts as the preview", 'preview ' . $preview['processed'] . '/' . $preview['total'] . ', import ' . $run['processed'] . '/' . $run['total'] );
		mwt_check( 0 === $run['errors'] && empty( $run['outcomes']['failed'] ), "$source import: reports no write failure", 'errors ' . $run['errors'] . ', outcomes ' . wp_json_encode( $run['outcomes'] ) );
		mwt_check( array( 'changed' => $planned['would_change'] ?? -1 ) === $done, "$source import: changes exactly the fields the preview announced", 'preview ' . wp_json_encode( $planned ) . ', import ' . wp_json_encode( $done ) );
		$same_fields = true;
		foreach ( $source_ids as $id ) {
			$a = isset( $preview['fields'][ $id ] ) ? array_keys( $preview['fields'][ $id ] ) : array();
			$b = isset( $run['fields'][ $id ] ) ? array_keys( $run['fields'][ $id ] ) : array();
			sort( $a );
			sort( $b );
			$same_fields = $same_fields && $a === $b;
		}
		mwt_check( $same_fields, "$source import: reports the same fields per post as the preview" );

		// --- Second import: nothing left to do -------------------------------------
		$again = mwt_statuses( mwt_import( $source, false ), $source_ids );
		mwt_check( array( 'matched' ) === array_keys( $again ) && $again['matched'] === ( $planned['would_change'] ?? -1 ), "$source second import: every field already matches (no conflict, no failure)", wp_json_encode( $again ) );
		$again_preview = mwt_statuses( mwt_import( $source, true ), $source_ids );
		mwt_check( array( 'matched' ) === array_keys( $again_preview ), "$source second preview: announces no change", wp_json_encode( $again_preview ) );
	}

	// --- Stored values ------------------------------------------------------------
	mwt_check( '1' === mwt_native( $ids['y-pub-noindex'], 'noindex' ) && 'A literal Yoast description.' === mwt_native( $ids['y-pub-noindex'], 'desc' ) && 'yoast phrase' === mwt_native( $ids['y-pub-noindex'], 'focus' ), 'Yoast published post: noindex, description and focus keyphrase imported (ordinary case)' );
	mwt_check( 'Hand written title' === mwt_native( $ids['y-pub-index'], 'title' ), 'Yoast published post: literal title imported (ordinary case)' );
	mwt_check( mwt_flag_off( $ids['y-pub-index'], 'noindex' ) && mwt_flag_off( $ids['y-pub-index'], 'nofollow' ), 'Yoast explicit index/follow: stored as an explicit "off" flag', wp_json_encode( array( mwt_native( $ids['y-pub-index'], 'noindex' ), mwt_native( $ids['y-pub-index'], 'nofollow' ) ) ) );
	mwt_check( '1' === mwt_native( $ids['y-draft'], 'noindex' ) && '1' === mwt_native( $ids['y-draft'], 'nofollow' ) && 'Draft custom title' === mwt_native( $ids['y-draft'], 'title' ), 'Yoast draft: noindex, nofollow and title imported' );
	mwt_check( '1' === mwt_native( $ids['y-pending'], 'noindex' ), 'Yoast pending post: noindex imported' );
	mwt_check( '1' === mwt_native( $ids['y-future'], 'nofollow' ) && 'Scheduled description.' === mwt_native( $ids['y-future'], 'desc' ), 'Yoast scheduled post: nofollow and description imported' );
	mwt_check( '1' === mwt_native( $ids['y-private'], 'noindex' ) && '1' === mwt_native( $ids['y-private'], 'cornerstone' ), 'Yoast private post: noindex and cornerstone imported' );
	mwt_check( '1' === mwt_native( $ids['y-page-draft'], 'noindex' ), 'Yoast draft page: noindex imported' );
	mwt_check( '%%title%% - %%sitename%%' === mwt_native( $ids['r-pub-index'], 'title' ) && 'rank phrase' === mwt_native( $ids['r-pub-index'], 'focus' ), 'Rank Math published post: title tokens converted, first keyphrase imported (ordinary case)' );
	mwt_check( mwt_flag_off( $ids['r-pub-index'], 'noindex' ) && '(absent)' === mwt_native( $ids['r-pub-index'], 'nofollow' ), 'Rank Math robots [index]: noindex stored "off", nofollow left alone', wp_json_encode( array( mwt_native( $ids['r-pub-index'], 'noindex' ), mwt_native( $ids['r-pub-index'], 'nofollow' ) ) ) );
	mwt_check( '1' === mwt_native( $ids['r-pub-noindex'], 'noindex' ) && mwt_flag_off( $ids['r-pub-noindex'], 'nofollow' ) && mwt_flag_off( $ids['r-pub-noindex'], 'cornerstone' ), 'Rank Math robots [noindex, follow] and pillar "off": noindex on, nofollow and cornerstone "off"' );
	mwt_check( '1' === mwt_native( $ids['r-draft'], 'noindex' ) && '1' === mwt_native( $ids['r-draft'], 'nofollow' ) && '1' === mwt_native( $ids['r-draft'], 'cornerstone' ) && 'A literal Rank Math description.' === mwt_native( $ids['r-draft'], 'desc' ), 'Rank Math draft: noindex, nofollow, cornerstone and description imported' );
	mwt_check( mwt_flag_off( $ids['r-pending'], 'noindex' ) && '1' === mwt_native( $ids['r-pending'], 'nofollow' ), 'Rank Math pending post: nofollow imported' );
	$untouched = true;
	foreach ( $excluded as $name ) {
		foreach ( $native as $key ) {
			$untouched = $untouched && '(absent)' === mwt_native( $ids[ $name ], $key );
		}
	}
	mwt_check( $untouched, 'trash, auto-draft and revision: nothing was written' );

	// --- Existing native values and the overwrite rule (ordinary cases) -----------
	$mk( 'y-native-on', 'publish', array( '_yoast_wpseo_meta-robots-noindex' => '2', '_mwseo_noindex' => '1', '_yoast_wpseo_title' => 'Legacy title', '_mwseo_title' => 'Native title' ) );
	$mk( 'y-native-off', 'publish', array( '_yoast_wpseo_meta-robots-noindex' => '1', '_mwseo_noindex' => '' ) );
	$keep = mwt_import( 'yoast', false );
	mwt_check( 'conflict' === ( $keep['fields'][ $ids['y-native-on'] ]['_mwseo_noindex'] ?? '' ) && 'conflict' === ( $keep['fields'][ $ids['y-native-on'] ]['_mwseo_title'] ?? '' ) && '1' === mwt_native( $ids['y-native-on'], 'noindex' ) && 'Native title' === mwt_native( $ids['y-native-on'], 'title' ), 'existing native values are preserved without Overwrite', wp_json_encode( $keep['fields'][ $ids['y-native-on'] ] ?? null ) );
	mwt_check( 'conflict' === ( $keep['fields'][ $ids['y-native-off'] ]['_mwseo_noindex'] ?? '' ) && '' === mwt_native( $ids['y-native-off'], 'noindex' ), 'an existing native "off" flag is preserved against a legacy noindex without Overwrite' );
	$over = mwt_import( 'yoast', false, true );
	mwt_check( 'changed' === ( $over['fields'][ $ids['y-native-on'] ]['_mwseo_noindex'] ?? '' ) && mwt_flag_off( $ids['y-native-on'], 'noindex' ) && 'Legacy title' === mwt_native( $ids['y-native-on'], 'title' ) && 0 === $over['errors'], 'Overwrite replaces existing native values, including turning a flag off', wp_json_encode( $over['fields'][ $ids['y-native-on'] ] ?? null ) );
	mwt_check( '1' === mwt_native( $ids['y-native-off'], 'noindex' ), 'Overwrite turns a native "off" flag on from a legacy noindex' );

	// --- A post that was unpublished at import time keeps its robots setting -----
	wp_publish_post( $ids['y-draft'] );
	wp_publish_post( $ids['r-pending'] );
	wp_publish_post( $ids['y-pending'] );
	$robots = mwt_robots( get_permalink( $ids['y-draft'] ) );
	mwt_check( false !== strpos( $robots, 'noindex' ) && false !== strpos( $robots, 'nofollow' ), 'Yoast draft published after the import: page is noindex, nofollow', 'robots: ' . $robots );
	$robots = mwt_robots( get_permalink( $ids['r-pending'] ) );
	mwt_check( false !== strpos( $robots, 'nofollow' ) && false === strpos( $robots, 'noindex' ), 'Rank Math pending post published after the import: page is nofollow and stays indexable', 'robots: ' . $robots );
	$robots = mwt_robots( get_permalink( $ids['y-pub-index'] ) );
	mwt_check( false === strpos( $robots, 'noindex' ) && false === strpos( $robots, 'nofollow' ) && 0 !== strpos( $robots, 'HTTP' ), 'explicit index/follow post: page stays indexable (ordinary case)', 'robots: ' . $robots );
	$sitemap = mwt_fetch( '/mwseo-post-sitemap.xml' );
	mwt_check( false === strpos( $sitemap['body'], get_permalink( $ids['y-draft'] ) ) && false === strpos( $sitemap['body'], get_permalink( $ids['y-pending'] ) ), 'posts published after the import with noindex stay out of the sitemap' );
	mwt_check( false !== strpos( $sitemap['body'], get_permalink( $ids['r-pending'] ) ) && false !== strpos( $sitemap['body'], get_permalink( $ids['y-pub-index'] ) ), 'indexable posts are in the sitemap (ordinary case)' );
} catch ( Throwable $e ) {
	while ( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	mwt_check( false, 'test ran to completion', get_class( $e ) . ': ' . $e->getMessage() . ' @' . $e->getLine() );
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
}

mwt_finish( 'importer-statuses-booleans' );
