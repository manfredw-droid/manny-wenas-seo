<?php
/**
 * Post meta registry and accessors.
 *
 * @package MannyWenasSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and reads per-post SEO meta.
 */
class MWSEO_Meta {

	/**
	 * Field definitions: key => [ type, Yoast fallback key, Rank Math fallback key ].
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'focus'       => array( 'string', '_yoast_wpseo_focuskw', 'rank_math_focus_keyword' ),
			'related'     => array( 'array', '' ),
			'title'       => array( 'string', '_yoast_wpseo_title', 'rank_math_title' ),
			'desc'        => array( 'string', '_yoast_wpseo_metadesc', 'rank_math_description' ),
			'noindex'     => array( 'boolean', '' ),
			'nofollow'    => array( 'boolean', '' ),
			'cornerstone' => array( 'boolean', '_yoast_wpseo_is_cornerstone', 'rank_math_pillar_content' ),
			'score'       => array( 'integer', '' ),
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
					'auth_callback' => static function ( $allowed, $meta_key, $object_id ) {
						return current_user_can( 'edit_post', $object_id );
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

		if ( in_array( $key, array( 'title', 'desc' ), true ) && MWSEO_Compat::owns_title_fields() ) {
			// Another SEO plugin outputs these, so its value is the effective one; ours is only a fallback.
			$slots = MWSEO_Compat::rank_math_active() ? array( 2 ) : array( 1 );
			$other = self::from_other_plugins( $post_id, $key, $def, $slots );
			$value = '' !== $other ? $other : $value;
		} elseif ( '' === $value || array() === $value || false === $value ) {
			// Ours is empty: fall back to Yoast, then Rank Math.
			$value = self::from_other_plugins( $post_id, $key, $def, array( 1, 2 ) );
		}
		if ( 'array' === $def[0] ) {
			return is_array( $value ) ? array_values( array_filter( $value ) ) : array();
		}
		if ( 'boolean' === $def[0] ) {
			return in_array( (string) $value, array( '1', 'true', 'on' ), true );
		}
		if ( 'integer' === $def[0] ) {
			return (int) $value;
		}
		return (string) $value;
	}

	/**
	 * Read a value stored by another SEO plugin.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Field key (without prefix).
	 * @param array  $def     Field definition (type, Yoast key, Rank Math key).
	 * @param int[]  $slots   Definition slots to try in order (1 = Yoast, 2 = Rank Math).
	 * @return mixed Empty string when nothing is stored.
	 */
	private static function from_other_plugins( $post_id, $key, array $def, array $slots ) {
		foreach ( $slots as $slot ) {
			if ( empty( $def[ $slot ] ) ) {
				continue;
			}
			$value = get_post_meta( $post_id, $def[ $slot ], true );
			if ( '' === $value || false === $value || array() === $value ) {
				continue;
			}
			if ( 2 === $slot && 'focus' === $key && is_string( $value ) && false !== strpos( $value, ',' ) ) {
				// Rank Math stores several focus keywords comma-separated; the first is primary.
				$value = trim( strtok( $value, ',' ) );
			}
			if ( 'title' === $key && is_string( $value ) && false !== strpos( $value, '%' ) ) {
				$value = self::render_title( $value, $post_id, $slot );
			}
			return $value;
		}
		return '';
	}

	/**
	 * Did a renderer return a usable title (non-blank and without leftover variables)?
	 *
	 * @param mixed $title Rendered title.
	 * @return bool
	 */
	private static function is_rendered( $title ) {
		return is_string( $title ) && '' !== trim( $title ) && false === strpos( $title, '%' );
	}

	/**
	 * Turn a stored title template ("%title% %sep% %sitename%") into the rendered title.
	 *
	 * Falls back to the raw template when no renderer is available for this post.
	 *
	 * @param string $template Stored value containing template variables.
	 * @param int    $post_id  Post ID.
	 * @param int    $slot     Definition slot (1 = Yoast, 2 = Rank Math).
	 * @return string
	 */
	private static function render_title( $template, $post_id, $slot ) {
		// Rank Math's Paper and the document title describe the queried page, so they only apply to this post.
		$is_queried = is_singular() && (int) get_queried_object_id() === (int) $post_id;

		if ( 2 === $slot ) {
			if ( $is_queried && class_exists( 'RankMath\\Paper\\Paper' ) ) {
				$title = \RankMath\Paper\Paper::get()->get_title();
				if ( self::is_rendered( $title ) ) {
					return $title;
				}
			}
			if ( class_exists( 'RankMath\\Helper' ) && method_exists( 'RankMath\\Helper', 'replace_vars' ) ) {
				$title = \RankMath\Helper::replace_vars( $template, get_post( $post_id ) );
				if ( self::is_rendered( $title ) ) {
					return $title;
				}
			}
		} elseif ( 1 === $slot && function_exists( 'YoastSEO' ) ) {
			$yoast = YoastSEO();
			if ( isset( $yoast->meta ) && method_exists( $yoast->meta, 'for_post' ) ) {
				$meta = $yoast->meta->for_post( $post_id );
				if ( $meta && self::is_rendered( $meta->title ) ) {
					return trim( $meta->title );
				}
			}
		}

		if ( $is_queried ) {
			$title = wp_get_document_title();
			if ( self::is_rendered( $title ) ) {
				return $title;
			}
		}
		return $template;
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
