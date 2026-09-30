<?php
/**
 * Virtual robots.txt management.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Appends custom rules and sitemap/llms references to WordPress's virtual robots.txt.
 *
 * Note: a physical robots.txt file in the web root overrides this output.
 */
class MWSEO_Robots_Txt {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter' ), 20, 2 );
	}

	/**
	 * Filter robots.txt output.
	 *
	 * @param string $output Existing output.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public static function filter( $output, $public ) {
		if ( ! $public ) {
			return $output;
		}
		$custom = trim( (string) MWSEO_Options::get( 'robots_custom' ) );
		if ( $custom ) {
			$output = rtrim( $output ) . "\n\n" . $custom . "\n";
		}
		if ( MWSEO_Options::get( 'robots_add_sitemap' ) && ! MWSEO_Yoast::is_active() ) {
			$line = 'Sitemap: ' . MWSEO_Sitemaps::index_url();
			if ( false === strpos( $output, $line ) ) {
				$output = rtrim( $output ) . "\n\n" . $line . "\n";
			}
		}
		return $output;
	}
}
