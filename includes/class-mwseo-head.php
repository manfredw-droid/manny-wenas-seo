<?php
/**
 * Front-end head output: title, description, robots, canonical, Open Graph, Twitter/X.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Head tags.
 */
class MWSEO_Head {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( MWSEO_Yoast::is_active() ) {
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
		if ( is_singular() ) {
			$url = get_permalink( get_queried_object_id() );
		} elseif ( is_front_page() || is_home() ) {
			$url = home_url( '/' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$url = get_term_link( get_queried_object() );
		} elseif ( is_author() ) {
			$url = get_author_posts_url( get_queried_object_id() );
		} elseif ( is_post_type_archive() ) {
			$url = get_post_type_archive_link( get_query_var( 'post_type' ) );
		} else {
			$url = home_url( user_trailingslashit( $GLOBALS['wp']->request ) );
		}
		if ( is_wp_error( $url ) || ! $url ) {
			$url = home_url( '/' );
		}
		$paged = (int) get_query_var( 'paged' );
		if ( $paged > 1 && ! is_singular() ) {
			$url = get_pagenum_link( $paged );
		}
		return apply_filters( 'mwseo_canonical', $url );
	}

	/**
	 * Resolve the SEO title.
	 *
	 * @param string $title Existing pre-title (empty by default).
	 * @return string
	 */
	public static function title( $title ) {
		if ( is_singular() ) {
			$custom = MWSEO_Meta::get( get_queried_object_id(), 'title' );
			if ( $custom ) {
				return self::replace_vars( $custom, get_the_title() );
			}
			return self::replace_vars( MWSEO_Options::get( 'title_template' ), get_the_title() );
		}
		return $title;
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
			$desc = has_excerpt( $id ) ? get_the_excerpt( $id ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( get_post_field( 'post_content', $id ) ) ), 28, '…' );
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
			'og:locale'    => str_replace( '-', '_', get_bloginfo( 'language' ) ),
			'og:type'      => $is_article ? 'article' : 'website',
			'og:title'     => self::social_title(),
			'og:description' => $desc,
			'og:url'       => $url,
			'og:site_name' => get_bloginfo( 'name' ),
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
