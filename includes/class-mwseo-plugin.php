<?php
/**
 * Plugin bootstrap.
 *
 * @package MannyWenasSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires all modules together and handles activation/deactivation.
 */
final class MWSEO_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var MWSEO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the singleton.
	 *
	 * @return MWSEO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Boot modules.
	 */
	private function __construct() {
		MWSEO_Meta::init();
		MWSEO_Metabox::init();
		MWSEO_Settings::init();
		MWSEO_Rest::init();
		MWSEO_Head::init();
		MWSEO_Schema::init();
		MWSEO_Yoast::init();
		MWSEO_Compat::init();
		MWSEO_Sitemaps::init();
		MWSEO_Robots_Txt::init();
		MWSEO_Notices::init();
		MWSEO_Verification::init();
		MWSEO_Indexnow::init();
		MWSEO_Llms_Txt::init();
		MWSEO_Gsc::init();
		MWSEO_Abilities::init();
		MWSEO_Importer::init();
		MWSEO_Trends::init();
	}

	/**
	 * Activation: defaults, rewrite rules and cron.
	 */
	public static function activate() {
		if ( false === get_option( MWSEO_Options::KEY ) ) {
			add_option( MWSEO_Options::KEY, MWSEO_Options::defaults(), '', false );
		}
		MWSEO_Options::flush();
		MWSEO_Indexnow::ensure_key();
		MWSEO_Sitemaps::add_rewrites();
		MWSEO_Llms_Txt::add_rewrites();
		flush_rewrite_rules();
		if ( ! wp_next_scheduled( 'mwseo_weekly' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'weekly', 'mwseo_weekly' );
		}
		MWSEO_Llms_Txt::regenerate();
	}

	/**
	 * Deactivation: clear cron and rewrite rules.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'mwseo_weekly' );
		flush_rewrite_rules();
	}
}
