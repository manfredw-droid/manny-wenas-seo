<?php
/**
 * WordPress Abilities API integration (WP 6.9+).
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exposes SEO capabilities to AI agents under the "mwseo" namespace.
 */
class MWSEO_Abilities {

	/**
	 * Hooks. Silently inert on WordPress versions without the Abilities API.
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'categories' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the ability category.
	 */
	public static function categories() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'mwseo',
			array(
				'label'       => __( 'SEO', 'manny-wenas-seo' ),
				'description' => __( 'Read and improve on-page SEO.', 'manny-wenas-seo' ),
			)
		);
	}

	/**
	 * Register abilities.
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$post_id_schema = array(
			'type'       => 'object',
			'properties' => array(
				'post_id' => array(
					'type'        => 'integer',
					'description' => __( 'Post or page ID.', 'manny-wenas-seo' ),
				),
			),
			'required'   => array( 'post_id' ),
		);

		$can_edit = static function ( $input = array() ) {
			$id = isset( $input['post_id'] ) ? (int) $input['post_id'] : 0;
			return $id ? current_user_can( 'edit_post', $id ) : current_user_can( 'edit_posts' );
		};

		wp_register_ability(
			'mwseo/get-seo-analysis',
			array(
				'label'               => __( 'Get SEO analysis', 'manny-wenas-seo' ),
				'description'         => __( 'Returns the 0-100 SEO and readability score, written verdict and per-check results for a post.', 'manny-wenas-seo' ),
				'category'            => 'mwseo',
				'input_schema'        => $post_id_schema,
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $can_edit,
				'execute_callback'    => static function ( $input ) {
					$post = get_post( (int) $input['post_id'] );
					return $post ? MWSEO_Rest::analyze_post( $post ) : new WP_Error( 'mwseo_not_found', __( 'Post not found.', 'manny-wenas-seo' ) );
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'mwseo/get-seo-meta',
			array(
				'label'               => __( 'Get SEO meta', 'manny-wenas-seo' ),
				'description'         => __( 'Returns the focus keyphrase, related keyphrases, SEO title, meta description and robots flags for a post.', 'manny-wenas-seo' ),
				'category'            => 'mwseo',
				'input_schema'        => $post_id_schema,
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $can_edit,
				'execute_callback'    => static function ( $input ) {
					$id = (int) $input['post_id'];
					return array(
						'focus'       => MWSEO_Meta::get( $id, 'focus' ),
						'related'     => MWSEO_Meta::get( $id, 'related' ),
						'title'       => MWSEO_Meta::get( $id, 'title' ),
						'desc'        => MWSEO_Meta::get( $id, 'desc' ),
						'noindex'     => MWSEO_Meta::get( $id, 'noindex' ),
						'nofollow'    => MWSEO_Meta::get( $id, 'nofollow' ),
						'cornerstone' => MWSEO_Meta::get( $id, 'cornerstone' ),
						'score'       => MWSEO_Meta::get( $id, 'score' ),
					);
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);

		wp_register_ability(
			'mwseo/update-seo-meta',
			array(
				'label'               => __( 'Update SEO meta', 'manny-wenas-seo' ),
				'description'         => __( 'Sets the focus keyphrase, up to two related keyphrases, SEO title, meta description and robots flags for a post. Only supplied fields change.', 'manny-wenas-seo' ),
				'category'            => 'mwseo',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'  => array( 'type' => 'integer' ),
						'focus'    => array( 'type' => 'string' ),
						'related'  => array(
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
							'maxItems' => 2,
						),
						'title'    => array( 'type' => 'string' ),
						'desc'     => array( 'type' => 'string' ),
						'noindex'  => array( 'type' => 'boolean' ),
						'nofollow' => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'post_id' ),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => $can_edit,
				'execute_callback'    => static function ( $input ) {
					$post = get_post( (int) $input['post_id'] );
					if ( ! $post ) {
						return new WP_Error( 'mwseo_not_found', __( 'Post not found.', 'manny-wenas-seo' ) );
					}
					foreach ( array( 'focus', 'title' ) as $k ) {
						if ( isset( $input[ $k ] ) ) {
							MWSEO_Meta::set( $post->ID, $k, sanitize_text_field( $input[ $k ] ) );
						}
					}
					if ( isset( $input['desc'] ) ) {
						MWSEO_Meta::set( $post->ID, 'desc', sanitize_textarea_field( $input['desc'] ) );
					}
					if ( isset( $input['related'] ) ) {
						MWSEO_Meta::set( $post->ID, 'related', array_slice( array_filter( array_map( 'sanitize_text_field', (array) $input['related'] ) ), 0, 2 ) );
					}
					foreach ( array( 'noindex', 'nofollow' ) as $k ) {
						if ( isset( $input[ $k ] ) ) {
							MWSEO_Meta::set( $post->ID, $k, (bool) $input[ $k ] );
						}
					}
					$res = MWSEO_Rest::analyze_post( $post );
					MWSEO_Meta::set( $post->ID, 'score', (int) $res['score'] );
					return $res;
				},
				'meta'                => array( 'show_in_rest' => true ),
			)
		);

		wp_register_ability(
			'mwseo/list-low-scoring-posts',
			array(
				'label'               => __( 'List low-scoring posts', 'manny-wenas-seo' ),
				'description'         => __( 'Lists published posts and pages whose stored SEO score is below a threshold, lowest first.', 'manny-wenas-seo' ),
				'category'            => 'mwseo',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'threshold' => array(
							'type'    => 'integer',
							'default' => 60,
						),
						'limit'     => array(
							'type'    => 'integer',
							'default' => 20,
						),
					),
				),
				'output_schema'       => array( 'type' => 'array' ),
				'permission_callback' => static function () {
					// Intentionally broad: editors (edit_others_posts) and administrators (manage_options) may list scores across all authors and post types.
					return current_user_can( 'edit_others_posts' ) || current_user_can( 'manage_options' );
				},
				'execute_callback'    => static function ( $input ) {
					$threshold = isset( $input['threshold'] ) ? (int) $input['threshold'] : 60;
					$limit     = isset( $input['limit'] ) ? min( 100, max( 1, (int) $input['limit'] ) ) : 20;
					$q         = new WP_Query(
						array(
							'post_type'      => MWSEO_Options::get( 'post_types' ),
							'post_status'    => 'publish',
							'posts_per_page' => $limit,
							'meta_key'       => '_mwseo_score', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
							'meta_value_num' => $threshold, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
							'meta_compare'   => '<',
							'meta_type'      => 'NUMERIC',
							'orderby'        => 'meta_value_num',
							'order'          => 'ASC',
						)
					);
					$out = array();
					foreach ( $q->posts as $p ) {
						$out[] = array(
							'post_id' => $p->ID,
							'title'   => get_the_title( $p ),
							'url'     => get_permalink( $p ),
							'score'   => MWSEO_Meta::get( $p->ID, 'score' ),
						);
					}
					return $out;
				},
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			)
		);
	}
}
