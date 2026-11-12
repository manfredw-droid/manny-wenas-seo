<?php
defined( 'ABSPATH' ) || exit;
/**
 * XML sitemap suite and HTML sitemap.
 *
 * @package MannyWenasSEO
 */

/**
 * Serves /mwseo-sitemap.xml and its children:
 * posts per type, news, images, videos, categories, tags and authors.
 */
class MWSEO_Sitemaps {

	const PER_PAGE = 1000;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'mwseo_html_sitemap', array( __CLASS__, 'html_sitemap' ) );
		// Kept current even while another SEO plugin serves the sitemaps, so it is right when ours return.
		MWSEO_Media_Index::init();
		if ( MWSEO_Compat::other_active() ) {
			return;
		}
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'init', array( __CLASS__, 'add_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'get_meta_sql', array( __CLASS__, 'media_flag_sql' ), 10, 6 );
	}

	/**
	 * Rewrite rules.
	 */
	public static function add_rewrites() {
		add_rewrite_rule( '^mwseo-sitemap\.xml$', 'index.php?mwseo_sitemap=index', 'top' );
		add_rewrite_rule( '^mwseo-([a-z0-9_]+)-sitemap([0-9]*)\.xml$', 'index.php?mwseo_sitemap=$matches[1]&mwseo_page=$matches[2]', 'top' );
	}

	/**
	 * Query vars.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'mwseo_sitemap';
		$vars[] = 'mwseo_page';
		return $vars;
	}

	/**
	 * Index URL.
	 *
	 * @return string
	 */
	public static function index_url() {
		// Plain permalinks have no rewrite rules, so fall back to the query-string form.
		if ( ! get_option( 'permalink_structure' ) ) {
			return home_url( '/?mwseo_sitemap=index' );
		}
		return home_url( '/mwseo-sitemap.xml' );
	}

	/**
	 * Enabled sitemap types.
	 *
	 * @return string[]
	 */
	public static function types() {
		$types = array();
		foreach ( MWSEO_Options::get( 'post_types' ) as $pt ) {
			$types[] = $pt;
		}
		foreach ( array(
			'news'     => 'sitemap_news',
			'image'    => 'sitemap_images',
			'video'    => 'sitemap_videos',
			'category' => 'sitemap_categories',
			'tag'      => 'sitemap_tags',
			'author'   => 'sitemap_authors',
		) as $type => $opt ) {
			if ( MWSEO_Options::get( $opt ) ) {
				$types[] = $type;
			}
		}
		return $types;
	}

	/**
	 * Output a sitemap when one is requested.
	 */
	public static function serve() {
		$type = get_query_var( 'mwseo_sitemap' );
		if ( ! $type ) {
			return;
		}
		$page = max( 1, (int) get_query_var( 'mwseo_page' ) );

		if ( 'index' !== $type && ! in_array( $type, self::types(), true ) ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return;
		}

		if ( in_array( $type, array( 'index', 'image', 'video' ), true ) ) {
			// Catch up with content that existed before posts were flagged for media; a no-op once done.
			MWSEO_Media_Index::step();
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ( 'index' === $type ) {
			self::render_index();
		} else {
			self::render( $type, $page );
		}
		exit;
	}

	/**
	 * Sitemap index.
	 */
	private static function render_index() {
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( self::types() as $type ) {
			$pages = self::page_count( $type );
			for ( $i = 1; $i <= $pages; $i++ ) {
				$suffix = $i > 1 ? $i : '';
				echo '<sitemap><loc>' . esc_url( home_url( "/mwseo-{$type}-sitemap{$suffix}.xml" ) ) . "</loc></sitemap>\n";
			}
		}
		echo '</sitemapindex>';
	}

	/**
	 * Number of pages for a type.
	 *
	 * @param string $type Sitemap type.
	 * @return int
	 */
	private static function page_count( $type ) {
		// Same precedence as render(): the special sitemap names win over a post type of that name.
		switch ( $type ) {
			case 'news':
				return 1;
			case 'image':
			case 'video':
				$flagged = self::media_args( $type );
				$total   = self::post_total( (array) MWSEO_Options::get( 'post_types' ), $flagged );
				if ( $flagged ) {
					// Only posts that have this kind of media count: no entries, no sitemap.
					return (int) ceil( $total / self::PER_PAGE );
				}
				// Not known yet for all existing content: every post counts, PER_PAGE posts per sitemap.
				break;
			case 'category':
			case 'tag':
				$total = self::term_total( $type );
				break;
			case 'author':
				$total = self::author_total();
				break;
			default:
				if ( ! post_type_exists( $type ) ) {
					return 1;
				}
				$total = self::post_total( array( $type ) );
		}
		return max( 1, (int) ceil( $total / self::PER_PAGE ) );
	}

	/**
	 * Number of posts that qualify for the sitemaps of these post types.
	 *
	 * @param string[] $types Post types.
	 * @param array    $extra Extra query args (see media_args()).
	 * @return int
	 */
	private static function post_total( array $types, array $extra = array() ) {
		// The index can ask for the same total more than once; count once per request.
		static $totals = array();
		$key           = implode( ',', $types ) . '|' . md5( (string) wp_json_encode( $extra ) );
		if ( ! isset( $totals[ $key ] ) ) {
			$q              = new WP_Query( array_merge( self::post_args( $types, 1, 1 ), $extra ) );
			$totals[ $key ] = (int) $q->found_posts;
		}
		return $totals[ $key ];
	}

	/**
	 * Extra query args that limit an image or video sitemap to posts flagged as having that
	 * kind of media, on top of the usual rules (published, no password, not noindex).
	 *
	 * @param string $type 'image' or 'video'.
	 * @return array Empty while the flags do not cover all existing content yet.
	 */
	private static function media_args( $type ) {
		if ( ! MWSEO_Media_Index::ready() ) {
			return array();
		}
		// Applied by media_flag_sql() as one subquery bounded to the flag key. A meta_query
		// clause would add an unrestricted postmeta join to a query that already has one.
		return array( 'mwseo_media_flag' => MWSEO_Media_Index::key( $type ) );
	}

	/**
	 * Limit a sitemap query to posts that carry a media flag.
	 *
	 * Hooked on get_meta_sql like the other key-bounded conditions of this plugin; only
	 * queries with the mwseo_media_flag query variable are touched.
	 *
	 * @param array  $sql               Meta SQL parts (join, where).
	 * @param array  $queries           Meta queries.
	 * @param string $type              Meta type.
	 * @param string $primary_table     Primary table.
	 * @param string $primary_id_column Primary ID column.
	 * @param mixed  $context           The WP_Query being built.
	 * @return array
	 */
	public static function media_flag_sql( $sql, $queries, $type, $primary_table, $primary_id_column, $context = null ) {
		if ( 'post' !== $type || ! $context instanceof WP_Query || ! $context->get( 'mwseo_media_flag' ) || ! is_array( $sql ) ) {
			return $sql;
		}
		global $wpdb;
		$id           = esc_sql( $primary_table ) . '.' . esc_sql( $primary_id_column );
		$sql['where'] = ( isset( $sql['where'] ) ? $sql['where'] : '' ) . $wpdb->prepare(
			" AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} AS mwseo_mf WHERE mwseo_mf.post_id = {$id} AND mwseo_mf.meta_key = %s AND mwseo_mf.meta_value = %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names come from WP_Query.
			(string) $context->get( 'mwseo_media_flag' ),
			'1'
		);
		return $sql;
	}

	/**
	 * One sitemap page of qualifying post IDs, with their caches filled.
	 *
	 * The posts are selected by ID exactly as before (so filters on loaded posts still do not
	 * apply); their rows, meta and terms are then loaded in a few queries for the whole page,
	 * so the loops that print the entries need no query per URL.
	 *
	 * @param string[] $types      Post types.
	 * @param int      $page       Page.
	 * @param array    $extra      Extra query args.
	 * @param bool     $thumbnails Also load the featured images of these posts.
	 * @return int[]
	 */
	private static function post_ids( array $types, $page, array $extra = array(), $thumbnails = false ) {
		$args                  = array_merge( self::post_args( $types, $page ), $extra );
		$args['no_found_rows'] = true;
		$q                     = new WP_Query( $args );
		$ids                   = array_map( 'intval', $q->posts );
		if ( $ids && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $ids, true, true );
			if ( $thumbnails ) {
				MWSEO_Media_Index::prime_thumbnails( $ids );
			}
		}
		return $ids;
	}

	/**
	 * Query args for the terms of a category or tag sitemap, in a fixed order.
	 *
	 * @param string $type 'category' or 'tag'.
	 * @return array
	 */
	private static function term_args( $type ) {
		return array(
			'taxonomy'   => 'category' === $type ? 'category' : 'post_tag',
			'hide_empty' => true,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		);
	}

	/**
	 * All qualifying terms of a hierarchical taxonomy.
	 *
	 * WordPress keeps an empty parent of a non-empty child and decides that after its
	 * database query, so counting and slicing must use this complete list.
	 *
	 * @param string $type 'category' or 'tag'.
	 * @return WP_Term[]
	 */
	private static function all_terms( $type ) {
		$terms = get_terms( self::term_args( $type ) );
		return is_wp_error( $terms ) ? array() : array_values( $terms );
	}

	/**
	 * Number of terms that qualify for a category or tag sitemap.
	 *
	 * @param string $type 'category' or 'tag'.
	 * @return int
	 */
	private static function term_total( $type ) {
		$args = self::term_args( $type );
		if ( is_taxonomy_hierarchical( $args['taxonomy'] ) ) {
			return count( self::all_terms( $type ) );
		}
		$count = wp_count_terms( $args );
		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * One sitemap page of terms.
	 *
	 * @param string $type 'category' or 'tag'.
	 * @param int    $page Page.
	 * @return WP_Term[]
	 */
	private static function term_page( $type, $page ) {
		$args   = self::term_args( $type );
		$offset = ( $page - 1 ) * self::PER_PAGE;
		if ( is_taxonomy_hierarchical( $args['taxonomy'] ) ) {
			return array_slice( self::all_terms( $type ), $offset, self::PER_PAGE );
		}
		$args['number'] = self::PER_PAGE;
		$args['offset'] = $offset;
		$terms          = get_terms( $args );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Number of users with published posts.
	 *
	 * @return int
	 */
	private static function author_total() {
		$q = new WP_User_Query(
			array(
				'has_published_posts' => true,
				'fields'              => 'ID',
				'number'              => 1,
				'count_total'         => true,
			)
		);
		return (int) $q->get_total();
	}

	/**
	 * Base query args that exclude noindex posts.
	 *
	 * @param string[] $types Post types.
	 * @param int      $page  Page.
	 * @param int      $per   Per page.
	 * @return array
	 */
	private static function post_args( array $types, $page, $per = self::PER_PAGE ) {
		return array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => $per,
			'paged'          => $page,
			'fields'         => 'ids',
			// The ID breaks ties in the modified date, so consecutive pages never overlap or skip a post.
			'orderby'        => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'has_password'   => false,
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
		);
	}

	/**
	 * Render a child sitemap.
	 *
	 * @param string $type Type.
	 * @param int    $page Page.
	 */
	private static function render( $type, $page ) {
		$ns = 'xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"';
		switch ( $type ) {
			case 'news':
				echo "<urlset $ns xmlns:news=\"http://www.google.com/schemas/sitemap-news/0.9\">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				self::news();
				break;
			case 'image':
				echo "<urlset $ns xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				self::images( $page );
				break;
			case 'video':
				echo "<urlset $ns xmlns:video=\"http://www.google.com/schemas/sitemap-video/1.1\">\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				self::videos( $page );
				break;
			case 'category':
			case 'tag':
				echo "<urlset $ns>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( self::term_page( $type, $page ) as $term ) {
					self::url( get_term_link( $term ) );
				}
				break;
			case 'author':
				echo "<urlset $ns>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				// The nicename comes with the list, so building a URL needs no query per user.
				foreach ( get_users(
					array(
						'has_published_posts' => true,
						'fields'              => array( 'ID', 'user_nicename' ),
						'number'              => self::PER_PAGE,
						'offset'              => ( $page - 1 ) * self::PER_PAGE,
						'orderby'             => 'ID',
						'order'               => 'ASC',
						'count_total'         => false,
					)
				) as $user ) {
					self::url( get_author_posts_url( (int) $user->ID, $user->user_nicename ) );
				}
				break;
			default:
				echo "<urlset $ns>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( self::post_ids( array( $type ), $page ) as $id ) {
					self::url( get_permalink( $id ), get_post_modified_time( 'c', true, $id ) );
				}
		}
		echo '</urlset>';
	}

	/**
	 * Print a <url> entry.
	 *
	 * @param string $loc     URL.
	 * @param string $lastmod ISO date.
	 * @param string $inner   Extra inner XML (already escaped).
	 */
	private static function url( $loc, $lastmod = '', $inner = '' ) {
		if ( ! $loc || is_wp_error( $loc ) ) {
			return;
		}
		echo '<url><loc>' . esc_url( $loc ) . '</loc>';
		if ( $lastmod ) {
			echo '<lastmod>' . esc_xml( $lastmod ) . '</lastmod>';
		}
		echo $inner . "</url>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * Google News sitemap: posts published in the last 2 days.
	 */
	private static function news() {
		$extra = array(
			'posts_per_page' => 1000,
			'date_query'     => array(
				array(
					'after'     => '2 days ago',
					'inclusive' => true,
				),
			),
		);
		$lang  = strtolower( substr( get_bloginfo( 'language' ), 0, 2 ) );
		foreach ( self::post_ids( array( 'post' ), 1, $extra ) as $id ) {
			$inner = '<news:news><news:publication><news:name>' . esc_xml( MWSEO_Options::get( 'news_publication' ) ) . '</news:name><news:language>' . esc_xml( $lang ) . '</news:language></news:publication>'
				. '<news:publication_date>' . esc_xml( get_post_time( 'c', true, $id ) ) . '</news:publication_date>'
				. '<news:title>' . esc_xml( get_the_title( $id ) ) . '</news:title></news:news>';
			self::url( get_permalink( $id ), '', $inner );
		}
	}

	/**
	 * Image sitemap: featured and in-content images per post.
	 *
	 * @param int $page Page.
	 */
	private static function images( $page ) {
		foreach ( self::post_ids( (array) MWSEO_Options::get( 'post_types' ), $page, self::media_args( 'image' ), true ) as $id ) {
			$inner = '';
			foreach ( MWSEO_Media_Index::image_urls( $id ) as $src ) {
				$inner .= '<image:image><image:loc>' . esc_url( $src ) . '</image:loc></image:image>';
			}
			if ( $inner ) {
				self::url( get_permalink( $id ), get_post_modified_time( 'c', true, $id ), $inner );
			}
		}
	}

	/**
	 * Video sitemap: YouTube/Vimeo/file videos detected in content.
	 *
	 * @param int $page Page.
	 */
	private static function videos( $page ) {
		foreach ( self::post_ids( (array) MWSEO_Options::get( 'post_types' ), $page, self::media_args( 'video' ), true ) as $id ) {
			$videos = MWSEO_Media_Index::videos( $id );
			if ( ! $videos ) {
				continue;
			}
			$desc  = wp_trim_words( MWSEO_Head::description_for_post( $id ), 40 );
			$inner = '';
			foreach ( $videos as $v ) {
				$inner .= '<video:video><video:thumbnail_loc>' . esc_url( $v['thumb'] ) . '</video:thumbnail_loc>'
					. '<video:title>' . esc_xml( get_the_title( $id ) ) . '</video:title>'
					. '<video:description>' . esc_xml( $desc ? $desc : get_the_title( $id ) ) . '</video:description>';
				if ( $v['file'] ) {
					$inner .= '<video:content_loc>' . esc_url( $v['file'] ) . '</video:content_loc>';
				}
				if ( $v['player'] ) {
					$inner .= '<video:player_loc>' . esc_url( $v['player'] ) . '</video:player_loc>';
				}
				$inner .= '<video:publication_date>' . esc_xml( get_post_time( 'c', true, $id ) ) . '</video:publication_date></video:video>';
			}
			if ( $inner ) {
				self::url( get_permalink( $id ), get_post_modified_time( 'c', true, $id ), $inner );
			}
		}
	}

	/**
	 * [mwseo_html_sitemap] shortcode.
	 *
	 * @return string
	 */
	public static function html_sitemap() {
		if ( ! MWSEO_Options::get( 'sitemap_html' ) ) {
			return '';
		}
		$out = '<div class="mwseo-html-sitemap">';

		$out .= '<h2>' . esc_html__( 'Pages', 'manny-wenas-seo' ) . '</h2><ul>';
		$out .= wp_list_pages(
			array(
				'title_li' => '',
				'echo'     => false,
			)
		);
		$out .= '</ul>';

		$cats = get_categories( array( 'hide_empty' => true ) );
		foreach ( $cats as $cat ) {
			$posts = get_posts(
				array(
					'category'       => $cat->term_id,
					'posts_per_page' => 100,
					'post_status'    => 'publish',
					'meta_query'     => self::post_args( array( 'post' ), 1 )['meta_query'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				)
			);
			if ( ! $posts ) {
				continue;
			}
			$out .= '<h2><a href="' . esc_url( get_category_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a></h2><ul>';
			foreach ( $posts as $p ) {
				$out .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></li>';
			}
			$out .= '</ul>';
		}

		$authors = get_users( array( 'has_published_posts' => true ) );
		if ( $authors ) {
			$out .= '<h2>' . esc_html__( 'Authors', 'manny-wenas-seo' ) . '</h2><ul>';
			foreach ( $authors as $user ) {
				$out .= '<li><a href="' . esc_url( get_author_posts_url( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a></li>';
			}
			$out .= '</ul>';
		}
		return $out . '</div>';
	}
}
