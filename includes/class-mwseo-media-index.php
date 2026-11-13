<?php
defined( 'ABSPATH' ) || exit;
/**
 * Which posts have an image or a video, for the image and video sitemaps.
 *
 * @package MannyWenasSEO
 */

/**
 * Records per post whether the image and the video sitemap have something to list.
 *
 * Whether a post has media can only be read from its content and featured image. Reading
 * that for every post on every sitemap request is too slow, so the answer is stored with
 * the post when it changes: a hidden flag (post meta) for "has an image" and one for "has a
 * video". A post without the flag has none. The sitemaps then select flagged posts with an
 * indexed lookup, list only sitemaps that have entries and fill each one completely.
 *
 * Content that existed before the flags were introduced is read once, in small steps: while
 * sitemaps are requested and, started by the first of those requests, by a scheduled event
 * that repeats until everything has been read (step()). Until that is complete, ready() is
 * false and the sitemaps keep working the former way, so no post is left out in the meantime.
 *
 * The flags are derived data: they are removed on uninstall and rebuilt when RULES changes.
 */
class MWSEO_Media_Index {

	const OPTION    = 'mwseo_media_index';
	const LOCK      = 'mwseo_media_index_lock';
	const CRON      = 'mwseo_media_index_step';
	const EPOCH     = 'mwseo_media_index_epoch';
	const IMAGE_KEY = '_mwseo_has_image';
	const VIDEO_KEY = '_mwseo_has_video';

	/**
	 * Version of the detection rules in image_urls() and videos(). Increase it whenever
	 * those rules change, so existing content is read again.
	 */
	const RULES = '1';

	/**
	 * Posts whose featured image is being deleted from the media library.
	 *
	 * @var int[]
	 */
	private static $orphans = array();

	/**
	 * Hooks that keep the flags current.
	 */
	public static function init() {
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'delete_attachment', array( __CLASS__, 'before_attachment_delete' ) );
		add_action( self::CRON, array( __CLASS__, 'cron' ) );
	}

	/**
	 * Scheduled continuation of step(), with more time than a visitor's request gets.
	 */
	public static function cron() {
		self::step( 10 );
	}

	/**
	 * Flag key of a sitemap type.
	 *
	 * @param string $type 'image' or 'video'.
	 * @return string
	 */
	public static function key( $type ) {
		return 'video' === $type ? self::VIDEO_KEY : self::IMAGE_KEY;
	}

	/**
	 * Post types whose posts are flagged: every public type and every type enabled in the
	 * settings, without attachments.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = array_merge( array_values( get_post_types( array( 'public' => true ) ) ), (array) MWSEO_Options::get( 'post_types' ) );
		$types = array_values( array_unique( array_diff( array_filter( $types, 'is_string' ), array( 'attachment', 'revision' ) ) ) );
		sort( $types );
		return $types;
	}

	/**
	 * Image URLs of a post for the image sitemap: featured image and images in the content.
	 *
	 * @param int $id Post ID.
	 * @return string[]
	 */
	public static function image_urls( $id ) {
		$urls  = array();
		$thumb = get_the_post_thumbnail_url( $id, 'full' );
		if ( $thumb ) {
			$urls[] = $thumb;
		}
		preg_match_all( '#<img[^>]+src=["\']([^"\']+)["\']#i', (string) get_post_field( 'post_content', $id ), $m );
		foreach ( $m[1] as $src ) {
			$urls[] = $src;
		}
		return array_slice( array_unique( $urls ), 0, 1000 );
	}

	/**
	 * Videos of a post for the video sitemap: YouTube, and Vimeo or video files when the
	 * post has a featured image to use as thumbnail.
	 *
	 * @param int $id Post ID.
	 * @return array[] Each with thumb, player and file.
	 */
	public static function videos( $id ) {
		$content = (string) get_post_field( 'post_content', $id );
		$videos  = array();
		preg_match_all( '#(?:youtube\.com/(?:embed/|watch\?v=)|youtu\.be/)([\w-]{11})#', $content, $yt );
		foreach ( array_unique( $yt[1] ) as $vid ) {
			$videos[] = array(
				'thumb'  => 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
				'player' => 'https://www.youtube.com/embed/' . $vid,
				'file'   => '',
			);
		}
		$feat = get_the_post_thumbnail_url( $id, 'full' );
		preg_match_all( '#vimeo\.com/(?:video/)?(\d+)#', $content, $vm );
		foreach ( array_unique( $vm[1] ) as $vid ) {
			if ( $feat ) {
				$videos[] = array(
					'thumb'  => $feat,
					'player' => 'https://player.vimeo.com/video/' . $vid,
					'file'   => '',
				);
			}
		}
		preg_match_all( '#<(?:video|source)[^>]+src=["\']([^"\']+\.(?:mp4|webm|mov))["\']#i', $content, $fl );
		foreach ( array_unique( $fl[1] ) as $file ) {
			if ( $feat ) {
				$videos[] = array(
					'thumb'  => $feat,
					'player' => '',
					'file'   => $file,
				);
			}
		}
		return $videos;
	}

	/**
	 * Recompute both flags of a post.
	 *
	 * @param int $id Post ID.
	 */
	public static function refresh( $id ) {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		self::set_flag( $post->ID, self::IMAGE_KEY, (bool) self::image_urls( $post->ID ) );
		self::set_flag( $post->ID, self::VIDEO_KEY, (bool) self::videos( $post->ID ) );
	}

	/**
	 * Store or remove one flag, writing only when it changes.
	 *
	 * @param int    $id  Post ID.
	 * @param string $key Flag key.
	 * @param bool   $on  Whether the post has this kind of media.
	 */
	private static function set_flag( $id, $key, $on ) {
		$stored = '1' === get_post_meta( $id, $key, true );
		if ( $on && ! $stored ) {
			update_post_meta( $id, $key, '1' );
		} elseif ( ! $on && metadata_exists( 'post', $id, $key ) ) {
			delete_post_meta( $id, $key );
		}
	}

	/**
	 * A post was saved: its content may have gained or lost media.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}
		self::refresh( $post_id );
	}

	/**
	 * The featured image of a post was set, changed or removed. WordPress does that without
	 * saving the post (and, from the block editor, after saving it).
	 *
	 * @param mixed  $meta_id Meta ID(s).
	 * @param int    $post_id Post ID; empty when a deleted attachment is removed from all posts.
	 * @param string $key     Meta key.
	 */
	public static function on_meta( $meta_id, $post_id, $key ) {
		if ( '_thumbnail_id' !== $key ) {
			return;
		}
		if ( $post_id ) {
			self::refresh( (int) $post_id );
			return;
		}
		$posts         = self::$orphans;
		self::$orphans = array();
		foreach ( $posts as $id ) {
			self::refresh( $id );
		}
	}

	/**
	 * An image is about to be deleted from the media library: remember which posts use it as
	 * featured image. WordPress removes it from all of them at once right after this.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function before_attachment_delete( $attachment_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one indexed lookup while an attachment is deleted.
		$ids           = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s", (string) (int) $attachment_id ) );
		self::$orphans = array_map( 'intval', $ids );
	}

	/**
	 * Stored progress, started afresh when the rules or the post types changed.
	 *
	 * On a network, progress also starts afresh when the plugin was deactivated or activated
	 * for the whole network since it was stored (see epoch()).
	 *
	 * @return array version, types, cursor (highest post ID read so far), done, epoch.
	 */
	private static function state() {
		$state = get_option( self::OPTION );
		$types = self::post_types();
		$epoch = self::epoch();
		if ( ! is_array( $state ) || ! isset( $state['version'], $state['types'], $state['cursor'] ) || self::RULES !== $state['version'] || array_diff( $types, (array) $state['types'] )
			|| ( isset( $state['epoch'] ) ? (string) $state['epoch'] : '0' ) !== $epoch ) {
			$state = array(
				'version' => self::RULES,
				'types'   => $types,
				'cursor'  => 0,
				'done'    => false,
				'epoch'   => $epoch,
			);
		}
		return $state;
	}

	/**
	 * Network-wide marker that changes whenever the plugin is deactivated or activated for a
	 * whole network. Always '0' on a single site.
	 *
	 * WordPress runs the deactivation and activation code once per network, in the site the
	 * administrator is on, so the other sites cannot be reset there without visiting every
	 * one of them. Each site instead stores the marker with its progress and starts reading
	 * its content again when it next looks at that progress and finds a different marker.
	 *
	 * @return string
	 */
	private static function epoch() {
		return is_multisite() ? (string) get_site_option( self::EPOCH, '0' ) : '0';
	}

	/**
	 * Forget that existing content has been read, so it is read again from the start.
	 *
	 * Called when the plugin is deactivated and when it is activated: while it is switched off
	 * nothing keeps the flags current, so a post published or changed in that time would be
	 * missing from the image and video sitemaps for good. Until the content has been read
	 * again the sitemaps list every eligible post the former way. The flags themselves stay;
	 * reading the content again corrects them.
	 *
	 * @param bool $network_wide The plugin is being deactivated or activated for a whole network:
	 *                           every other site of the network starts afresh too, each when
	 *                           it next reads its progress (see epoch()).
	 */
	public static function reset( $network_wide = false ) {
		delete_option( self::OPTION );
		delete_transient( self::LOCK );
		wp_clear_scheduled_hook( self::CRON );
		if ( $network_wide && is_multisite() ) {
			update_site_option( self::EPOCH, (string) ( (int) get_site_option( self::EPOCH, 0 ) + 1 ) );
		}
	}

	/**
	 * Has all existing content been read, so the flags can be relied on?
	 *
	 * @return bool
	 */
	public static function ready() {
		$state = self::state();
		return ! empty( $state['done'] );
	}

	/**
	 * Fill the caches for the featured images of these posts in one go.
	 *
	 * @param int[] $ids Post IDs whose meta is already cached.
	 */
	public static function prime_thumbnails( array $ids ) {
		if ( ! function_exists( '_prime_post_caches' ) ) {
			return;
		}
		$images = array();
		foreach ( $ids as $id ) {
			$images[] = (int) get_post_meta( $id, '_thumbnail_id', true );
		}
		$images = array_values( array_unique( array_filter( $images ) ) );
		if ( $images ) {
			_prime_post_caches( $images, false, true );
		}
	}

	/**
	 * Read a bounded amount of existing content and flag it. Called while sitemaps are
	 * requested and by the scheduled event until everything has been read once; does nothing
	 * afterwards.
	 *
	 * Posts are read in ID order from a stored position, so each call continues where the
	 * last one stopped. A post saved in the meantime flags itself (on_save()). A short lock
	 * keeps simultaneous requests from doing the same work. While work is left, one scheduled
	 * event is kept pending, so a large site does not depend on how often its sitemaps are read.
	 *
	 * @param float $seconds Time this call may spend.
	 */
	public static function step( $seconds = 1.5 ) {
		global $wpdb;
		$state = self::state();
		if ( ! empty( $state['done'] ) ) {
			return;
		}
		/**
		 * Seconds one call may spend reading existing content: 1.5 during a sitemap request,
		 * 10 in the scheduled event. 0 pauses it.
		 *
		 * @param float $seconds Seconds.
		 */
		$seconds = (float) apply_filters( 'mwseo_media_index_seconds', (float) $seconds );
		if ( $seconds <= 0 || get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 30 );
		$stop  = microtime( true ) + $seconds;
		$types = $state['types'];
		$in    = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- primary-key range read; $in holds only placeholders.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ( {$in} ) AND post_status <> 'auto-draft' ORDER BY ID ASC LIMIT 200", array_merge( array( (int) $state['cursor'] ), $types ) ) );
			if ( ! $ids ) {
				$state['done'] = true;
				break;
			}
			$ids = array_map( 'intval', $ids );
			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, false, true );
			}
			self::prime_thumbnails( $ids );
			foreach ( $ids as $id ) {
				self::refresh( $id );
				// Posts that were only read for this are not needed in memory any longer.
				wp_cache_delete( $id, 'posts' );
				wp_cache_delete( $id, 'post_meta' );
			}
			$state['cursor'] = end( $ids );
		} while ( microtime( true ) < $stop );
		update_option( self::OPTION, $state, true );
		delete_transient( self::LOCK );
		if ( empty( $state['done'] ) && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::CRON );
		}
	}
}
