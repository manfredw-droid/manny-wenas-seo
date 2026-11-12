<?php
defined( 'ABSPATH' ) || exit;
/**
 * Front-end head output: title, description, robots, canonical, Open Graph, Twitter/X.
 *
 * @package MannyWenasSEO
 */

/**
 * Head tags.
 */
class MWSEO_Head {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( MWSEO_Compat::other_active() ) {
			return;
		}
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 20 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 20 );
		add_action( 'wp', array( __CLASS__, 'swap_canonical' ) );
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
	}

	/**
	 * Replace core's canonical with ours.
	 */
	public static function swap_canonical() {
		remove_action( 'wp_head', 'rel_canonical' );
	}

	/**
	 * Canonical URL for the current request.
	 *
	 * @return string
	 */
	public static function canonical() {
		$url        = '';
		$by_request = false;
		if ( is_singular() ) {
			$id   = get_queried_object_id();
			$url  = get_permalink( $id );
			$page = (int) get_query_var( 'page' );
			// A post split with <!--nextpage--> has one URL per content page; each is its own canonical.
			if ( $url && $page > 1 && $page <= self::content_pages( $id ) ) {
				$url = self::singular_page_url( $id, $url, $page );
			}
		} elseif ( is_front_page() ) {
			$url = home_url( '/' );
		} elseif ( is_home() ) {
			// A separate posts page ("Posts page" under Settings > Reading) has its own URL. Only the
			// request for that page counts: with a posts page but no homepage selected, WordPress
			// also shows the latest posts at the site root, which stays its own canonical.
			$posts_page = (int) get_option( 'page_for_posts' );
			$url        = $posts_page && (int) get_queried_object_id() === $posts_page ? get_permalink( $posts_page ) : home_url( '/' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$url = get_term_link( get_queried_object() );
		} elseif ( is_author() ) {
			$url = get_author_posts_url( get_queried_object_id() );
		} elseif ( is_post_type_archive() ) {
			$url = get_post_type_archive_link( get_query_var( 'post_type' ) );
		} else {
			// Date archives, search results and anything else: the requested path, as before.
			$by_request = true;
			$url        = self::request_url();
		}
		if ( is_wp_error( $url ) || ! $url ) {
			$url = home_url( '/' );
		}
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 && ! is_singular() ) {
			// Built from the clean URL above, never from the request URI, so tracking and other
			// unrelated query parameters cannot end up in the canonical.
			$query = $by_request ? self::request_query_vars() : array();
			if ( $query && ! get_option( 'permalink_structure' ) ) {
				$url = self::paged_url( add_query_arg( $query, $url ), $paged );
			} else {
				$url = self::paged_url( $url, $paged );
				$url = $query ? add_query_arg( $query, $url ) : $url;
			}
		}
		return apply_filters( 'mwseo_canonical', $url );
	}

	/**
	 * WordPress's own query variables of a request that only they identify, for example
	 * ?s=term, ?m=201903 or ?m=2019&w=11. They are part of the address of a later page;
	 * every other parameter of the request is left out.
	 *
	 * @return array Variable => URL-encoded value.
	 */
	private static function request_query_vars() {
		global $wp;
		$out = array();
		foreach ( (array) $wp->public_query_vars as $var ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: which variables the request used.
			if ( 'paged' === $var || 'page' === $var || ! isset( $_GET[ $var ], $wp->query_vars[ $var ] ) ) {
				continue;
			}
			$value = $wp->query_vars[ $var ];
			if ( is_scalar( $value ) && '' !== (string) $value ) {
				$out[ $var ] = rawurlencode( wp_unslash( (string) $value ) );
			}
		}
		return $out;
	}

	/**
	 * Number of content pages of a post (1 without <!--nextpage-->).
	 *
	 * @param int $id Post ID.
	 * @return int
	 */
	private static function content_pages( $id ) {
		$post = get_post( $id );
		return $post ? substr_count( (string) $post->post_content, '<!--nextpage-->' ) + 1 : 1;
	}

	/**
	 * URL of a later content page of a paginated post, built like WordPress's own page links.
	 *
	 * @param int    $id   Post ID.
	 * @param string $url  Permalink of the post.
	 * @param int    $page Content page (2 or higher).
	 * @return string
	 */
	private static function singular_page_url( $id, $url, $page ) {
		global $wp_rewrite;
		if ( ! get_option( 'permalink_structure' ) || false !== strpos( $url, '?' ) ) {
			return add_query_arg( 'page', $page, $url );
		}
		if ( 'page' === get_option( 'show_on_front' ) && (int) get_option( 'page_on_front' ) === (int) $id ) {
			return trailingslashit( $url ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $page, 'single_paged' );
		}
		return trailingslashit( $url ) . user_trailingslashit( $page, 'single_paged' );
	}

	/**
	 * URL of a later page of an archive, appended to its clean first-page URL.
	 *
	 * @param string $url   URL of the first page.
	 * @param int    $paged Page number (2 or higher).
	 * @return string
	 */
	private static function paged_url( $url, $paged ) {
		global $wp_rewrite;
		if ( ! get_option( 'permalink_structure' ) || false !== strpos( $url, '?' ) ) {
			return add_query_arg( 'paged', $paged, $url );
		}
		return trailingslashit( self::with_index( $url ) ) . user_trailingslashit( $wp_rewrite->pagination_base . '/' . $paged, 'paged' );
	}

	/**
	 * Add the index.php segment that "PATHINFO" permalinks (/index.php/%postname%/) need.
	 *
	 * With that structure WordPress serves pretty URLs only below /index.php/. Links built by
	 * WordPress already carry the segment; a URL put together from the home URL or from the
	 * requested path does not, and without it the web server would not find the page. The
	 * site root itself and query-string URLs are left as they are.
	 *
	 * @param string $url URL on this site.
	 * @return string
	 */
	private static function with_index( $url ) {
		global $wp_rewrite;
		if ( ! $wp_rewrite->using_index_permalinks() ) {
			return $url;
		}
		$home = trailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) ) {
			return $url;
		}
		$rest = (string) substr( $url, strlen( $home ) );
		if ( $rest === $wp_rewrite->index || 0 === strpos( $rest, $wp_rewrite->index . '/' ) || ( '' !== $rest && '?' === $rest[0] ) ) {
			return $url;
		}
		return $home . $wp_rewrite->index . '/' . $rest;
	}

	/**
	 * URL of the requested path without its pagination segment, for requests no rule above covers.
	 *
	 * @return string
	 */
	private static function request_url() {
		global $wp_rewrite;
		$request = preg_replace( '#(^|/)' . preg_quote( $wp_rewrite->pagination_base, '#' ) . '/[0-9]+/?$#', '', (string) $GLOBALS['wp']->request );
		return '' === (string) $request ? home_url( '/' ) : self::with_index( home_url( user_trailingslashit( $request ) ) );
	}

	/**
	 * Resolve the SEO title.
	 *
	 * @param string $title Existing pre-title (empty by default).
	 * @return string
	 */
	public static function title( $title ) {
		if ( is_singular() ) {
			$id     = get_queried_object_id();
			$custom = MWSEO_Meta::get( $id, 'title' );
			if ( $custom ) {
				// A legacy (Yoast / Rank Math) title is only used when it resolves completely. Our own
				// _mwseo_title row, even an empty one, is never second-guessed. A legacy template that
				// still holds variables falls back to the default title format, which can replace
				// custom wording that was stored in that legacy title.
				$legacy = ! metadata_exists( 'post', $id, '_mwseo_title' );
				if ( ! $legacy || ! self::has_unresolved_vars( $custom ) ) {
					return self::replace_vars( $custom, get_the_title() );
				}
			}
			return self::replace_vars( MWSEO_Options::get( 'title_template' ), get_the_title() );
		}
		return $title;
	}

	/**
	 * Does a title template still contain variables replace_vars() cannot resolve?
	 *
	 * Only the template is inspected, never the finished title, so a post title such as
	 * "example %name%" cannot trigger it.
	 *
	 * @param string $template Title template.
	 * @return bool
	 */
	private static function has_unresolved_vars( $template ) {
		$known = strtr(
			(string) $template,
			array(
				'%%title%%'    => '',
				'%%sitename%%' => '',
				'%%tagline%%'  => '',
				'%%sep%%'      => '',
			)
		);
		return (bool) preg_match( '/%{1,2}[A-Za-z][A-Za-z0-9_]*%{1,2}/', $known );
	}

	/**
	 * Replace %%vars%% in a title template.
	 *
	 * @param string $template Template.
	 * @param string $title    Post title.
	 * @return string
	 */
	public static function replace_vars( $template, $title ) {
		$out = strtr(
			$template,
			array(
				'%%title%%'    => $title,
				'%%sitename%%' => get_bloginfo( 'name' ),
				'%%tagline%%'  => get_bloginfo( 'description' ),
				'%%sep%%'      => MWSEO_Options::get( 'title_separator' ),
			)
		);
		return trim( wp_strip_all_tags( $out ) );
	}

	/**
	 * Meta description for a post: explicit, else excerpt, else trimmed content.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	public static function description_for_post( $id ) {
		$desc = MWSEO_Meta::get( $id, 'desc' );
		if ( ! $desc ) {
			// Public descriptions must not depend on a visitor's password cookie.
			$post = get_post( $id );
			if ( $post && '' !== (string) $post->post_password ) {
				return '';
			}
			if ( has_excerpt( $id ) ) {
				$desc = get_the_excerpt( $id );
			} else {
				// Keep a space between blocks so words from adjacent paragraphs/headings do not fuse.
				$content = preg_replace( '#</(p|h[1-6]|li|div|blockquote|figcaption)>#i', '$0 ', (string) get_post_field( 'post_content', $id ) );
				$desc    = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $content ) ), 28, '…' );
			}
		}
		return trim( wp_strip_all_tags( $desc ) );
	}

	/**
	 * Meta description for the current request.
	 *
	 * @return string
	 */
	public static function description() {
		if ( is_singular() ) {
			return self::description_for_post( get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return trim( wp_strip_all_tags( term_description() ) );
		}
		if ( is_front_page() ) {
			return get_bloginfo( 'description' );
		}
		return '';
	}

	/**
	 * Robots directives.
	 *
	 * @param array $robots Robots map.
	 * @return array
	 */
	public static function robots( $robots ) {
		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( MWSEO_Meta::get( $id, 'noindex' ) ) {
				$robots['noindex'] = true;
				unset( $robots['index'] );
			}
			if ( MWSEO_Meta::get( $id, 'nofollow' ) ) {
				$robots['nofollow'] = true;
			}
		} elseif ( is_search() || is_404() ) {
			$robots['noindex'] = true;
		}
		$robots['max-image-preview'] = 'large';
		return $robots;
	}

	/**
	 * Output all head tags.
	 */
	public static function output() {
		$canonical = self::canonical();
		$desc      = self::description();

		echo "\n<!-- Manny Wenas SEO " . esc_html( MWSEO_VERSION ) . " -->\n";
		echo '<link rel="canonical" href="' . esc_url( $canonical ) . '" />' . "\n";
		if ( $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		self::open_graph( $canonical, $desc );
		self::twitter( $desc );
		echo "<!-- / Manny Wenas SEO -->\n";
	}

	/**
	 * Page title as used for social cards.
	 *
	 * @return string
	 */
	private static function social_title() {
		$title = wp_get_document_title();
		return $title ? $title : get_bloginfo( 'name' );
	}

	/**
	 * Image data for social cards: featured image, else default image.
	 *
	 * @return array|null url/width/height/alt/mime.
	 */
	public static function social_image() {
		$att = 0;
		if ( is_singular() && has_post_thumbnail( get_queried_object_id() ) ) {
			$att = get_post_thumbnail_id( get_queried_object_id() );
		}
		if ( ! $att ) {
			$att = (int) MWSEO_Options::get( 'default_image' );
		}
		if ( ! $att ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $att, 'full' );
		if ( ! $src ) {
			return null;
		}
		return array(
			'url'    => $src[0],
			'width'  => $src[1],
			'height' => $src[2],
			'alt'    => trim( (string) get_post_meta( $att, '_wp_attachment_image_alt', true ) ),
			'mime'   => get_post_mime_type( $att ),
		);
	}

	/**
	 * Open Graph tags (current spec; no deprecated tags such as fb:admins or og:updated_time).
	 *
	 * @param string $url  Canonical URL.
	 * @param string $desc Description.
	 */
	private static function open_graph( $url, $desc ) {
		$is_article = is_singular( 'post' );
		$tags       = array(
			'og:locale'      => str_replace( '-', '_', get_bloginfo( 'language' ) ),
			'og:type'        => $is_article ? 'article' : 'website',
			'og:title'       => self::social_title(),
			'og:description' => $desc,
			'og:url'         => $url,
			'og:site_name'   => get_bloginfo( 'name' ),
		);
		foreach ( $tags as $prop => $val ) {
			if ( '' !== (string) $val ) {
				echo '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $val ) . '" />' . "\n";
			}
		}

		$img = self::social_image();
		if ( $img ) {
			echo '<meta property="og:image" content="' . esc_url( $img['url'] ) . '" />' . "\n";
			echo '<meta property="og:image:width" content="' . (int) $img['width'] . '" />' . "\n";
			echo '<meta property="og:image:height" content="' . (int) $img['height'] . '" />' . "\n";
			if ( $img['mime'] ) {
				echo '<meta property="og:image:type" content="' . esc_attr( $img['mime'] ) . '" />' . "\n";
			}
			if ( $img['alt'] ) {
				echo '<meta property="og:image:alt" content="' . esc_attr( $img['alt'] ) . '" />' . "\n";
			}
		}

		if ( $is_article ) {
			$post = get_queried_object();
			echo '<meta property="article:published_time" content="' . esc_attr( get_post_time( 'c', true, $post ) ) . '" />' . "\n";
			echo '<meta property="article:modified_time" content="' . esc_attr( get_post_modified_time( 'c', true, $post ) ) . '" />' . "\n";
			echo '<meta property="article:author" content="' . esc_url( get_author_posts_url( $post->post_author ) ) . '" />' . "\n";
			$cats = get_the_category( $post->ID );
			if ( $cats ) {
				echo '<meta property="article:section" content="' . esc_attr( $cats[0]->name ) . '" />' . "\n";
			}
			foreach ( (array) get_the_tags( $post->ID ) as $tag ) {
				if ( $tag ) {
					echo '<meta property="article:tag" content="' . esc_attr( $tag->name ) . '" />' . "\n";
				}
			}
		}

		$app_id = MWSEO_Options::get( 'fb_app_id' );
		if ( $app_id ) {
			echo '<meta property="fb:app_id" content="' . esc_attr( $app_id ) . '" />' . "\n";
		}
	}

	/**
	 * Twitter/X card tags.
	 *
	 * @param string $desc Description.
	 */
	private static function twitter( $desc ) {
		$img  = self::social_image();
		$tags = array(
			'twitter:card'        => $img ? 'summary_large_image' : 'summary',
			'twitter:title'       => self::social_title(),
			'twitter:description' => $desc,
			'twitter:image'       => $img ? $img['url'] : '',
			'twitter:image:alt'   => $img ? $img['alt'] : '',
			'twitter:site'        => self::handle( MWSEO_Options::get( 'twitter_site' ) ),
		);
		foreach ( $tags as $name => $val ) {
			if ( '' !== (string) $val ) {
				echo '<meta name="' . esc_attr( $name ) . '" content="' . esc_attr( $val ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Normalise a Twitter handle to @name.
	 *
	 * @param string $handle Handle or URL.
	 * @return string
	 */
	private static function handle( $handle ) {
		$handle = trim( (string) $handle );
		if ( '' === $handle ) {
			return '';
		}
		$handle = preg_replace( '#^https?://(www\.)?(twitter|x)\.com/#i', '', $handle );
		return '@' . ltrim( trim( $handle, '/' ), '@' );
	}
}
