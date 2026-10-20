<?php
defined( 'ABSPATH' ) || exit;
/**
 * First-time setup screen: import first, then site identity.
 *
 * @package MannyWenasSEO
 */

/**
 * A skippable, stateless setup screen. Nothing is required before writers can use the plugin.
 */
class MWSEO_Setup {

	const SLUG     = 'mwseo-setup';
	const TRANSIENT = 'mwseo_setup_redirect';

	/**
	 * Hook suffix of the setup page.
	 *
	 * @var string|false
	 */
	private static $page_hook = false;

	/**
	 * Hook suffix of the setup page (false before the menu is registered).
	 *
	 * @return string|false
	 */
	public static function hook() {
		return self::$page_hook;
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 15 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MWSEO_FILE ), array( __CLASS__, 'action_links' ), 20 );
	}

	/**
	 * Remember that this activation created the options (a new installation).
	 * Existing sites (options already stored) are never redirected.
	 *
	 * @param bool $is_new_install True when no options existed before activation.
	 */
	public static function flag_new_install( $is_new_install ) {
		if ( $is_new_install ) {
			set_transient( self::TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Submenu entry, kept permanently so existing admins can open the screen voluntarily.
	 */
	public static function menu() {
		self::$page_hook = add_submenu_page(
			'mwseo',
			__( 'Get started', 'manny-wenas-seo' ),
			__( 'Get started', 'manny-wenas-seo' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
		MWSEO_Admin::register_screen( self::$page_hook );
	}

	/**
	 * One-time landing after a fresh activation. No bulk, network, AJAX or non-admin redirects.
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( self::TRANSIENT ) ) {
			return;
		}
		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) || ! current_user_can( 'manage_options' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		delete_transient( self::TRANSIENT );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * "Get started" link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Get started', 'manny-wenas-seo' ) . '</a>';
		return $links;
	}

	/**
	 * Notes about another SEO plugin that is still active.
	 *
	 * @return string[]
	 */
	private static function active_plugin_notes() {
		$notes = array();
		if ( MWSEO_Compat::rank_math_active() ) {
			$notes[] = __( 'Rank Math SEO is still active. Manny Wenas SEO defers to it for frontend output until you deactivate it.', 'manny-wenas-seo' );
		}
		if ( MWSEO_Yoast::is_active() ) {
			$notes[] = __( 'Yoast SEO is still active. Manny Wenas SEO defers to it for frontend output until you deactivate it.', 'manny-wenas-seo' );
		}
		return $notes;
	}

	/**
	 * Which identity data is still absent. Optional information, never a requirement for writing.
	 *
	 * @param array $opts Options.
	 * @return string[]
	 */
	private static function missing_identity( array $opts ) {
		$missing = array();
		if ( '' === trim( (string) $opts['org_name'] ) && 'person' !== $opts['site_represents'] ) {
			$missing[] = __( 'name', 'manny-wenas-seo' );
		}
		if ( empty( $opts['org_logo'] ) ) {
			$missing[] = __( 'logo', 'manny-wenas-seo' );
		}
		if ( in_array( $opts['site_represents'], array( 'person', 'both' ), true ) && empty( $opts['person_user'] ) ) {
			$missing[] = __( 'person', 'manny-wenas-seo' );
		}
		return $missing;
	}

	/**
	 * Render the setup screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts  = MWSEO_Options::all();
		$notes = self::active_plugin_notes();
		MWSEO_Admin::shell_open( 'setup', __( 'Let’s get you started', 'manny-wenas-seo' ), __( 'A short setup. Your writing stays yours.', 'manny-wenas-seo' ) );
		settings_errors();
		?>
		<section class="mwseo-card mwseo-step" id="mwseo-step-import" aria-labelledby="mwseo-step-import-title">
			<p class="mwseo-step__eyebrow"><?php esc_html_e( 'Step 1 of 3', 'manny-wenas-seo' ); ?></p>
			<h2 id="mwseo-step-import-title"><?php esc_html_e( 'Bring your SEO data along', 'manny-wenas-seo' ); ?></h2>
			<p><?php esc_html_e( 'Preview and import supported post SEO data from Yoast SEO or Rank Math. Then check your site identity.', 'manny-wenas-seo' ); ?></p>
			<ul class="mwseo-plain-list">
				<li><?php esc_html_e( 'Site-wide settings are reviewed separately, in the next step and under Settings.', 'manny-wenas-seo' ); ?></li>
				<li><?php esc_html_e( 'Your source data is kept. Nothing is deleted and no plugin is deactivated for you.', 'manny-wenas-seo' ); ?></li>
				<li><?php esc_html_e( 'Existing Manny Wenas SEO values are preserved unless you choose to overwrite them.', 'manny-wenas-seo' ); ?></li>
				<li><?php esc_html_e( 'Not every setting is imported. Unsupported fields are listed in the preview.', 'manny-wenas-seo' ); ?></li>
			</ul>
			<?php foreach ( $notes as $note ) : ?>
				<p class="mwseo-callout"><?php echo esc_html( $note ); ?></p>
			<?php endforeach; ?>
			<?php MWSEO_Importer::render_import_form( 'setup' ); ?>
			<p><a href="#mwseo-step-identity"><?php esc_html_e( 'Skip this step', 'manny-wenas-seo' ); ?></a></p>
		</section>

		<section class="mwseo-card mwseo-step" id="mwseo-step-identity" aria-labelledby="mwseo-step-identity-title">
			<p class="mwseo-step__eyebrow"><?php esc_html_e( 'Step 2 of 3', 'manny-wenas-seo' ); ?></p>
			<h2 id="mwseo-step-identity-title"><?php esc_html_e( 'Check your site identity', 'manny-wenas-seo' ); ?></h2>
			<p><?php esc_html_e( 'Used in structured data (schema) on your site. All of it is optional for writing.', 'manny-wenas-seo' ); ?></p>
			<?php $missing = self::missing_identity( $opts ); ?>
			<?php if ( $missing ) : ?>
				<p class="mwseo-callout" id="mwseo-identity-missing">
					<?php
					/* translators: %s: list of missing items, for example "logo, person" */
					echo esc_html( sprintf( __( 'Not set yet: %s. You can add it below whenever you like.', 'manny-wenas-seo' ), implode( ', ', $missing ) ) );
					?>
				</p>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'mwseo_group' ); ?>
				<table class="form-table" role="presentation">
					<?php foreach ( MWSEO_Settings::identity_fields() as $f ) : ?>
						<?php MWSEO_Settings::field( $f, $opts ); ?>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Save site identity', 'manny-wenas-seo' ) ); ?>
			</form>
		</section>

		<section class="mwseo-card mwseo-step" id="mwseo-step-editor" aria-labelledby="mwseo-step-editor-title">
			<p class="mwseo-step__eyebrow"><?php esc_html_e( 'Step 3 of 3', 'manny-wenas-seo' ); ?></p>
			<h2 id="mwseo-step-editor-title"><?php esc_html_e( 'Write in the block editor', 'manny-wenas-seo' ); ?></h2>
			<p><?php esc_html_e( 'Open the Manny Wenas SEO panel from the editor’s top-right toolbar. Add a focus keyphrase, a title and a description when it helps. The checks are guidance, not a verdict on your writing.', 'manny-wenas-seo' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php' ) ); ?>"><?php esc_html_e( 'Write a post', 'manny-wenas-seo' ); ?></a>
				<a class="button" href="<?php echo esc_url( MWSEO_Admin::section_url( 'general' ) ); ?>"><?php esc_html_e( 'Review all settings', 'manny-wenas-seo' ); ?></a>
			</p>
		</section>
		<?php
		MWSEO_Admin::shell_close();
	}
}
