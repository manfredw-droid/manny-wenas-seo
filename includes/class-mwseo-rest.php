<?php
/**
 * REST API routes used by the editor UI.
 *
 * @package MannyWenasSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the mwseo/v1 namespace.
 */
class MWSEO_Rest {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
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
