<?php
defined( 'ABSPATH' ) || exit;
/**
 * Editor companion: a docked, unobtrusive view of the existing analysis.
 *
 * The companion owns no analysis, no metadata and no slug state. It only
 * (a) renders the result the metabox script already receives from the scorer
 * and (b) lets the writer jump to the article's own headings and to the
 * existing SEO fields. Nothing here writes to the post.
 *
 * - Block editor: a native PluginSidebar (pinned Manny icon in the editor
 *   toolbar). It lives in WordPress's own sidebar region, so it can never sit
 *   on top of the writing canvas.
 * - Classic Editor: a compact box in the native side column.
 *
 * @package MannyWenasSEO
 */

/**
 * Registers the companion for the block editor and the Classic Editor.
 */
class MWSEO_Companion {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'block_assets' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ), 5, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'classic_assets' ) );
	}

	/**
	 * Is the companion wanted on this post type for this user?
	 *
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private static function enabled_for( $post_type ) {
		return in_array( $post_type, (array) MWSEO_Options::get( 'post_types' ), true )
			&& current_user_can( 'edit_posts' );
	}

	/**
	 * Strings shared by both editors.
	 *
	 * @return array
	 */
	private static function strings() {
		return array(
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
			'fieldHidden'    => __( 'That field is hidden. Show the Manny Wenas SEO box in the editor’s panel settings.', 'manny-wenas-seo' ),
			'slugHint'       => __( 'URL control focused. Press Enter to edit the slug in WordPress.', 'manny-wenas-seo' ),
			'slugOpened'     => __( 'Opened the WordPress post settings. The URL control is under Summary.', 'manny-wenas-seo' ),
			'slugMissing'    => __( 'WordPress shows an editable permalink only once the post has a title and the site uses pretty permalinks.', 'manny-wenas-seo' ),
			'noIntro'        => __( 'There is no paragraph to go to yet.', 'manny-wenas-seo' ),
			'autoHide'       => __( 'Hide while scrolling down', 'manny-wenas-seo' ),
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
	 * Shared config passed to both scripts.
	 *
	 * @return array
	 */
	private static function config() {
		return array(
			'logo'      => MWSEO_URL . 'assets/images/mwseo-logo.svg',
			'ownsTitle' => (bool) MWSEO_Compat::owns_title_fields(),
			'i18n'      => self::strings(),
		);
	}

	/**
	 * Block editor: enqueue the sidebar script on the post editor only.
	 */
	public static function block_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ! self::enabled_for( $screen->post_type ) ) {
			return;
		}
		wp_enqueue_style( 'mwseo-companion', MWSEO_URL . 'assets/css/companion.css', array(), MWSEO_VERSION );
		wp_enqueue_script( 'mwseo-companion-core', MWSEO_URL . 'assets/js/companion-core.js', array( 'jquery' ), MWSEO_VERSION, true );
		wp_enqueue_script(
			'mwseo-companion-block',
			MWSEO_URL . 'assets/js/companion-block.js',
			array( 'mwseo-companion-core', 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-primitives' ),
			MWSEO_VERSION,
			true
		);
		wp_add_inline_script( 'mwseo-companion-core', 'window.mwseoCompanionCfg = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Is the block editor handling this post?
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	private static function is_block_editor( $post ) {
		return function_exists( 'use_block_editor_for_post' ) && use_block_editor_for_post( $post );
	}

	/**
	 * Classic Editor: register a compact box at the end of the side column. It is last so that WordPress's own side-column scrolling can never slide it over another box.
	 *
	 * @param string  $post_type Post type.
	 * @param WP_Post $post      Post.
	 */
	public static function register( $post_type, $post = null ) {
		if ( ! self::enabled_for( $post_type ) || ! $post instanceof WP_Post || self::is_block_editor( $post ) ) {
			return;
		}
		add_meta_box( 'mwseo-companion', __( 'Manny Wenas SEO Companion', 'manny-wenas-seo' ), array( __CLASS__, 'render' ), $post_type, 'side', 'low' );
	}

	/**
	 * Classic Editor: enqueue assets.
	 *
	 * @param string $hook Admin hook suffix.
	 */
	public static function classic_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		$post   = get_post();
		if ( ! $screen || ! $post || ! self::enabled_for( $screen->post_type ) || self::is_block_editor( $post ) ) {
			return;
		}
		wp_enqueue_style( 'mwseo-companion', MWSEO_URL . 'assets/css/companion.css', array(), MWSEO_VERSION );
		wp_enqueue_script( 'mwseo-companion-core', MWSEO_URL . 'assets/js/companion-core.js', array( 'jquery' ), MWSEO_VERSION, true );
		wp_enqueue_script( 'mwseo-companion-classic', MWSEO_URL . 'assets/js/companion-classic.js', array( 'mwseo-companion-core', 'jquery' ), MWSEO_VERSION, true );
		wp_add_inline_script( 'mwseo-companion-core', 'window.mwseoCompanionCfg = ' . wp_json_encode( self::config() ) . ';', 'before' );
	}

	/**
	 * Render the Classic Editor box. The script fills the dynamic parts.
	 */
	public static function render() {
		$s = self::strings();
		?>
		<div class="mwseo-cmp mwseo-cmp--classic" id="mwseo-cmp">
			<div class="mwseo-cmp__head">
				<img class="mwseo-cmp__logo" src="<?php echo esc_url( MWSEO_URL . 'assets/images/mwseo-logo.svg' ); ?>" alt="" width="32" height="32">
				<div class="mwseo-ring mwseo-cmp__ring" id="mwseo-cmp-ring" data-state="none" role="img" aria-label="<?php echo esc_attr( $s['score'] ); ?>"><span id="mwseo-cmp-ring-num">–</span></div>
				<strong class="mwseo-cmp__verdict" id="mwseo-cmp-verdict"><?php echo esc_html( $s['analysing'] ); ?></strong>
			</div>
			<div class="mwseo-cmp__body" id="mwseo-cmp-body">
				<div class="mwseo-cmp__section">
					<h4><?php echo esc_html( $s['fields'] ); ?></h4>
					<div class="mwseo-cmp__quick" id="mwseo-cmp-quick"></div>
				</div>
				<div class="mwseo-cmp__section">
					<h4 id="mwseo-cmp-headings-label"><?php echo esc_html( $s['headings'] ); ?></h4>
					<ul class="mwseo-cmp__headings" id="mwseo-cmp-headings" aria-labelledby="mwseo-cmp-headings-label"></ul>
				</div>
				<div class="mwseo-cmp__section">
					<h4><?php echo esc_html( $s['checks'] ); ?></h4>
					<ul class="mwseo-cmp__checks" id="mwseo-cmp-checks"></ul>
				</div>
				<p class="mwseo-cmp__option">
					<label><input type="checkbox" id="mwseo-cmp-autohide"> <?php echo esc_html( $s['autoHide'] ); ?></label>
				</p>
				<p class="mwseo-cmp__status description" id="mwseo-cmp-status" role="status"></p>
			</div>
		</div>
		<?php
	}
}
