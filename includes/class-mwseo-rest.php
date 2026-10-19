<?php
defined( 'ABSPATH' ) || exit;
/**
 * REST API routes used by the editor UI.
 *
 * @package MannyWenasSEO
 */

/**
 * Registers the mwseo/v1 namespace.
 */
class MWSEO_Rest {

	/** @var SplObjectStorage|null Saves already considered during this request. */
	private static $score_refreshes;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'score_hooks' ) );
	}

	/** Register final-save hooks for configured post types. */
	public static function score_hooks() {
		foreach ( MWSEO_Options::get( 'post_types', array( 'post', 'page' ) ) as $post_type ) {
			add_action( "rest_after_insert_{$post_type}", array( __CLASS__, 'refresh_score' ), 10, 3 );
		}
	}

	/**
	 * Refresh the snapshot after a successful ordinary core REST save.
	 * Explicit score operations take precedence, including null resets.
	 *
	 * @param WP_Post         $post     Saved post.
	 * @param WP_REST_Request $request  Dispatched request.
	 * @param bool            $creating Whether this is a creation.
	 */
	public static function refresh_score( $post, $request, $creating ) {
		$attributes = $request->get_attributes();
		$callback   = isset( $attributes['callback'] ) ? $attributes['callback'] : null;
		if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) ||
			! is_object( $callback[0] ) || 'WP_REST_Posts_Controller' !== get_class( $callback[0] ) ||
			! in_array( $callback[1], array( 'create_item', 'update_item' ), true ) ||
			! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) ||
			! in_array( $post->post_type, MWSEO_Options::get( 'post_types', array( 'post', 'page' ) ), true ) ||
			( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ||
			wp_is_post_autosave( $post->ID ) || wp_is_post_revision( $post->ID ) ||
			! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		// Inspect supplied parameters, not schema defaults on irrelevant updates.
		$supplied = array_merge( $request->get_query_params(), $request->get_body_params(), (array) $request->get_json_params() );
		$meta     = isset( $supplied['meta'] ) ? $request->get_param( 'meta' ) : array();
		$meta     = is_array( $meta ) ? $meta : array();
		if ( array_key_exists( '_mwseo_score', $meta ) ) {
			return;
		}
		if ( ! $creating &&
			! array_intersect( array( 'content', 'title', 'slug' ), array_keys( $supplied ) ) &&
			! array_intersect( array( '_mwseo_title', '_mwseo_desc', '_mwseo_focus', '_mwseo_related' ), array_keys( $meta ) ) ) {
			return;
		}

		if ( null === self::$score_refreshes ) {
			self::$score_refreshes = new SplObjectStorage();
		}
		$seen = self::$score_refreshes->contains( $request ) ? self::$score_refreshes[ $request ] : array();
		if ( isset( $seen[ $post->ID ] ) ) {
			return;
		}
		$seen[ $post->ID ] = true;
		self::$score_refreshes[ $request ] = $seen;

		try {
			$stored_post = get_post( $post->ID );
			if ( ! $stored_post ) {
				throw new RuntimeException( 'missing_post' );
			}
			$result = self::analyze_post( $stored_post );
			if ( ! is_array( $result ) || ! isset( $result['score'] ) || ! is_int( $result['score'] ) ||
				$result['score'] < 0 || $result['score'] > 100 ) {
				throw new RuntimeException( 'invalid_analysis' );
			}
			$score = $result['score'];
			if ( self::stored_score_matches( $post->ID, $score ) ) {
				return;
			}
			MWSEO_Meta::set( $post->ID, 'score', $score );
			if ( ! self::stored_score_matches( $post->ID, $score ) ) {
				throw new RuntimeException( 'score_write_failed' );
			}
		} catch ( Throwable $error ) {
			// Content is already saved. Do not invent a zero, undo the save or leak content.
			error_log( 'Manny Wenas SEO: REST score snapshot refresh failed for post ' . (int) $post->ID . '.' );
		}
	}

	/** Check actual storage, distinguishing a valid zero from a missing record. */
	private static function stored_score_matches( $post_id, $score ) {
		$stored = get_post_meta( $post_id, '_mwseo_score', true );
		return metadata_exists( 'post', $post_id, '_mwseo_score' ) && is_scalar( $stored ) && (string) $stored === (string) $score;
	}

	/**
	 * Routes.
	 */
	public static function routes() {
		$can_edit = static function ( WP_REST_Request $req ) {
			$id = (int) $req->get_param( 'post_id' );
			return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
		};

		register_rest_route(
			'mwseo/v1',
			'/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_analyze' ),
				'permission_callback' => $can_edit,
			)
		);
		register_rest_route(
			'mwseo/v1',
			'/gsc/(?P<post_id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_gsc' ),
				'permission_callback' => $can_edit,
			)
		);  }

	/**
	 * POST /analyze: live analysis of unsaved editor state.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	public static function rest_analyze( WP_REST_Request $req ) {
		$related = array_map( 'sanitize_text_field', (array) $req->get_param( 'related' ) );
		return MWSEO_Analyzer::analyze(
			array(
				'title'   => sanitize_text_field( (string) $req->get_param( 'title' ) ),
				'desc'    => sanitize_textarea_field( (string) $req->get_param( 'desc' ) ),
				'slug'    => sanitize_title( (string) $req->get_param( 'slug' ) ),
				'focus'   => sanitize_text_field( (string) $req->get_param( 'focus' ) ),
				'related' => $related,
				'content' => wp_kses_post( (string) $req->get_param( 'content' ) ),
			)
		);
	}

	/**
	 * Analyse a stored post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function analyze_post( $post ) {
		$id = $post->ID;
		return MWSEO_Analyzer::analyze(
			array(
				'title'   => MWSEO_Meta::get( $id, 'title' ),
				'desc'    => MWSEO_Meta::get( $id, 'desc' ),
				'slug'    => $post->post_name,
				'focus'   => MWSEO_Meta::get( $id, 'focus' ),
				'related' => MWSEO_Meta::get( $id, 'related' ),
				'content' => apply_filters( 'the_content', $post->post_content ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			)
		);
	}

	/**
	 * GET /gsc/<id>: per-post query data.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	public static function rest_gsc( WP_REST_Request $req ) {
		$post_id = (int) $req['post_id'];
		$rows    = MWSEO_Gsc::page_queries( get_permalink( $post_id ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return array( 'rows' => $rows );
	}
}
