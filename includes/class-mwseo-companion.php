<?php
defined( 'ABSPATH' ) || exit;
/**
 * Editor companion: a docked, unobtrusive view of the existing analysis.
 *
 * Native fields use WordPress editor metadata. Analysis remains server-side.
 *
 * @package MannyWenasSEO
 */

/**
 * Registers native SEO fields and analysis in the block editor.
 */
class MWSEO_Companion {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_assets' ) );
	}

	/**
	 * Is the companion wanted on this post type for this user?
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function enabled_for( $post_type ) {
		return in_array( $post_type, (array) MWSEO_Options::get( 'post_types' ), true )
			&& ( ! get_post() || current_user_can( 'edit_post', get_post()->ID ) );
	}

	/**
	 * Translated editor strings.
	 *
	 * @return array
	 */
	private static function strings() {
		return array(
			/* translators: %d: number */
			'relatedOne'     => sprintf( __( 'Related keyphrase %d (optional)', 'manny-wenas-seo' ), 1 ),
			/* translators: %d: number */
			'relatedTwo'     => sprintf( __( 'Related keyphrase %d (optional)', 'manny-wenas-seo' ), 2 ),
			'matching'       => __( 'Matching is semantic: word order and common variants (plurals, verb forms) count.', 'manny-wenas-seo' ),
			'noindex'        => __( 'noindex: keep this out of search results', 'manny-wenas-seo' ),
			'nofollow'       => __( 'nofollow: do not follow links on this page', 'manny-wenas-seo' ),
			'cornerstone'    => __( 'Anchor post (prioritised in llms.txt)', 'manny-wenas-seo' ),
			'visibility'     => __( 'Search engine visibility', 'manny-wenas-seo' ),
			'noData'         => __( 'No Search Console data for this URL yet.', 'manny-wenas-seo' ),
			'notConnected'   => __( 'Search Console is not connected. Go to Manny Wenas SEO → Search Console to connect.', 'manny-wenas-seo' ),
			'chars'          => __( 'characters', 'manny-wenas-seo' ),
			'use'            => __( 'Use', 'manny-wenas-seo' ),
			'error'          => __( 'Something went wrong.', 'manny-wenas-seo' ),
			'suggest'        => __( 'Suggest from Search Console', 'manny-wenas-seo' ),
			'gsc'            => __( 'Search Console queries (last 28 days)', 'manny-wenas-seo' ),
			'trends'         => __( 'Trends (7 days)', 'manny-wenas-seo' ),
			'average'        => __( 'Average', 'manny-wenas-seo' ),
			'query'          => __( 'Query', 'manny-wenas-seo' ),
			'clicks'         => __( 'Clicks', 'manny-wenas-seo' ),
			'impressions'    => __( 'Impressions', 'manny-wenas-seo' ),
			'position'       => __( 'Position', 'manny-wenas-seo' ),
			'fieldsTitle'    => __( 'SEO fields', 'manny-wenas-seo' ),
			'loading'        => __( 'Loading SEO fields…', 'manny-wenas-seo' ),
			'title'          => __( 'Manny Wenas SEO', 'manny-wenas-seo' ),
			'analysing'      => __( 'Analysing…', 'manny-wenas-seo' ),
			'na'             => __( 'N/A', 'manny-wenas-seo' ),
			'headings'       => __( 'Headings', 'manny-wenas-seo' ),
			'noHeadings'     => __( 'No H2 or H3 headings yet.', 'manny-wenas-seo' ),
			'jump'           => __( 'Go to:', 'manny-wenas-seo' ),
			'fields'         => __( 'Go to', 'manny-wenas-seo' ),
			'checks'         => __( 'Analysis', 'manny-wenas-seo' ),
			'keyphrase'      => __( 'Focus keyphrase', 'manny-wenas-seo' ),
			'seoTitle'       => __( 'SEO title', 'manny-wenas-seo' ),
			'metaDesc'       => __( 'Meta description', 'manny-wenas-seo' ),
			'slug'           => __( 'URL slug', 'manny-wenas-seo' ),
			'related'        => __( 'Related keyphrases', 'manny-wenas-seo' ),
			'intro'          => __( 'Opening paragraph', 'manny-wenas-seo' ),
			'showHeadings'   => __( 'Headings', 'manny-wenas-seo' ),
			'fieldMissing'   => __( 'That field is not available on this screen.', 'manny-wenas-seo' ),
			'fieldHidden'    => __( 'Open the SEO fields panel to use this field.', 'manny-wenas-seo' ),
			'slugHint'       => __( 'URL control focused. Press Enter to edit the slug in WordPress.', 'manny-wenas-seo' ),
			'slugOpened'     => __( 'Opened the WordPress post settings. The URL control is under Summary.', 'manny-wenas-seo' ),
			'slugMissing'    => __( 'WordPress shows an editable permalink only once the post has a title and the site uses pretty permalinks.', 'manny-wenas-seo' ),
			'noIntro'        => __( 'There is no paragraph to go to yet.', 'manny-wenas-seo' ),
			'score'          => __( 'SEO score', 'manny-wenas-seo' ),
			/* translators: %s: heading text */
			'goToHeading'    => __( 'Go to heading: %s', 'manny-wenas-seo' ),
			'untitled'       => __( '(empty heading)', 'manny-wenas-seo' ),
			'level2'         => __( 'H2', 'manny-wenas-seo' ),
			'level3'         => __( 'H3', 'manny-wenas-seo' ),
			'openSidebar'    => __( 'Open the Manny Wenas SEO companion', 'manny-wenas-seo' ),
			'ownedElsewhere' => __( 'Title and description are managed by another SEO plugin.', 'manny-wenas-seo' ),
		);
	}

	/**
	 * Configuration for the native editor.
	 *
	 * @return array
	 */
	private static function config() {
		return array(
			'logo'           => MWSEO_URL . 'assets/images/mwseo-logo.svg',
			'ownsTitle'      => (bool) MWSEO_Compat::owns_title_fields(),
			'postId'         => get_the_ID(),
			'hasGsc'         => MWSEO_Gsc::is_connected(),
			'ajax'           => MWSEO_Options::get( 'trends_enabled' ) ? array(
				'url' => admin_url( 'admin-ajax.php' ),
				'action' => MWSEO_Trends::ACTION,
				'nonce' => wp_create_nonce( MWSEO_Trends::ACTION ),
			) : null,
			'effectiveTitle' => array( 'title' => MWSEO_Meta::get( get_the_ID(), 'title' ), 'desc' => MWSEO_Meta::get( get_the_ID(), 'desc' ) ),
			'i18n'           => self::strings(),
		);
	}

	/**
	 * Block editor: enqueue the sidebar script on the post editor only.
	 */
	public static function block_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! $screen->is_block_editor() || ! self::enabled_for( $screen->post_type ) ) {
			return;
		}
		wp_enqueue_style( 'mwseo-companion', MWSEO_URL . 'assets/css/companion.css', array(), MWSEO_VERSION );
		wp_enqueue_script( 'mwseo-companion-core', MWSEO_URL . 'assets/js/companion-core.js', array( 'wp-data', 'wp-api-fetch' ), MWSEO_VERSION, true );
		wp_enqueue_script(
			'mwseo-companion-block',
			MWSEO_URL . 'assets/js/companion-block.js',
			array( 'mwseo-companion-core', 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-primitives' ),
			MWSEO_VERSION,
			true
		);
		wp_add_inline_script( 'mwseo-companion-core', 'window.mwseoCompanionCfg = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

}
