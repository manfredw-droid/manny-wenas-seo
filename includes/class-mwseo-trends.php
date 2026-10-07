<?php
/**
 * Google Trends indicator for the focus keyphrase.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Looks up the 7-day Google Trends interest of the focus keyphrase (unofficial
 * endpoints, best effort) and serves it to the metabox over admin-ajax. Any
 * failure simply means no indicator is shown.
 */
class MWSEO_Trends {

	const ACTION    = 'mwseo_trends';
	const BASE      = 'https://trends.google.com/trends/api/';
	const CACHE_TTL = DAY_IN_SECONDS;
	const FAIL_TTL  = HOUR_IN_SECONDS;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'ajax' ) );
	}

	/**
	 * AJAX handler: returns the average interest for a keyphrase.
	 */
	public static function ajax() {
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		$keyphrase = isset( $_POST['keyphrase'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['keyphrase'] ) ) ) : '';
		if ( '' === $keyphrase ) {
			wp_send_json_error( null, 400 );
		}
		$avg = self::average( $keyphrase );
		if ( null === $avg ) {
			wp_send_json_error();
		}
		wp_send_json_success(
			array(
				'average' => $avg,
				'level'   => self::level( $avg ),
			)
		);
	}

	/**
	 * Traffic-light level: green above 60, orange 30-60, grey below 30.
	 *
	 * @param int $avg Average interest 0-100.
	 * @return string high|medium|low
	 */
	public static function level( $avg ) {
		if ( $avg > 60 ) {
			return 'high';
		}
		return $avg >= 30 ? 'medium' : 'low';
	}

	/**
	 * Cached 7-day average interest.
	 *
	 * @param string $keyphrase Keyphrase.
	 * @return int|null 0-100, or null when unavailable.
	 */
	public static function average( $keyphrase ) {
		$key    = 'mwseo_trends_' . md5( $keyphrase );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return isset( $cached['avg'] ) ? (int) $cached['avg'] : null;
		}
		$avg = self::fetch( $keyphrase );
		if ( null === $avg ) {
			// Remember the failure briefly so a rate-limited endpoint is not hammered.
			set_transient( $key, array( 'avg' => null ), self::FAIL_TTL );
			return null;
		}
		set_transient( $key, array( 'avg' => $avg ), self::CACHE_TTL );
		return $avg;
	}

	/**
	 * Query Google Trends: explore (to obtain the widget token), then the
	 * interest-over-time data.
	 *
	 * @param string $keyphrase Keyphrase.
	 * @return int|null
	 */
	private static function fetch( $keyphrase ) {
		$explore = self::request(
			'explore',
			array(
				'hl'  => 'en-US',
				'tz'  => '0',
				'req' => wp_json_encode(
					array(
						'comparisonItem' => array(
							array(
								'keyword' => $keyphrase,
								'geo'     => '',
								'time'    => 'now 7-d',
							),
						),
						'category'       => 0,
						'property'       => '',
					)
				),
			)
		);
		if ( null === $explore || empty( $explore['widgets'] ) || ! is_array( $explore['widgets'] ) ) {
			return null;
		}
		$widget = null;
		foreach ( $explore['widgets'] as $w ) {
			if ( isset( $w['id'], $w['token'], $w['request'] ) && 'TIMESERIES' === $w['id'] ) {
				$widget = $w;
				break;
			}
		}
		if ( ! $widget ) {
			self::log( 'no TIMESERIES widget in explore response' );
			return null;
		}
		$data = self::request(
			'widgetdata/multiline',
			array(
				'hl'    => 'en-US',
				'tz'    => '0',
				'req'   => wp_json_encode( $widget['request'] ),
				'token' => $widget['token'],
			)
		);
		if ( null === $data || empty( $data['default']['timelineData'] ) || ! is_array( $data['default']['timelineData'] ) ) {
			self::log( 'no timelineData in response' );
			return null;
		}
		$values = array();
		foreach ( $data['default']['timelineData'] as $point ) {
			if ( isset( $point['value'][0] ) ) {
				$values[] = (float) $point['value'][0];
			}
		}
		if ( ! $values ) {
			self::log( 'empty timelineData' );
			return null;
		}
		return (int) round( array_sum( $values ) / count( $values ) );
	}

	/**
	 * GET a Trends endpoint and decode the XSSI-prefixed JSON.
	 *
	 * @param string $path Endpoint path below /trends/api/.
	 * @param array  $args Query args; values are URL-encoded.
	 * @return array|null
	 */
	private static function request( $path, array $args ) {
		$query = array();
		foreach ( $args as $name => $value ) {
			$query[] = $name . '=' . rawurlencode( (string) $value );
		}
		$response = wp_remote_get(
			self::BASE . $path . '?' . implode( '&', $query ),
			array(
				'timeout' => 8,
				'headers' => array( 'User-Agent' => 'Mozilla/5.0' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			self::log( $path . ': ' . $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::log( $path . ': HTTP ' . $code );
			return null;
		}
		// Google prefixes the JSON with )]}' as XSSI protection.
		$body = preg_replace( "/^\)\]\}',?\s*/", '', (string) wp_remote_retrieve_body( $response ) );
		$json = json_decode( (string) $body, true );
		if ( ! is_array( $json ) ) {
			self::log( $path . ': unexpected response format' );
			return null;
		}
		return $json;
	}

	/**
	 * Debug log.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		error_log( 'MWSEO Trends: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
