<?php
/**
 * XML sitemap suite and HTML sitemap.
 *
 * @package MannyWenasSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
		if ( MWSEO_Compat::other_active() ) {
			return;
		}
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		add_action( 'init', array( __CLASS__, 'add_rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
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
		if ( post_type_exists( $type ) ) {
			$q = new WP_Query( self::post_args( array( $type ), 1, 1 ) );
			return max( 1, (int) ceil( $q->found_posts / self::PER_PAGE ) );
		}
		return 1;
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
			'orderby'        => 'modified',
			'order'          => 'DESC',
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
				$terms = get_terms(
					array(
						'taxonomy'   => 'category' === $type ? 'category' : 'post_tag',
						'hide_empty' => true,
						'number'     => self::PER_PAGE,
					)
				);
				foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
					self::url( get_term_link( $term ) );
				}
				break;
			case 'author':
				echo "<urlset $ns>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				foreach ( get_users(
					array(
						'has_published_posts' => true,
						'fields'              => 'ID',
						'number'              => self::PER_PAGE,
					)
				) as $uid ) {
					self::url( get_author_posts_url( $uid ) );
				}
				break;
			default:
				echo "<urlset $ns>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				$q = new WP_Query( self::post_args( array( $type ), $page ) );
				foreach ( $q->posts as $id ) {
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
		$args               = self::post_args( array( 'post' ), 1, 1000 );
		$args['date_query'] = array(
			array(
				'after'     => '2 days ago',
				'inclusive' => true,
			),
		);
		$lang               = strtolower( substr( get_bloginfo( 'language' ), 0, 2 ) );
		$q                  = new WP_Query( $args );
		foreach ( $q->posts as $id ) {
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
		$q = new WP_Query( self::post_args( MWSEO_Options::get( 'post_types' ), $page ) );
		foreach ( $q->posts as $id ) {
			$urls  = array();
			$thumb = get_the_post_thumbnail_url( $id, 'full' );
			if ( $thumb ) {
				$urls[] = $thumb;
			}
			preg_match_all( '#<img[^>]+src=["\']([^"\']+)["\']#i', (string) get_post_field( 'post_content', $id ), $m );
			foreach ( $m[1] as $src ) {
				$urls[] = $src;
			}
			$urls  = array_slice( array_unique( $urls ), 0, 1000 );
			$inner = '';
			foreach ( $urls as $src ) {
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
		$q = new WP_Query( self::post_args( MWSEO_Options::get( 'post_types' ), $page ) );
		foreach ( $q->posts as $id ) {
			$content = (string) get_post_field( 'post_content', $id );
			$videos  = array();
			preg_match_all( '#(?:youtube\.com/(?:embed/|watch\?v=)|youtu\.be/)([\w-]{11})#', $content, $yt );
			foreach ( array_unique( $yt[1] ) as $vid ) {
				$videos[] = array(
					'thumb'  => 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
					'player' => 'https://www.youtube.com/embed/' . $vid,
					'file'   => '',
				);
			}
			$feat = get_the_post_thumbnail_url( $id, 'full' );
			preg_match_all( '#vimeo\.com/(?:video/)?(\d+)#', $content, $vm );
			foreach ( array_unique( $vm[1] ) as $vid ) {
				if ( $feat ) {
					$videos[] = array(
						'thumb'  => $feat,
						'player' => 'https://player.vimeo.com/video/' . $vid,
						'file'   => '',
					);
				}
			}
			preg_match_all( '#<(?:video|source)[^>]+src=["\']([^"\']+\.(?:mp4|webm|mov))["\']#i', $content, $fl );
			foreach ( array_unique( $fl[1] ) as $file ) {
				if ( $feat ) {
					$videos[] = array(
						'thumb'  => $feat,
						'player' => '',
						'file'   => $file,
					);
				}
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
	 * Extract a YouTube video ID.
	 *
	 * @param string $url URL.
	 * @return string Empty when not YouTube.
	 */
	public static function youtube_id( $url ) {
		return preg_match( '#(?:youtube\.com/(?:embed/|watch\?v=)|youtu\.be/)([\w-]{11})#', (string) $url, $m ) ? $m[1] : '';
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
