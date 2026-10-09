<?php
defined( 'ABSPATH' ) || exit;
/**
 * Yoast SEO coexistence.
 *
 * @package MannyWenasSEO
 */

/**
 * When Yoast is active we defer to it: we never double-output meta, OG, sitemaps or schema.
 */
class MWSEO_Yoast {

	/**
	 * Hooks.
	 */
	public static function init() {
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
	 * Is Yoast active, so that we defer to it completely?
	 *
	 * In this state Yoast owns the SEO title and meta description.
	 *
	 * @return bool
	 */
	public static function defers() {
		return self::is_active();
	}

	/**
	 * Explain the current behaviour on our settings screen.
	 */
	public static function notice() {
		$screen = get_current_screen();
		if ( ! self::is_active() || ! $screen || false === strpos( $screen->id, 'mwseo' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'Yoast SEO is active. Manny Wenas SEO is deferring to Yoast for all front-end output.', 'manny-wenas-seo' ) . '</p></div>';
	}
}
