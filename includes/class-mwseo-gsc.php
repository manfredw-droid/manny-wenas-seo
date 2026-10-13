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
		add_action( 'transition_post_status', array( __CLASS__, 'on_publish' ), 10, 3 );
		add_action( 'mwseo_gsc_submit', array( __CLASS__, 'submit_scheduled' ) );
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
			// Read/write scope required for sitemap submission (PUT /sitemaps/{url}).
			$scope = 'https://www.googleapis.com/auth/webmasters';
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
		$base = array(
			'client_id'     => MWSEO_Options::get( 'gsc_client_id' ),
			'client_secret' => MWSEO_Options::get( 'gsc_client_secret' ),
		);

		/*
		 * redirect_uri is required for the initial authorization-code exchange
		 * (RFC 6749 §4.1.3) but MUST be omitted for refresh-token requests
		 * (RFC 6749 §6).  Sending it on a refresh can trigger a
		 * redirect_uri_mismatch error from Google when the site's admin URL has
		 * changed (e.g. HTTP → HTTPS) since the OAuth client was registered.
		 */
		if ( isset( $params['grant_type'] ) && 'authorization_code' === $params['grant_type'] ) {
			$base['redirect_uri'] = self::redirect_uri();
		}

		$resp = wp_remote_post(
			'https://oauth2.googleapis.com/token',
			array(
				'timeout' => 15,
				'body'    => array_merge( $base, $params ),
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
		} elseif ( in_array( $method, array( 'PUT', 'POST', 'PATCH' ), true ) ) {
			// Google's front end answers body-less PUT/POST without an explicit
			// Content-Length with an HTML "411 Length Required" page (sitemaps.submit
			// has no request body), which surfaces as a generic failure.
			unset( $args['headers']['Content-Type'] );
			$args['headers']['Content-Length'] = '0';
			$args['body']                      = '';
		}
		$resp = wp_remote_request( 'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode( self::property() ) . '/' . $path, $args );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$code = wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $code >= 300 ) {
			if ( isset( $data['error']['message'] ) ) {
				$message = $data['error']['message'];
			} else {
				/* translators: %d: HTTP status code returned by Google. */
				$message = sprintf( __( 'Search Console request failed (HTTP %d).', 'manny-wenas-seo' ), (int) $code );
			}
			return new WP_Error( 'mwseo_gsc_api', $message, array( 'status' => $code ) );
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

	/**
	 * Schedule a sitemap submission when a post is first published.
	 *
	 * Fires on every transition_post_status. Skips anything that isn't a
	 * first-time publication (old status already publish), posts with a
	 * password, or when GSC is not connected.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       Post being transitioned.
	 */
	public static function on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( ! self::is_connected() ) {
			return;
		}
		if ( '' !== (string) $post->post_password ) {
			return;
		}
		// One submission per day is enough — avoid hammering the API on bulk
		// operations.  The transient is cleared on success so a retry is
		// attempted the first time the guard expires, not immediately.
		if ( get_transient( 'mwseo_gsc_sitemap_sent' ) ) {
			return;
		}
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'mwseo_gsc_submit' );
	}

	/**
	 * Cron callback: submit the sitemap index to Google Search Console.
	 *
	 * Only sets the suppression transient on success so a failed attempt is
	 * retried the next time a post is published rather than silently dropped.
	 */
	public static function submit_scheduled() {
		if ( ! self::is_connected() ) {
			return;
		}
		$result = self::submit_sitemap( MWSEO_Sitemaps::index_url() );
		if ( ! is_wp_error( $result ) ) {
			set_transient( 'mwseo_gsc_sitemap_sent', 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Submit a sitemap URL to Google Search Console.
	 *
	 * Uses the Webmasters v3 sitemaps.submit endpoint:
	 *   PUT https://www.googleapis.com/webmasters/v3/sites/{siteUrl}/sitemaps/{feedpath}
	 *
	 * Requires the site to have been connected with the 'webmasters' (read/write)
	 * OAuth scope.  Returns true on success or a WP_Error on failure.
	 *
	 * @param string $sitemap_url Absolute sitemap URL.
	 * @return true|WP_Error
	 */
	public static function submit_sitemap( $sitemap_url ) {
		$res = self::request( 'PUT', 'sitemaps/' . rawurlencode( $sitemap_url ) );
		return is_wp_error( $res ) ? $res : true;
	}
}
