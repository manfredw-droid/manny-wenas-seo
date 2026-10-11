<?php
defined( 'ABSPATH' ) || exit;
/**
 * Google Search Console integration (OAuth 2.0).
 *
 * @package MannyWenasSEO
 */

/**
 * OAuth flow and Search Analytics queries.
 *
 * The site owner creates their own Google Cloud OAuth client (web application)
 * and registers the redirect URI shown on the settings screen.
 */
class MWSEO_Gsc {

	const TOKENS = 'mwseo_gsc';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_admin' ) );
	}

	/**
	 * Is a refresh token stored?
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$t = get_option( self::TOKENS );
		return ! empty( $t['refresh_token'] );
	}

	/**
	 * OAuth redirect URI (must be registered in Google Cloud).
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=mwseo' );
	}

	/**
	 * Search Console property identifier.
	 *
	 * @return string
	 */
	public static function property() {
		$p = trim( (string) MWSEO_Options::get( 'gsc_property' ) );
		return $p ? $p : home_url( '/' );
	}

	/**
	 * Handle connect, callback and disconnect requests.
	 */
	public static function handle_admin() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['page'] ) || 'mwseo' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( isset( $_GET['mwseo_gsc_connect'] ) && check_admin_referer( 'mwseo_gsc_connect' ) ) {
			$client_id = MWSEO_Options::get( 'gsc_client_id' );
			if ( ! $client_id ) {
				wp_safe_redirect( admin_url( 'admin.php?page=mwseo&tab=gsc&mwseo_msg=no_client' ) );
				exit;
			}
			$scope = 'https://www.googleapis.com/auth/webmasters.readonly';
			$url   = add_query_arg(
				array(
					'client_id'     => $client_id,
					'redirect_uri'  => self::redirect_uri(),
					'response_type' => 'code',
					'scope'         => $scope,
					'access_type'   => 'offline',
					'prompt'        => 'consent',
					'state'         => wp_create_nonce( 'mwseo_gsc_state' ),
				),
				'https://accounts.google.com/o/oauth2/v2/auth'
			);
			wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google's OAuth endpoint.
			exit;
		}

		if ( isset( $_GET['mwseo_gsc_disconnect'] ) && check_admin_referer( 'mwseo_gsc_disconnect' ) ) {
			delete_option( self::TOKENS );
			wp_safe_redirect( admin_url( 'admin.php?page=mwseo&tab=gsc&mwseo_msg=disconnected' ) );
			exit;
		}

		if ( isset( $_GET['code'], $_GET['state'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['state'] ) ), 'mwseo_gsc_state' ) ) {
			$result = self::token_request(
				array(
					'grant_type' => 'authorization_code',
					'code'       => sanitize_text_field( wp_unslash( $_GET['code'] ) ),
				)
			);
			$msg    = 'error';
			if ( ! is_wp_error( $result ) && ! empty( $result['refresh_token'] ) ) {
				update_option(
					self::TOKENS,
					array(
						'access_token'  => $result['access_token'],
						'expires'       => time() + (int) $result['expires_in'] - 60,
						'refresh_token' => $result['refresh_token'],
					),
					false
				);
				$msg = 'connected';
			}
			wp_safe_redirect( admin_url( 'admin.php?page=mwseo&tab=gsc&mwseo_msg=' . $msg ) );
			exit;
		}
	}

	/**
	 * Call Google's token endpoint.
	 *
	 * @param array $params Grant parameters.
	 * @return array|WP_Error
	 */
	private static function token_request( array $params ) {
		$resp = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array_merge(
					array(
						'client_id'     => MWSEO_Options::get( 'gsc_client_id' ),
						'client_secret' => MWSEO_Options::get( 'gsc_client_secret' ),
						'redirect_uri'  => self::redirect_uri(),
					),
					$params
				),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$body = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $resp ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'mwseo_gsc_token', isset( $body['error_description'] ) ? $body['error_description'] : __( 'Token request failed.', 'manny-wenas-seo' ) );
		}
		return $body;
	}

	/**
	 * Valid access token, refreshing when needed.
	 *
	 * @return string|WP_Error
	 */
	private static function access_token() {
		$t = get_option( self::TOKENS );
		if ( empty( $t['refresh_token'] ) ) {
			return new WP_Error( 'mwseo_gsc_off', __( 'Search Console is not connected.', 'manny-wenas-seo' ) );
		}
		if ( ! empty( $t['access_token'] ) && $t['expires'] > time() ) {
			return $t['access_token'];
		}
		$new = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $t['refresh_token'],
			)
		);
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$t['access_token'] = $new['access_token'];
		$t['expires']      = time() + (int) $new['expires_in'] - 60;
		update_option( self::TOKENS, $t, false );
		return $t['access_token'];
	}

	/**
	 * Authenticated API request.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $path   Path under /webmasters/v3/sites/{property}/.
	 * @param array|null $body   JSON body.
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, $body = null ) {
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$resp = wp_remote_request( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( self::property() ) . '/' . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 300 ) {
			return new WP_Error( 'mwseo_gsc_api', isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Search Console request failed.', 'manny-wenas-seo' ), array( 'status' => $code ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Query data for one URL, last 28 days (cached 6 hours).
	 *
	 * @param string $url Page URL.
	 * @return array[]|WP_Error Rows with query, clicks, impressions, position.
	 */
	public static function page_queries( $url ) {
		$key   = 'mwseo_gsc_' . md5( $url );
		$cache = get_transient( $key );
		if ( false !== $cache ) {
			return $cache;
		}
		$res = self::request(
			'POST',
			'searchAnalytics/query',
			array(
				'startDate'             => gmdate( 'Y-m-d', strtotime( '-31 days' ) ),
				'endDate'               => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
				'dimensions'            => array( 'query' ),
				'dimensionFilterGroups' => array(
					array(
						'filters' => array(
							array(
								'dimension'  => 'page',
								'operator'   => 'equals',
								'expression' => $url,
							),
						),
					),
				),
				'rowLimit'              => 50,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$rows = array();
		foreach ( isset( $res['rows'] ) ? $res['rows'] : array() as $r ) {
			$rows[] = array(
				'query'       => $r['keys'][0],
				'clicks'      => (int) $r['clicks'],
				'impressions' => (int) $r['impressions'],
				'position'    => round( $r['position'], 1 ),
			);
		}
		set_transient( $key, $rows, 6 * HOUR_IN_SECONDS );
		return $rows;
	}
}
