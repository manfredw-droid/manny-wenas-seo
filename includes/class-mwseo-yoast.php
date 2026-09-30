<?php
/**
 * Yoast SEO coexistence.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * When Yoast is active we never double-output meta, OG, sitemaps or schema.
 *
 * Modes (setting "yoast_mode"):
 * - stitch: Yoast renders the graph; we add our advanced Pro nodes to it.
 * - defer:  we output nothing that Yoast also outputs.
 */
class MWSEO_Yoast {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'stitch' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * Is Yoast SEO active?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Add our nodes to Yoast's graph (stitch mode, Pro nodes only).
	 *
	 * @param array  $graph   Yoast graph pieces.
	 * @param object $context Yoast meta tags context.
	 * @return array
	 */
	public static function stitch( $graph, $context ) {
		if ( 'stitch' !== MWSEO_Options::get( 'yoast_mode' ) || ! MWSEO_Pro::is_active() || ! is_singular() ) {
			return $graph;
		}
		$page_id = ! empty( $context->main_schema_id ) ? $context->main_schema_id : get_permalink();
		foreach ( MWSEO_Schema::advanced_nodes( get_queried_object_id(), $page_id ) as $node ) {
			$graph[] = $node;
		}
		return $graph;
	}

	/**
	 * Explain the current behaviour on our settings screen.
	 */
	public static function notice() {
		$screen = get_current_screen();
		if ( ! self::is_active() || ! $screen || false === strpos( $screen->id, 'mwseo' ) ) {
			return;
		}
		$mode = 'stitch' === MWSEO_Options::get( 'yoast_mode' )
			? __( 'Yoast SEO is active. Yoast outputs titles, meta, Open Graph and its schema graph; Manny Wenas SEO stitches advanced Pro schema into it.', 'manny-wenas-seo' )
			: __( 'Yoast SEO is active. Manny Wenas SEO is deferring to Yoast for all front-end output.', 'manny-wenas-seo' );
		echo '<div class="notice notice-info"><p>' . esc_html( $mode ) . '</p></div>';
	}
}
