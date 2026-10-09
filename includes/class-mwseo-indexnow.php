<?php
/**
 * IndexNow key file and publish/update pings.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Notifies IndexNow (Bing, Yandex, …) when a post or page is published or updated.
 */
class MWSEO_Indexnow {

	const META_KEY = '_mwseo_indexnow_pinged';

	/**
	 * Post IDs already pinged during this request.
	 *
	 * @var int[]
	 */
	private static $pinged = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'ensure_key' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve_key' ), 0 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 20, 3 );
		add_action( 'save_post', array( __CLASS__, 'on_save' ), 20, 2 );
		add_action( 'admin_post_mwseo_indexnow_regenerate', array( __CLASS__, 'handle_regenerate' ) );
	}

	/**
	 * Generate a key when none exists. Safe to call repeatedly.
	 *
	 * @return string The key.
	 */
	public static function ensure_key() {
		$key = (string) MWSEO_Options::get( 'indexnow_key' );
		if ( '' === $key ) {
			$key = self::store_new_key();
		}
		return $key;
	}

	/**
	 * Create and persist a new key.
	 *
	 * @return string
	 */
	private static function store_new_key() {
		$key                 = wp_generate_password( 32, false );
		$all                 = MWSEO_Options::all();
		$all['indexnow_key'] = $key;
		MWSEO_Options::save( $all );
		return $key;
	}

	/**
	 * Serve /{key}.txt as plain text.
	 */
	public static function serve_key() {
		$key = (string) MWSEO_Options::get( 'indexnow_key' );
		if ( '' === $key ) {
			return;
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		if ( ltrim( $path, '/' ) !== $key . '.txt' ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo esc_html( $key );
		exit;
	}

	/**
	 * New publication.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		self::ping( $post );
	}

	/**
	 * Update of an already published post.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function on_save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}
		self::ping( $post );
	}

	/**
	 * Fire-and-forget ping for a post or page.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function ping( $post ) {
		// Opt-in: nothing leaves the site unless the administrator enabled IndexNow.
		if ( ! MWSEO_Options::get( 'indexnow_enabled' ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) || in_array( (int) $post->ID, self::$pinged, true ) ) {
			return;
		}
		if ( 'publish' !== get_post_status( $post ) || '' !== (string) $post->post_password ) {
			return;
		}
		$key = self::ensure_key();
		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}
		self::$pinged[] = (int) $post->ID;
		wp_remote_get(
			add_query_arg(
				array(
					'url' => $url,
					'key' => $key,
				),
				'https://api.indexnow.org/indexnow'
			),
			array(
				'blocking' => false,
				'timeout'  => 5,
			)
		);
		update_post_meta( $post->ID, self::META_KEY, time() );
	}

	/**
	 * "Regenerate key" POST action.
	 */
	public static function handle_regenerate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'manny-wenas-seo' ), 403 );
		}
		check_admin_referer( 'mwseo_indexnow_regenerate', 'mwseo_indexnow_nonce' );
		self::store_new_key();
		wp_safe_redirect( admin_url( 'admin.php?page=mwseo&tab=verify&mwseo_msg=indexnow_renewed' ) );
		exit;
	}
}
