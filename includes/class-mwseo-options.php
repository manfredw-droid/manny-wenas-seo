<?php
/**
 * Plugin options accessor.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single-array options store.
 */
class MWSEO_Options {

	const KEY = 'mwseo_options';

	/**
	 * Request-level cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'title_template'     => '%%title%% %%sep%% %%sitename%%',
			'title_separator'    => '-',
			'yoast_mode'         => 'stitch',
			'post_types'         => array( 'post', 'page' ),
			'site_represents'    => 'organization',
			'org_name'           => get_bloginfo( 'name' ),
			'org_logo'           => 0,
			'person_user'        => 0,
			'social_profiles'    => '',
			'default_image'      => 0,
			'twitter_site'       => '',
			'fb_app_id'          => '',
			'article_type'       => 'Article',
			'sitemap_news'       => 1,
			'sitemap_images'     => 1,
			'sitemap_videos'     => 1,
			'sitemap_categories' => 1,
			'sitemap_tags'       => 1,
			'sitemap_authors'    => 1,
			'sitemap_html'       => 1,
			'news_publication'   => get_bloginfo( 'name' ),
			'robots_custom'      => '',
			'robots_add_sitemap' => 1,
			'llms_enabled'       => 1,
			'llms_intro'         => '',
			'gsc_client_id'      => '',
			'gsc_client_secret'  => '',
			'gsc_property'       => '',
			'ai_provider'        => 'anthropic',
			'ai_api_key'         => '',
			'ai_model'           => 'claude-haiku-4-5-20251001',
			'report_enabled'     => 1,
			'report_email'       => '',
			'sitemap_autosubmit' => 1,
			'indexnow_key'       => '',
			'gsc_html_filename'  => '',
			'bing_verification_key' => '',
		);
	}

	/**
	 * All options merged over defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::KEY, array() );
			self::$cache = wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
		}
		return self::$cache;
	}

	/**
	 * Get one option.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $fallback Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Persist options and reset the cache.
	 *
	 * @param array $values Full options array.
	 */
	public static function save( array $values ) {
		update_option( self::KEY, $values, false );
		self::flush();
	}

	/**
	 * Reset the request-level cache.
	 */
	public static function flush() {
		self::$cache = null;
	}
}
