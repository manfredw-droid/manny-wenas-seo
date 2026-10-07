<?php
/**
 * Search engine ownership verification files.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the Google Search Console HTML verification file.
 */
class MWSEO_Verification {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'serve_gsc' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'serve_bing' ), 0 );
	}

	/**
	 * Sanitize a GSC verification file name: [a-z0-9-_.], max 64 chars.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_gsc_filename( $value ) {
		$value = strtolower( is_scalar( $value ) ? (string) $value : '' );
		return substr( preg_replace( '/[^a-z0-9\-_.]/', '', $value ), 0, 64 );
	}

	/**
	 * Request path relative to the site root (supports sub-directory installs).
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return ltrim( rawurldecode( $path ), '/' );
	}

	/**
	 * Serve /{googleXXXX.html}.
	 */
	public static function serve_gsc() {
		$filename = self::sanitize_gsc_filename( MWSEO_Options::get( 'gsc_html_filename' ) );
		if ( '' === $filename || self::request_path() !== $filename ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo 'google-site-verification: ' . esc_html( $filename ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Sanitize a Bing verification code: [A-Za-z0-9], max 64 chars.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_bing_key( $value ) {
		return substr( preg_replace( '/[^A-Za-z0-9]/', '', is_scalar( $value ) ? (string) $value : '' ), 0, 64 );
	}

	/**
	 * Serve /BingSiteAuth.xml.
	 */
	public static function serve_bing() {
		$key = self::sanitize_bing_key( MWSEO_Options::get( 'bing_verification_key' ) );
		if ( '' === $key || 'BingSiteAuth.xml' !== self::request_path() ) {
			return;
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo '<?xml version="1.0"?><users>  <user>' . esc_html( $key ) . '</user></users>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Status line shown under a verification field.
	 *
	 * @param string $value Stored value.
	 * @return string
	 */
	public static function status( $value ) {
		return '' === (string) $value
			? __( 'Niet ingesteld', 'manny-wenas-seo' )
			: __( 'Actief — controleer via Google Search Console.', 'manny-wenas-seo' );
	}
}
