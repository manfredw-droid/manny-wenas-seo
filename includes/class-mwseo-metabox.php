<?php
defined( 'ABSPATH' ) || exit;
/** Score columns and the notice for unsupported editors. @package MannyWenasSEO */
class MWSEO_Metabox {
	/** Register shared administration hooks; there is no metabox or POST writer. */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'editor_notice' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_editor_notice' ) );
		add_filter( 'manage_posts_columns', array( __CLASS__, 'column' ) );
		add_filter( 'manage_pages_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'manage_pages_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		// Cornerstone overview: a column and an "Only cornerstone" filter on the lists of the configured post types.
		add_action( 'admin_init', array( __CLASS__, 'register_cornerstone_list' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'cornerstone_filter' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_cornerstone' ) );
	}

	/** Add the Cornerstone column to the list of every configured post type. */
	public static function register_cornerstone_list() {
		foreach ( (array) MWSEO_Options::get( 'post_types' ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'cornerstone_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'cornerstone_column_value' ), 10, 2 );
		}
	}

	/** Reuse the same translated explanation in both editors. */
	private static function custom_fields_message() {
		return __( 'Manny Wenas SEO controls are unavailable because this post type does not support custom-fields. Your saved SEO data and frontend output continue to work.', 'manny-wenas-seo' );
	}

	/** Show only a native notice when Gutenberg cannot expose SEO metadata. */
	public static function block_editor_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() ||
			! in_array( $screen->post_type, (array) MWSEO_Options::get( 'post_types' ), true ) ||
			post_type_supports( $screen->post_type, 'custom-fields' ) ) {
			return;
		}
		wp_enqueue_script( 'wp-notices' );
		wp_add_inline_script(
			'wp-notices',
			'wp.data.dispatch("core/notices").createNotice("info", ' . wp_json_encode( self::custom_fields_message() ) . ', { id: "mwseo-custom-fields", isDismissible: false });',
			'after'
		);
	}

	/** Explain missing requirements on configured Classic edit screens. */
	public static function editor_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'post' !== $screen->base || $screen->is_block_editor() ||
			! in_array( $screen->post_type, (array) MWSEO_Options::get( 'post_types' ), true ) ) {
			return;
		}
		if ( ! post_type_supports( $screen->post_type, 'custom-fields' ) ) {
			$message = self::custom_fields_message();
		} else {
			$message = __( 'From version 1.0.7, Manny Wenas SEO requires the block editor for SEO controls. Your saved SEO data and frontend output continue to work.', 'manny-wenas-seo' );
		}
		echo '<div class="notice notice-info"><p>' . esc_html( $message ) . '</p></div>';
	}

	/**
	 * Add a score column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function column( $cols ) {
		$cols['mwseo_score'] = __( 'SEO', 'manny-wenas-seo' );
		return $cols;
	}

	/**
	 * Render the score column.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column_value( $col, $post_id ) {
		if ( 'mwseo_score' !== $col ) {
			return;
		}
		$score = MWSEO_Meta::get( $post_id, 'score' );
		echo $score ? esc_html( $score . '/100' ) : '&ndash;';
	}

	/**
	 * Add the Cornerstone column.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function cornerstone_column( $cols ) {
		$cols['mwseo_cornerstone'] = __( 'Cornerstone', 'manny-wenas-seo' );
		return $cols;
	}

	/**
	 * Render the Cornerstone column from the existing cornerstone flag.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function cornerstone_column_value( $col, $post_id ) {
		if ( 'mwseo_cornerstone' !== $col ) {
			return;
		}
		if ( MWSEO_Meta::get( $post_id, 'cornerstone' ) ) {
			echo '<span class="dashicons dashicons-star-filled" aria-hidden="true"></span> ' . esc_html__( 'Cornerstone', 'manny-wenas-seo' );
		} else {
			echo '<span aria-hidden="true">&ndash;</span><span class="screen-reader-text">' . esc_html__( 'Not cornerstone content', 'manny-wenas-seo' ) . '</span>';
		}
	}

	/**
	 * Is the "Only cornerstone" filter selected?
	 *
	 * @return bool
	 */
	private static function cornerstone_filter_active() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		return isset( $_GET['mwseo_cornerstone'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['mwseo_cornerstone'] ) );
	}

	/**
	 * Output the "Only cornerstone" filter above the list.
	 *
	 * @param string $post_type Post type of the list.
	 * @param string $which     Table navigation position.
	 */
	public static function cornerstone_filter( $post_type, $which = 'top' ) {
		if ( 'top' !== $which || ! in_array( $post_type, (array) MWSEO_Options::get( 'post_types' ), true ) ) {
			return;
		}
		echo '<label class="screen-reader-text" for="mwseo-cornerstone-filter">' . esc_html__( 'Filter by cornerstone content', 'manny-wenas-seo' ) . '</label>';
		echo '<select name="mwseo_cornerstone" id="mwseo-cornerstone-filter">';
		echo '<option value="">' . esc_html__( 'All content', 'manny-wenas-seo' ) . '</option>';
		echo '<option value="1"' . selected( self::cornerstone_filter_active(), true, false ) . '>' . esc_html__( 'Only cornerstone', 'manny-wenas-seo' ) . '</option>';
		echo '</select>';
	}

	/**
	 * Limit the admin list to cornerstone content when the filter is selected.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_cornerstone( $query ) {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || ! self::cornerstone_filter_active() ) {
			return;
		}
		$post_type = $query->get( 'post_type' );
		$post_type = '' === $post_type ? 'post' : $post_type;
		if ( ! is_string( $post_type ) || ! in_array( $post_type, (array) MWSEO_Options::get( 'post_types' ), true ) ) {
			return;
		}
		// The exact rule is applied by MWSEO_Meta::limit_to_cornerstone_sql(); the meta query is its cheap prefilter.
		$existing = $query->get( 'meta_query' );
		$clause   = MWSEO_Meta::cornerstone_candidate_meta_query();
		$query->set( 'mwseo_effective_cornerstone', 1 );
		$query->set( 'meta_query', $existing ? array( 'relation' => 'AND', $existing, $clause ) : $clause ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}
}
