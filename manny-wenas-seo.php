<?php
/**
 * Plugin Name:       Manny Wenas SEO
 * Plugin URI:        https://example.com/manny-wenas-seo
 * Description:       Lean SEO for WordPress: semantic keyphrase scoring, connected JSON-LD schema, sitemaps, llms.txt, Search Console and AI-agent abilities.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Manny Wenas
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       manny-wenas-seo
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

define( 'MWSEO_VERSION', '1.0.0' );
define( 'MWSEO_FILE', __FILE__ );
define( 'MWSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWSEO_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoload plugin classes: MWSEO_Foo_Bar => includes/class-mwseo-foo-bar.php.
 *
 * @param string $class_name Class name.
 */
function mwseo_autoload( $class_name ) {
	if ( 0 !== strpos( $class_name, 'MWSEO_' ) ) {
		return;
	}
	$file = MWSEO_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
	if ( is_readable( $file ) ) {
		require_once $file;
	}
}
spl_autoload_register( 'mwseo_autoload' );

register_activation_hook( __FILE__, array( 'MWSEO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MWSEO_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MWSEO_Plugin', 'instance' ) );
