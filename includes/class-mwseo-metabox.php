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
}
