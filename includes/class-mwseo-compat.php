<?php
defined( 'ABSPATH' ) || exit;
/**
 * Coexistence with other SEO plugins.
 *
 * @package MannyWenasSEO
 */

/**
 * Detects other SEO plugins that own front-end output.
 *
 * - Yoast SEO: handled by MWSEO_Yoast (we defer to it).
 * - Rank Math: we always defer. Our meta tags, schema graph and sitemaps are disabled.
 */
class MWSEO_Compat {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'rank_math_notice' ) );
	}

	/**
	 * Is Rank Math SEO active?
	 *
	 * @return bool
	 */
	public static function rank_math_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath', false );
	}

	/**
	 * Is another SEO plugin producing meta tags, schema and sitemaps?
	 *
	 * Used to suppress our own front-end output. The filter lets a site force
	 * the behaviour either way.
	 *
	 * @return bool
	 */
	public static function other_active() {
		return (bool) apply_filters( 'mwseo_other_seo_active', MWSEO_Yoast::is_active() || self::rank_math_active() );
	}

	/**
	 * Does another plugin own the SEO title and meta description fields?
	 *
	 * @return bool
	 */
	public static function owns_title_fields() {
		return self::rank_math_active() || MWSEO_Yoast::defers();
	}

	/**
	 * Name of the plugin that owns the title and description fields.
	 *
	 * @return string
	 */
	public static function owner_name() {
		return self::rank_math_active() ? 'Rank Math SEO' : 'Yoast SEO';
	}

	/**
	 * Explain the current behaviour on our settings screen.
	 */
	public static function rank_math_notice() {
		$screen = get_current_screen();
		if ( ! self::rank_math_active() || ! $screen || false === strpos( $screen->id, 'mwseo' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Rank Math SEO is active. Manny Wenas SEO is deferring to Rank Math for meta tags, schema and sitemaps.', 'manny-wenas-seo' ) . '</p></div>';
	}
}
