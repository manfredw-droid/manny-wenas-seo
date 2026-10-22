<?php
defined( 'ABSPATH' ) || exit;
/**
 * Generates the llms.txt output.
 *
 * @package MannyWenasSEO
 */

/**
 * Serves fresh /llms.txt output and maintains weekly snapshots. Cornerstone content is listed first.
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
		nocache_headers();
		// WordPress before 6.8 does not always include no-store for anonymous visitors.
		header( 'Cache-Control: no-store, private, no-cache, must-revalidate, max-age=0' );
		if ( ! MWSEO_Options::get( 'llms_enabled' ) || ! get_option( 'blog_public' ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}
		header( 'Content-Type: text/plain; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		// Build completely before emitting anything; never fall back to a stored snapshot.
		$level = ob_get_level();
		ob_start();
		try {
			$body = self::build();
			if ( ! is_string( $body ) ) {
				throw new \UnexpectedValueException( 'Invalid llms.txt output.' );
			}
		} catch ( \Throwable $e ) {
			$body = null;
		} finally {
			// Discard incidental output from generation/filter callbacks, including failures.
			while ( ob_get_level() > $level ) {
				ob_end_clean();
			}
		}
		if ( null === $body ) {
			status_header( 503 );
			echo 'llms.txt is temporarily unavailable.';
			exit;
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text.
		exit;
	}

	/**
	 * Rebuild the compatibility snapshot (weekly cron and on settings save).
	 * Public serving never uses this snapshot as its authority or fallback.
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

		// noindex is excluded in SQL (see exclude_noindex_sql) so it happens before the limit of 50.
		// Only the call that registers the filter removes it, so a nested build() cannot strip an outer one.
		$added = false === has_filter( 'get_meta_sql', array( __CLASS__, 'exclude_noindex_sql' ) );
		if ( $added ) {
			add_filter( 'get_meta_sql', array( __CLASS__, 'exclude_noindex_sql' ), 10, 6 );
		}
		try {
			$cornerstone = get_posts(
				array(
					'post_type'              => $types,
					'post_status'            => 'publish',
					'posts_per_page'         => 50,
					'has_password'           => false,
					'orderby'                => 'modified',
					'mwseo_llms_cornerstone' => 1,
					'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
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
		} finally {
			if ( $added ) {
				remove_filter( 'get_meta_sql', array( __CLASS__, 'exclude_noindex_sql' ), 10 );
			}
		}
		$entries = array();
		foreach ( $cornerstone as $p ) {
			$entry = self::entry( $p );
			if ( null !== $entry ) {
				$seen[]   = $p->ID;
				$entries[] = $entry;
			}
		}
		if ( $entries ) {
			$lines[] = '';
			$lines[] = '## ' . __( 'Key content', 'manny-wenas-seo' );
			$lines   = array_merge( $lines, $entries );
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
			$entries = array();
			foreach ( $posts as $p ) {
				$entry = self::entry( $p );
				if ( null !== $entry ) {
					$entries[] = $entry;
				}
			}
			if ( ! $entries ) {
				continue;
			}
			$lines[] = '';
			$lines[] = '## ' . $obj->labels->name;
			$lines   = array_merge( $lines, $entries );
		}

		return apply_filters( 'mwseo_llms_txt', implode( "\n", $lines ) . "\n", $lines );
	}

	/**
	 * Add the noindex exclusion to the cornerstone query only.
	 *
	 * Same rule as the ordinary selection: a post stays eligible when it has no
	 * _mwseo_noindex row, or a row whose value is not '1'. It is two correlated
	 * subqueries limited to this key, so no second postmeta join multiplies the
	 * rows (a meta_query clause would add an unrestricted join).
	 *
	 * @param array  $sql                Meta SQL parts (join, where).
	 * @param array  $queries            Meta queries.
	 * @param string $type               Meta type.
	 * @param string $primary_table      Primary table.
	 * @param string $primary_id_column  Primary ID column.
	 * @param mixed  $context            The WP_Query being built.
	 * @return array
	 */
	public static function exclude_noindex_sql( $sql, $queries, $type, $primary_table, $primary_id_column, $context = null ) {
		if ( 'post' !== $type || ! $context instanceof WP_Query || ! $context->get( 'mwseo_llms_cornerstone' ) || ! is_array( $sql ) ) {
			return $sql;
		}
		global $wpdb;
		$id           = esc_sql( $primary_table ) . '.' . esc_sql( $primary_id_column );
		$sql['where'] = ( isset( $sql['where'] ) ? $sql['where'] : '' ) . $wpdb->prepare(
			" AND ( NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS mwseo_ni WHERE mwseo_ni.post_id = {$id} AND mwseo_ni.meta_key = %s )" .
			" OR EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS mwseo_nv WHERE mwseo_nv.post_id = {$id} AND mwseo_nv.meta_key = %s AND mwseo_nv.meta_value != %s ) )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names come from WP_Query.
			'_mwseo_noindex',
			'_mwseo_noindex',
			'1'
		);
		return $sql;
	}

	/**
	 * One markdown list entry.
	 *
	 * @param WP_Post $p Post.
	 * @return string|null Null when the candidate is no longer publicly eligible.
	 */
	private static function entry( $p ) {
		$p = get_post( $p->ID );
		if ( ! $p || 'publish' !== $p->post_status || '' !== (string) $p->post_password || ! is_post_publicly_viewable( $p ) ) {
			return null;
		}
		$desc = MWSEO_Head::description_for_post( $p->ID );
		$line = '- [' . str_replace( array( '[', ']' ), '', wp_strip_all_tags( get_the_title( $p ) ) ) . '](' . get_permalink( $p ) . ')';
		return $desc ? $line . ': ' . preg_replace( '/\s+/', ' ', $desc ) : $line;
	}
}
