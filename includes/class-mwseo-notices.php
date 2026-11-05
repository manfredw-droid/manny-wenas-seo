<?php
defined( 'ABSPATH' ) || exit;
/**
 * Admin notices for environment problems.
 *
 * @package MannyWenasSEO
 */

/**
 * Warns about missing sitemap rewrite rules and a physical robots.txt.
 */
class MWSEO_Notices {

	const REWRITE_TRANSIENT = 'mwseo_rewrite_missing';
	const ROBOTS_META       = 'mwseo_dismiss_robots_notice';
	const REWRITE_LOCK      = 'mwseo_rewrite_flush_lock';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_loaded', array( __CLASS__, 'check_rewrite' ) );
		add_action( 'admin_notices', array( __CLASS__, 'rewrite_notice' ) );
		add_action( 'admin_post_mwseo_flush_rewrite', array( __CLASS__, 'handle_flush' ) );
		add_action( 'admin_notices', array( __CLASS__, 'robots_notice' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_robots_dismiss' ) );
	}

	/**
	 * Are both sitemap rewrite rules present?
	 *
	 * @param mixed $rules Rewrite rules.
	 * @return bool
	 */
	private static function has_sitemap_rules( $rules ) {
		return is_array( $rules ) && isset( $rules['^mwseo-sitemap\.xml$'], $rules['^mwseo-([a-z0-9_]+)-sitemap([0-9]*)\.xml$'] );
	}

	/**
	 * Restore the sitemap rewrite rules if another plugin dropped them.
	 */
	public static function check_rewrite() {
		global $wp_rewrite;
		if ( MWSEO_Compat::other_active() || ! get_option( 'permalink_structure' ) || ! is_object( $wp_rewrite ) ) {
			return;
		}
		if ( self::has_sitemap_rules( $wp_rewrite->wp_rewrite_rules() ) ) {
			return;
		}
		if ( get_transient( self::REWRITE_LOCK ) ) {
			return;
		}
		set_transient( self::REWRITE_LOCK, 1, HOUR_IN_SECONDS );
		MWSEO_Sitemaps::add_rewrites();
		flush_rewrite_rules( false );
		if ( self::has_sitemap_rules( get_option( 'rewrite_rules' ) ) ) {
			delete_transient( self::REWRITE_LOCK );
			delete_transient( self::REWRITE_TRANSIENT );
			return;
		}
		set_transient( self::REWRITE_TRANSIENT, 1, 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Notice with a nonce-protected POST flush button.
	 */
	public static function rewrite_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['mwseo_flushed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'MWSEO: Rewrite rules have been flushed.', 'manny-wenas-seo' ) . '</p></div>';
		}
		if ( ! get_transient( self::REWRITE_TRANSIENT ) ) {
			return;
		}
		if ( MWSEO_Compat::other_active() || ! get_option( 'permalink_structure' ) || self::has_sitemap_rules( get_option( 'rewrite_rules' ) ) ) {
			delete_transient( self::REWRITE_TRANSIENT );
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:.5em 0;">
				<input type="hidden" name="action" value="mwseo_flush_rewrite" />
				<?php wp_nonce_field( 'mwseo_flush_rewrite', 'mwseo_flush_nonce' ); ?>
				<?php esc_html_e( 'MWSEO: Sitemap rewrite rules were removed by a conflicting plugin.', 'manny-wenas-seo' ); ?>
				<button type="submit" class="button-link"><?php esc_html_e( 'Restore sitemap rewrite rules', 'manny-wenas-seo' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the flush POST.
	 */
	public static function handle_flush() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'manny-wenas-seo' ), 403 );
		}
		check_admin_referer( 'mwseo_flush_rewrite', 'mwseo_flush_nonce' );
		MWSEO_Sitemaps::add_rewrites();
		flush_rewrite_rules();
		delete_transient( self::REWRITE_TRANSIENT );
		wp_safe_redirect( add_query_arg( 'mwseo_flushed', 1, admin_url( 'index.php' ) ) );
		exit;
	}

	/**
	 * Path of the robots.txt file that would shadow WordPress's virtual one.
	 *
	 * WordPress only serves a virtual robots.txt when home is the host root, and an empty result means no
	 * warning should be shown.
	 *
	 * @return string
	 */
	private static function physical_robots_path() {
		$home = wp_parse_url( home_url() );
		$site = wp_parse_url( site_url() );
		if ( ! empty( $home['path'] ) && '/' !== $home['path'] ) {
			return '';
		}
		if ( strtolower( (string) ( $home['host'] ?? '' ) ) !== strtolower( (string) ( $site['host'] ?? '' ) ) ) {
			return '';
		}
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$root = get_home_path();
		if ( '/' === $root && '/' !== ABSPATH ) {
			return '';
		}
		return $root . 'robots.txt';
	}

	/**
	 * Warn when a physical robots.txt overrides the virtual one.
	 */
	public static function robots_notice() {
		$path = self::physical_robots_path();
		if ( ! current_user_can( 'manage_options' ) || '' === $path || ! is_file( $path ) ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), self::ROBOTS_META, true ) ) {
			return;
		}
		$url = wp_nonce_url( add_query_arg( 'mwseo_dismiss_robots', 1 ), 'mwseo_dismiss_robots' );
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php esc_html_e( 'MWSEO: A physical robots.txt file on the server overrides the robots.txt settings in Manny Wenas SEO. Remove that file to use these settings.', 'manny-wenas-seo' ); ?>
				<a href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Dismiss this notice', 'manny-wenas-seo' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * Store the robots notice dismissal.
	 */
	public static function handle_robots_dismiss() {
		if ( ! isset( $_GET['mwseo_dismiss_robots'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'mwseo_dismiss_robots' );
		update_user_meta( get_current_user_id(), self::ROBOTS_META, true );
		wp_safe_redirect( remove_query_arg( array( 'mwseo_dismiss_robots', '_wpnonce' ) ) );
		exit;
	}
}
