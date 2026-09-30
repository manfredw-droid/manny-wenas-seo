<?php
/**
 * Post meta registry and accessors.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Registers and reads per-post SEO meta.
 */
class MWSEO_Meta {

	/**
	 * Field definitions: key => [ type, yoast fallback key ].
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'focus'       => array( 'string', '_yoast_wpseo_focuskw' ),
			'related'     => array( 'array', '' ),
			'title'       => array( 'string', '_yoast_wpseo_title' ),
			'desc'        => array( 'string', '_yoast_wpseo_metadesc' ),
			'noindex'     => array( 'boolean', '' ),
			'nofollow'    => array( 'boolean', '' ),
			'cornerstone' => array( 'boolean', '_yoast_wpseo_is_cornerstone' ),
			'score'       => array( 'integer', '' ),
			'faq'         => array( 'string', '' ),
			'video'       => array( 'string', '' ),
			'review'      => array( 'array', '' ),
		);
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'flush_caches' ) );
	}

	/**
	 * Register meta keys so they are available in REST and to the Abilities API.
	 */
	public static function register() {
		foreach ( MWSEO_Options::get( 'post_types', array( 'post', 'page' ) ) as $post_type ) {
			foreach ( self::fields() as $key => $def ) {
				$args = array(
					'single'        => true,
					'type'          => $def[0],
					'auth_callback' => static function () {
						return current_user_can( 'edit_posts' );
					},
				);
				if ( 'array' === $def[0] ) {
					$args['show_in_rest'] = array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					);
				} else {
					$args['show_in_rest'] = true;
				}
				register_post_meta( $post_type, '_mwseo_' . $key, $args );
			}
		}
	}

	/**
	 * Read a field, falling back to Yoast's value when ours is empty.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key (without prefix).
	 * @return mixed
	 */
	public static function get( $post_id, $key ) {
		$fields = self::fields();
		$def    = isset( $fields[ $key ] ) ? $fields[ $key ] : array( 'string', '' );
		$value  = get_post_meta( $post_id, '_mwseo_' . $key, true );

		if ( ( '' === $value || array() === $value || false === $value ) && $def[1] ) {
			$value = get_post_meta( $post_id, $def[1], true );
		}
		if ( 'array' === $def[0] ) {
			return is_array( $value ) ? array_values( array_filter( $value ) ) : array();
		}
		if ( 'boolean' === $def[0] ) {
			return in_array( (string) $value, array( '1', 'true' ), true );
		}
		if ( 'integer' === $def[0] ) {
			return (int) $value;
		}
		return (string) $value;
	}

	/**
	 * Write a field.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key (without prefix).
	 * @param mixed  $value   Sanitized value.
	 */
	public static function set( $post_id, $key, $value ) {
		if ( '' === $value || array() === $value || false === $value || null === $value ) {
			delete_post_meta( $post_id, '_mwseo_' . $key );
			return;
		}
		update_post_meta( $post_id, '_mwseo_' . $key, true === $value ? '1' : $value );
	}

	/**
	 * Drop cached sitemap data when content changes.
	 */
	public static function flush_caches() {
		delete_transient( 'mwseo_sitemap_cache' );
	}
}
