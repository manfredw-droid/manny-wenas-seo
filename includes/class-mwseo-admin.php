<?php
defined( 'ABSPATH' ) || exit;
/**
 * Shared admin shell: assets, brand lockup and left navigation for the Manny Wenas SEO screens.
 *
 * @package MannyWenasSEO
 */

/**
 * Loads admin styles and scripts on Manny Wenas SEO screens only and renders the common layout.
 */
class MWSEO_Admin {

	/**
	 * Hook suffixes of the Manny Wenas SEO admin screens.
	 *
	 * @var array<string, bool>
	 */
	private static $screens = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/**
	 * Remember the hook suffix returned by add_menu_page() / add_submenu_page().
	 *
	 * @param string|false $hook Hook suffix.
	 */
	public static function register_screen( $hook ) {
		if ( $hook ) {
			self::$screens[ $hook ] = true;
		}
	}

	/**
	 * Is this one of our screens?
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	public static function is_screen( $hook ) {
		return isset( self::$screens[ $hook ] );
	}

	/**
	 * Styles, media library and the small admin script: our screens only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( ! self::is_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'mwseo-admin', MWSEO_URL . 'assets/css/admin.css', array(), MWSEO_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'mwseo-admin', MWSEO_URL . 'assets/js/admin.js', array( 'jquery', 'media-editor' ), MWSEO_VERSION, true );
		wp_localize_script(
			'mwseo-admin',
			'mwseoAdmin',
			array(
				'i18n' => array(
					'chooseImage' => __( 'Choose image', 'manny-wenas-seo' ),
					'useImage'    => __( 'Use this image', 'manny-wenas-seo' ),
					'change'      => __( 'Change image', 'manny-wenas-seo' ),
					'select'      => __( 'Select image', 'manny-wenas-seo' ),
					'remove'      => __( 'Remove', 'manny-wenas-seo' ),
					'none'        => __( 'No image selected', 'manny-wenas-seo' ),
				),
			)
		);
	}

	/**
	 * Settings sections, in navigation order.
	 *
	 * @return array<string, string> slug => label
	 */
	public static function sections() {
		return array(
			'general'  => __( 'General', 'manny-wenas-seo' ),
			'social'   => __( 'Social', 'manny-wenas-seo' ),
			'schema'   => __( 'Schema', 'manny-wenas-seo' ),
			'sitemaps' => __( 'Sitemaps', 'manny-wenas-seo' ),
			'robots'   => __( 'Robots & llms.txt', 'manny-wenas-seo' ),
			'gsc'      => __( 'Search Console', 'manny-wenas-seo' ),
			'verify'   => __( 'Verification & IndexNow', 'manny-wenas-seo' ),
		);
	}

	/**
	 * URL of a settings section.
	 *
	 * @param string $slug Section slug.
	 * @return string
	 */
	public static function section_url( $slug ) {
		return add_query_arg(
			array(
				'page' => 'mwseo',
				'tab'  => $slug,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Open the common layout: brand lockup, navigation and the page header.
	 *
	 * @param string $current     'setup', 'import' or 'settings:<section>'.
	 * @param string $title       Page title.
	 * @param string $description Short description under the title.
	 */
	public static function shell_open( $current, $title, $description = '' ) {
		?>
		<div class="wrap mwseo-admin">
			<div class="mwseo-shell">
				<nav class="mwseo-nav" aria-label="<?php esc_attr_e( 'Manny Wenas SEO', 'manny-wenas-seo' ); ?>">
					<div class="mwseo-brand">
						<img class="mwseo-brand__mark" src="<?php echo esc_url( MWSEO_URL . 'assets/images/mwseo-logo.svg' ); ?>" alt="" width="44" height="44">
						<div class="mwseo-brand__text">
							<strong class="mwseo-brand__name"><?php esc_html_e( 'Manny Wenas SEO', 'manny-wenas-seo' ); ?></strong>
							<span class="mwseo-brand__version">v<?php echo esc_html( MWSEO_VERSION ); ?></span>
						</div>
					</div>
					<p class="mwseo-tagline"><?php esc_html_e( 'Write it your way.', 'manny-wenas-seo' ); ?></p>
					<ul class="mwseo-nav__list">
						<?php self::nav_item( admin_url( 'admin.php?page=mwseo-setup' ), __( 'Get started', 'manny-wenas-seo' ), 'setup' === $current ); ?>
						<li class="mwseo-nav__group" id="mwseo-nav-settings"><?php esc_html_e( 'Settings', 'manny-wenas-seo' ); ?></li>
						<?php foreach ( self::sections() as $slug => $label ) : ?>
							<?php self::nav_item( self::section_url( $slug ), $label, 'settings:' . $slug === $current, true ); ?>
						<?php endforeach; ?>
						<li class="mwseo-nav__group" id="mwseo-nav-tools"><?php esc_html_e( 'Tools', 'manny-wenas-seo' ); ?></li>
						<?php self::nav_item( admin_url( 'admin.php?page=mw-seo-import' ), __( 'Import', 'manny-wenas-seo' ), 'import' === $current, true ); ?>
					</ul>
				</nav>
				<div class="mwseo-main">
					<header class="mwseo-header">
						<h1><?php echo esc_html( $title ); ?></h1>
						<?php if ( '' !== $description ) : ?>
							<p class="mwseo-header__desc"><?php echo esc_html( $description ); ?></p>
						<?php endif; ?>
					</header>
					<hr class="wp-header-end">
		<?php
	}

	/**
	 * Close the common layout.
	 */
	public static function shell_close() {
		?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * One navigation entry.
	 *
	 * @param string $url     Target URL.
	 * @param string $label   Label.
	 * @param bool   $active  Is it the current screen?
	 * @param bool   $nested  Indented entry below a group heading.
	 */
	private static function nav_item( $url, $label, $active, $nested = false ) {
		printf(
			'<li><a class="mwseo-nav__link%1$s%2$s" href="%3$s"%4$s>%5$s</a></li>',
			$active ? ' is-active' : '',
			$nested ? ' is-nested' : '',
			esc_url( $url ),
			$active ? ' aria-current="page"' : '',
			esc_html( $label )
		);
	}
}
