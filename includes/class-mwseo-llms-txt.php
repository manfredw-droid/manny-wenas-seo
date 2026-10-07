<?php
/**
 * Generates the llms.txt output.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Generates /llms.txt weekly. Anchor posts are listed first.
 */
class MWSEO_Llms_Txt {

	const OPTION = 'mwseo_llms_txt';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_action( 'mwseo_weekly', array( __CLASS__, 'regenerate' ) );
	}

	/**
	 * Rewrite rule.
	 */
	public static function add_rewrites() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?mwseo_llms=1', 'top' );
	}

	/**
	 * Query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'mwseo_llms';
		return $vars;
	}

	/**
	 * Serve the file.
	 */
	public static function serve() {
		if ( ! get_query_var( 'mwseo_llms' ) ) {
			return;
		}
		if ( ! MWSEO_Options::get( 'llms_enabled' ) || ! get_option( 'blog_public' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		$body = get_option( self::OPTION );
		if ( ! $body ) {
			$body = self::build();
		}
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text.
		exit;
	}

	/**
	 * Rebuild and store the file (weekly cron and on settings save).
	 */
	public static function regenerate() {
		update_option( self::OPTION, self::build(), false );
	}

	/**
	 * Build the llms.txt body.
	 *
	 * @return string
	 */
	public static function build() {
		$lines   = array();
		$lines[] = '# ' . wp_strip_all_tags( get_bloginfo( 'name' ) );
		$intro   = trim( (string) MWSEO_Options::get( 'llms_intro' ) );
		if ( ! $intro ) {
			$intro = get_bloginfo( 'description' );
		}
		if ( $intro ) {
			$lines[] = '';
			$lines[] = '> ' . preg_replace( '/\s+/', ' ', wp_strip_all_tags( $intro ) );
		}

		$types = MWSEO_Options::get( 'post_types' );
		$seen  = array();

		$cornerstone = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'has_password'   => false,
				'orderby'        => 'modified',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'   => '_mwseo_cornerstone',
						'value' => '1',
					),
					array(
						'key'   => '_yoast_wpseo_is_cornerstone',
						'value' => '1',
					),
					array(
						'key'   => 'rank_math_pillar_content',
						'value' => 'on',
					),
				),
			)
		);
		if ( $cornerstone ) {
			$lines[] = '';
			$lines[] = '## ' . __( 'Key content', 'manny-wenas-seo' );
			foreach ( $cornerstone as $p ) {
				$seen[]  = $p->ID;
				$lines[] = self::entry( $p );
			}
		}

		foreach ( $types as $type ) {
			$obj   = get_post_type_object( $type );
			$posts = get_posts(
				array(
					'post_type'      => $type,
					'post_status'    => 'publish',
					'posts_per_page' => 100,
					'has_password'   => false,
					'post__not_in'   => $seen ? $seen : array( 0 ),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'OR',
						array(
							'key'     => '_mwseo_noindex',
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => '_mwseo_noindex',
							'value'   => '1',
							'compare' => '!=',
						),
					),
				)
			);
			if ( ! $posts || ! $obj ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '## ' . $obj->labels->name;
			foreach ( $posts as $p ) {
				$lines[] = self::entry( $p );
			}
		}

		return apply_filters( 'mwseo_llms_txt', implode( "\n", $lines ) . "\n", $lines );
	}

	/**
	 * One markdown list entry.
	 *
	 * @param WP_Post $p Post.
	 * @return string
	 */
	private static function entry( $p ) {
		$desc = MWSEO_Head::description_for_post( $p->ID );
		$line = '- [' . str_replace( array( '[', ']' ), '', wp_strip_all_tags( get_the_title( $p ) ) ) . '](' . get_permalink( $p ) . ')';
		return $desc ? $line . ': ' . preg_replace( '/\s+/', ' ', $desc ) : $line;
	}
}
