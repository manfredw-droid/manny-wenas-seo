<?php
defined( 'ABSPATH' ) || exit;
/**
 * Post meta registry and accessors.
 *
 * @package MannyWenasSEO
 */

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
	 * Values that read as "true" for a boolean flag, in our own key and in the
	 * keys of other SEO plugins.
	 */
	const BOOLEAN_TRUE = array( '1', 'true', 'on' );

	/**
	 * Cheap candidate filter for cornerstone queries: posts where any of the three
	 * flag keys holds a true-looking value. Every post for which get( $id,
	 * 'cornerstone' ) is true qualifies, so it is a superset; WordPress shares one
	 * postmeta join between OR clauses that use IN. The exact rule is applied by
	 * limit_to_cornerstone_sql() once a query carries mwseo_effective_cornerstone.
	 *
	 * @return array
	 */
	public static function cornerstone_candidate_meta_query() {
		$yes = self::BOOLEAN_TRUE;
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_mwseo_cornerstone',
				'value'   => $yes,
				'compare' => 'IN',
			),
			array(
				'key'     => '_yoast_wpseo_is_cornerstone',
				'value'   => $yes,
				'compare' => 'IN',
			),
			array(
				'key'     => 'rank_math_pillar_content',
				'value'   => $yes,
				'compare' => 'IN',
			),
		);
	}

	/**
	 * SQL condition that is true exactly when get( $id, 'cornerstone' ) is true.
	 *
	 * get() rules, mirrored here: if our own key exists it alone decides, so an
	 * explicit opt-out (a saved empty value or 0) is respected even when an old
	 * Yoast SEO or Rank Math flag is still present. Only when our key is absent
	 * does the fallback apply: Yoast's value if it has one, otherwise Rank Math's.
	 *
	 * Details that keep the SQL identical to get():
	 * - get_post_meta() reads the first row (lowest meta_id) of a key, so every
	 *   value test looks at that row only, even when a key has duplicate rows.
	 * - Keys are matched exactly as PHP does (case-sensitive), although the
	 *   database compares them case-insensitively: the plain equality uses the
	 *   index, the BINARY equality makes it exact.
	 * - Values are compared case-sensitively and without trailing-space padding.
	 *
	 * Contract: values as the three plugins write them (plain strings, including
	 * NULL and empty). PHP-serialized values (for example "b:1;") are not
	 * interpreted: get() unserializes them, this SQL sees plain text. No plugin
	 * here writes such values (WordPress stores booleans as "1" or "").
	 *
	 * Every test is a subquery bounded to one key, so it adds no join and cannot
	 * multiply rows.
	 *
	 * @param string $id_sql Post ID column, for example "wp_posts.ID".
	 * @return string
	 */
	public static function cornerstone_where_sql( $id_sql ) {
		global $wpdb;
		$yes = self::BOOLEAN_TRUE;
		$in  = implode( ', ', array_fill( 0, count( $yes ), '%s' ) );
		/*
		 * One "key has a (first) row that satisfies $extra" test. $extra may use
		 * the alias; $args are its placeholder values.
		 */
		$has = static function ( $alias, $key, $extra = '', array $args = array(), $first_row = true ) use ( $wpdb, $id_sql ) {
			$earlier = $first_row
				? " AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS {$alias}e WHERE {$alias}e.post_id = {$alias}.post_id AND {$alias}e.meta_key = %s AND BINARY {$alias}e.meta_key = %s AND {$alias}e.meta_id < {$alias}.meta_id )"
				: '';
			$params  = array_merge( array( $key, $key ), $args, $first_row ? array( $key, $key ) : array() );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table, alias and column names are fixed or come from WP_Query; values are placeholders.
			return $wpdb->prepare( "EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS {$alias} WHERE {$alias}.post_id = {$id_sql} AND {$alias}.meta_key = %s AND BINARY {$alias}.meta_key = %s{$extra}{$earlier} )", $params );
		};
		$own      = '_mwseo_cornerstone';
		$yoast    = '_yoast_wpseo_is_cornerstone';
		$rank     = 'rank_math_pillar_content';
		$own_yes  = $has( 'mwseo_o1', $own, " AND BINARY mwseo_o1.meta_value IN ( {$in} )", $yes );
		$own_any  = $has( 'mwseo_o2', $own, '', array(), false );
		$yoast_ok = $has( 'mwseo_y1', $yoast, " AND BINARY mwseo_y1.meta_value IN ( {$in} )", $yes );
		$yoast_on = $has( 'mwseo_y2', $yoast, " AND mwseo_y2.meta_value <> ''" );
		$rank_ok  = $has( 'mwseo_r1', $rank, " AND BINARY mwseo_r1.meta_value IN ( {$in} )", $yes );
		return "( {$own_yes} OR ( NOT {$own_any} AND ( {$yoast_ok} OR ( NOT {$yoast_on} AND {$rank_ok} ) ) ) )";
	}

	/**
	 * Apply the exact cornerstone rule to queries that ask for it.
	 *
	 * Hooked on get_meta_sql, like the noindex exclusion of llms.txt, because that
	 * filter still runs for get_posts() calls, which suppress other query filters.
	 * Only queries with the mwseo_effective_cornerstone query variable (and a meta
	 * query, which is what makes WordPress build meta SQL) are touched.
	 *
	 * @param array  $sql               Meta SQL parts (join, where).
	 * @param array  $queries           Meta queries.
	 * @param string $type              Meta type.
	 * @param string $primary_table     Primary table.
	 * @param string $primary_id_column Primary ID column.
	 * @param mixed  $context           The WP_Query being built.
	 * @return array
	 */
	public static function limit_to_cornerstone_sql( $sql, $queries, $type, $primary_table, $primary_id_column, $context = null ) {
		if ( 'post' !== $type || ! $context instanceof WP_Query || ! $context->get( 'mwseo_effective_cornerstone' ) || ! is_array( $sql ) ) {
			return $sql;
		}
		$sql['where'] = ( isset( $sql['where'] ) ? $sql['where'] : '' ) . ' AND ' . self::cornerstone_where_sql( esc_sql( $primary_table ) . '.' . esc_sql( $primary_id_column ) );
		return $sql;
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'save_post', array( __CLASS__, 'flush_caches' ) );
		add_filter( 'get_meta_sql', array( __CLASS__, 'limit_to_cornerstone_sql' ), 10, 6 );
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
				if ( 'score' !== $key ) {
					$args['default'] = 'array' === $def[0] ? array() : ( 'boolean' === $def[0] ? false : '' );
					$args['revisions_enabled'] = true;
					$args['sanitize_callback'] = static function ( $value ) use ( $key, $def ) {
						if ( 'array' === $def[0] ) {
							return array_values( array_slice( array_filter( array_map( 'sanitize_text_field', (array) $value ), 'strlen' ), 0, 2 ) );
						}
						if ( 'boolean' === $def[0] ) {
							return rest_sanitize_boolean( $value );
						}
						return 'desc' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
					};
				}
				if ( 'array' === $def[0] ) {
					$args['show_in_rest'] = array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					);
				} elseif ( 'score' === $key ) {
					// Derived snapshots must not be echoed back as editable entity metadata.
					// Keep explicit REST score operations available to existing API clients.
					$args['show_in_rest'] = array( 'schema' => array( 'context' => array( 'view' ) ) );
				} else {
					$args['show_in_rest'] = true;
				}
				register_post_meta( $post_type, '_mwseo_' . $key, $args );
			}
			add_filter(
				"rest_prepare_{$post_type}",
				array( __CLASS__, 'protect_rest_meta' ),
				10,
				3
			);
		}
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'preflight_rest_meta' ), 10, 4 );
	}

	/**
	 * Reject unsafe native null resets before a core post update is dispatched.
	 *
	 * Autosaves do not apply the ordinary post metadata reset operation.
	 * Never return an error through their shared prepared-post filter.
	 *
	 * @param mixed           $dispatch_result Earlier dispatch result, or null.
	 * @param WP_REST_Request $request         REST request.
	 * @param string          $route           Matched REST route.
	 * @param array           $handler         Matched endpoint handler.
	 * @return mixed
	 */
	public static function preflight_rest_meta( $dispatch_result, $request, $route, $handler ) {
		if ( null !== $dispatch_result ) {
			return $dispatch_result;
		}
		$callback = isset( $handler['callback'] ) ? $handler['callback'] : null;
		if ( ! is_array( $callback ) || ! isset( $callback[0], $callback[1] ) ||
			! is_object( $callback[0] ) || 'WP_REST_Posts_Controller' !== get_class( $callback[0] ) ||
			'update_item' !== $callback[1] ) {
			return $dispatch_result;
		}
		$post_id = (int) $request->get_param( 'id' );
		$meta    = $request->get_param( 'meta' );
		if ( ! $post_id || ! is_array( $meta ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $dispatch_result;
		}
		$post_type = get_post_type( $post_id );
		$type      = get_post_type_object( $post_type );
		if ( ! in_array( $post_type, MWSEO_Options::get( 'post_types', array( 'post', 'page' ) ), true ) ||
			! $type || $type->get_rest_controller() !== $callback[0] ) {
			return $dispatch_result;
		}

		$schema = null;
		foreach ( self::fields() as $key => $definition ) {
			$meta_key = '_mwseo_' . $key;
			if ( ! array_key_exists( $meta_key, $meta ) || null !== $meta[ $meta_key ] ) {
				continue;
			}
			if ( null === $schema ) {
				$fields = new WP_REST_Post_Meta_Fields( get_post_type( $post_id ) );
				$schema = $fields->get_field_schema();
			}
			if ( isset( $schema['properties'][ $meta_key ] ) && is_wp_error(
				rest_validate_value_from_schema( get_post_meta( $post_id, $meta_key, true ), $schema['properties'][ $meta_key ] )
			) ) {
				return new WP_Error(
					'mwseo_invalid_null_reset',
					__( 'A requested metadata reset cannot be completed with the current stored value. The request was rejected before saving.', 'manny-wenas-seo' ),
					array( 'status' => 409 )
				);
			}
		}
		return $dispatch_result;
	}

	/**
	 * Restrict editorial metadata to users who can edit this post.
	 *
	 * @param WP_REST_Response $response Prepared response.
	 * @param WP_Post          $post     Response post.
	 * @param WP_REST_Request  $request  Request context.
	 * @return WP_REST_Response
	 */
	public static function protect_rest_meta( $response, $post, $request = null ) {
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			// Expose effective legacy fallbacks in editor state without creating stored
			// defaults on read. A subsequent explicit empty value stays authoritative.
			if ( $request && 'edit' === $request->get_param( 'context' ) ) {
				$data = $response->get_data();
				foreach ( self::fields() as $key => $definition ) {
					$meta_key = '_mwseo_' . $key;
					if ( 'score' !== $key && isset( $data['meta'] ) && array_key_exists( $meta_key, $data['meta'] ) &&
						! metadata_exists( 'post', $post->ID, $meta_key ) ) {
						$data['meta'][ $meta_key ] = self::get( $post->ID, $key );
					}
				}
				$response->set_data( $data );
			}
			return $response;
		}

		$data = $response->get_data();
		if ( is_array( $data ) && isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( array( 'focus', 'related', 'score' ) as $key ) {
				unset( $data['meta'][ '_mwseo_' . $key ] );
			}
			$response->set_data( $data );
		}
		return $response;
	}

	/**
	 * Read a field, falling back to another SEO plugin only when ours is absent.
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
		} elseif ( ! metadata_exists( 'post', $post_id, '_mwseo_' . $key ) ) {
			// Only absent keys fall back; an explicit native empty/false must remain cleared.
			$value = self::from_other_plugins( $post_id, $key, $def, array( 1, 2 ) );
		}
		if ( 'array' === $def[0] ) {
			return is_array( $value ) ? array_values( array_filter( $value ) ) : array();
		}
		if ( 'boolean' === $def[0] ) {
			return in_array( (string) $value, self::BOOLEAN_TRUE, true );
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
