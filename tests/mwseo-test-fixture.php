<?php
/**
 * Plugin Name: MWSEO test fixture (disposable sites only)
 *
 * Copy to wp-content/mu-plugins/ of a disposable WordPress site whose table prefix starts with `equinox_`.
 * It does nothing on any other site. Behaviour is driven by options set by the tests:
 *
 *  - mwseo_test_conflict : '', 'rankmath', 'yoast' or 'filter' (simulated conflicting SEO plugin)
 *  - mwseo_test_nosave   : 1 makes the rewrite_rules option impossible to save or delete
 *
 * It counts rewrite flushes in the option mwseo_test_flushes and offers two HTTP probes:
 *  ?mwseo_fx=state  prints JSON about the current request; ?mwseo_fx=flush flushes the rules like another plugin could.
 */
global $wpdb;
if ( isset( $wpdb->prefix ) && 0 === strpos( $wpdb->prefix, 'equinox_' ) ) {
	$mwseo_fx_conflict = get_option( 'mwseo_test_conflict', '' );
	if ( 'rankmath' === $mwseo_fx_conflict && ! defined( 'RANK_MATH_VERSION' ) ) {
		define( 'RANK_MATH_VERSION', '99.0' );
	}
	if ( 'yoast' === $mwseo_fx_conflict && ! defined( 'WPSEO_VERSION' ) ) {
		define( 'WPSEO_VERSION', '99.0' );
	}
	if ( 'filter' === $mwseo_fx_conflict ) {
		add_filter( 'mwseo_other_seo_active', '__return_true' );
	}

	// Every flush starts with delete_option( 'rewrite_rules' ); count it before anything can block it.
	add_filter(
		'pre_delete_option_rewrite_rules',
		static function ( $pre ) {
			update_option( 'mwseo_test_flushes', (int) get_option( 'mwseo_test_flushes', 0 ) + 1, false );
			return $pre;
		},
		1
	);
	if ( get_option( 'mwseo_test_nosave' ) ) {
		add_filter( 'pre_delete_option_rewrite_rules', '__return_false', 10 );
		add_filter(
			'pre_update_option_rewrite_rules',
			static function ( $value, $old ) {
				return $old;
			},
			10,
			2
		);
	}

	// The command-line test process must not run the plugin's own recovery while it reads state.
	if ( 'cli' === PHP_SAPI ) {
		add_action(
			'plugins_loaded',
			static function () {
				remove_action( 'wp_loaded', array( 'MWSEO_Notices', 'check_rewrite' ) );
			},
			20
		);
	}

	if ( isset( $_GET['mwseo_fx'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		add_action(
			'wp_loaded',
			static function () {
				$what = sanitize_key( wp_unslash( $_GET['mwseo_fx'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( 'flush' === $what ) {
					flush_rewrite_rules( false );
				}
				header( 'Content-Type: application/json' );
				echo wp_json_encode( array( 'other_active' => MWSEO_Compat::other_active() ) );
				exit;
			},
			99
		);
	}
}
