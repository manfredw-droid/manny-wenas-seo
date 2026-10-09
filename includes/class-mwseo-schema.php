<?php
/**
 * Connected JSON-LD @graph, rendered server-side.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Schema graph builder.
 *
 * ID structure: {home}/#/schema/{Type}/{id}.
 * - WebPage @id is the canonical URL.
 * - Organization and WebSite are always id "1".
 * - Person ids are obfuscated (salted hash of the user ID).
 * - image / logo / thumbnailUrl are always arrays of ImageObject references.
 */
class MWSEO_Schema {

	/**
	 * Collected nodes keyed by @id (dedupes images and people).
	 *
	 * @var array
	 */
	private static $nodes = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( MWSEO_Compat::other_active() ) {
			return;
		}
		add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
	}

	/**
	 * Build a schema @id.
	 *
	 * @param string     $type Schema type.
	 * @param string|int $key  Identifier.
	 * @return string
	 */
	public static function id( $type, $key = '1' ) {
		return home_url( '/#/schema/' . $type . '/' . $key );
	}

	/**
	 * Obfuscated Person id for a user.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function person_id( $user_id ) {
		$seed = hash( 'sha256', get_home_url() );
		return self::id( 'Person', md5( $seed . (int) $user_id ) );
	}

	/**
	 * Print the JSON-LD script.
	 */
	public static function output() {
		$graph = self::build();
		if ( ! $graph ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);
		echo '<script type="application/ld+json" class="mwseo-schema-graph">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX_TAG/AMP.
	}

	/**
	 * Build the graph for the current request.
	 *
	 * @return array
	 */
	public static function build() {
		self::$nodes = array();

		$canonical = MWSEO_Head::canonical();
		$website   = self::website();
		$publisher = self::publisher();

		self::add( $website );

		$page_id = $canonical;
		$page    = array(
			'@type'      => self::page_type(),
			'@id'        => $page_id,
			'url'        => $canonical,
			'name'       => wp_get_document_title(),
			'isPartOf'   => array( '@id' => $website['@id'] ),
			'inLanguage' => get_bloginfo( 'language' ),
		);

		$crumb = self::breadcrumb( $canonical );
		if ( $crumb ) {
			self::add( $crumb );
			$page['breadcrumb'] = array( '@id' => $crumb['@id'] );
		}

		$desc = MWSEO_Head::description();
		if ( $desc ) {
			$page['description'] = $desc;
		}

		$img_refs = array();
		$img      = MWSEO_Head::social_image();
		if ( $img ) {
			$att_id = is_singular() && has_post_thumbnail() ? get_post_thumbnail_id() : (int) MWSEO_Options::get( 'default_image' );
			$node   = self::image_node( $att_id );
			if ( $node ) {
				self::add( $node );
				$img_refs                   = array( array( '@id' => $node['@id'] ) );
				$page['primaryImageOfPage'] = array( '@id' => $node['@id'] );
				$page['image']              = $img_refs;
			}
		}

		if ( is_singular() ) {
			$post                  = get_queried_object();
			$page['datePublished'] = get_post_time( 'c', true, $post );
			$page['dateModified']  = get_post_modified_time( 'c', true, $post );
		}

		if ( $publisher ) {
			self::add( $publisher );
		}

		if ( is_singular( 'post' ) ) {
			$article = self::article( $post, $page_id, $publisher, $img_refs );
			self::add( $article );
			$page['mainEntity'] = array( '@id' => $article['@id'] );
		}
		self::add( $page );

		return apply_filters( 'mwseo_schema_graph', array_values( self::$nodes ) );
	}

	/**
	 * Register a node, merging by @id.
	 *
	 * @param array $node Node.
	 */
	private static function add( array $node ) {
		if ( empty( $node['@id'] ) ) {
			return;
		}
		self::$nodes[ $node['@id'] ] = isset( self::$nodes[ $node['@id'] ] ) ? array_merge( self::$nodes[ $node['@id'] ], $node ) : $node;
	}

	/**
	 * WebPage subtype for the current request.
	 *
	 * @return string
	 */
	private static function page_type() {
		if ( is_search() ) {
			return 'SearchResultsPage';
		}
		if ( is_archive() || ( is_home() && ! is_front_page() ) ) {
			return 'CollectionPage';
		}
		return 'WebPage';
	}

	/**
	 * WebSite node (always id 1).
	 *
	 * @return array
	 */
	private static function website() {
		$publisher = self::publisher();
		$node      = array(
			'@type'           => 'WebSite',
			'@id'             => self::id( 'WebSite', '1' ),
			'url'             => home_url( '/' ),
			'name'            => get_bloginfo( 'name' ),
			'description'     => get_bloginfo( 'description' ),
			'inLanguage'      => get_bloginfo( 'language' ),
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/?s={search_term_string}' ),
					),
					'query-input' => 'required name=search_term_string',
				),
			),
		);
		if ( $publisher ) {
			$node['publisher'] = array( '@id' => $publisher['@id'] );
		}
		return $node;
	}

	/**
	 * Publisher: Organization (id 1), Person, or the hybrid [Person, Organization].
	 *
	 * @return array|null
	 */
	private static function publisher() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$mode     = MWSEO_Options::get( 'site_represents' );
		$user_id  = (int) MWSEO_Options::get( 'person_user' );
		$user     = $user_id ? get_userdata( $user_id ) : false;
		$profiles = array_values( array_filter( array_map( 'esc_url_raw', preg_split( '/\R+/', (string) MWSEO_Options::get( 'social_profiles' ) ) ) ) );

		$logo      = array();
		$logo_node = self::image_node( (int) MWSEO_Options::get( 'org_logo' ) );
		if ( $logo_node ) {
			self::add( $logo_node );
			$logo = array( array( '@id' => $logo_node['@id'] ) );
		}

		if ( 'person' === $mode && $user ) {
			$node = array(
				'@type' => 'Person',
				'@id'   => self::person_id( $user_id ),
				'name'  => $user->display_name,
				'url'   => home_url( '/' ),
			);
		} else {
			$node = array(
				'@type' => 'Organization',
				'@id'   => self::id( 'Organization', '1' ),
				'name'  => MWSEO_Options::get( 'org_name' ) ? MWSEO_Options::get( 'org_name' ) : get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			);
			if ( 'both' === $mode && $user ) {
				$node['@type'] = array( 'Person', 'Organization' );
				$node['name']  = $user->display_name;
			}
		}
		if ( $logo ) {
			$node['logo']  = $logo;
			$node['image'] = $logo;
		}
		if ( $profiles ) {
			$node['sameAs'] = $profiles;
		}
		$cache = $node;
		return $cache;
	}

	/**
	 * Person node for a post author (obfuscated id), or a reference to the hybrid node.
	 *
	 * @param int $user_id Author ID.
	 * @return array Reference array with @id.
	 */
	private static function author_ref( $user_id ) {
		$mode = MWSEO_Options::get( 'site_represents' );
		if ( in_array( $mode, array( 'both', 'person' ), true ) && (int) MWSEO_Options::get( 'person_user' ) === (int) $user_id ) {
			$pub = self::publisher();
			if ( $pub ) {
				return array( '@id' => $pub['@id'] );
			}
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return array();
		}
		$node = array(
			'@type' => 'Person',
			'@id'   => self::person_id( $user_id ),
			'name'  => $user->display_name,
			'url'   => get_author_posts_url( $user_id ),
		);
		self::add( $node );
		return array( '@id' => $node['@id'] );
	}

	/**
	 * Article / NewsArticle node.
	 *
	 * @param WP_Post    $post      Post.
	 * @param string     $page_id   WebPage @id.
	 * @param array|null $publisher Publisher node.
	 * @param array      $img_refs  Image reference array.
	 * @return array
	 */
	private static function article( $post, $page_id, $publisher, array $img_refs ) {
		$type   = apply_filters( 'mwseo_article_type', MWSEO_Options::get( 'article_type' ), $post );
		$type   = in_array( $type, array( 'Article', 'NewsArticle', 'BlogPosting' ), true ) ? $type : 'Article';
		$node   = array(
			'@type'            => $type,
			'@id'              => self::id( $type, $post->ID ),
			'isPartOf'         => array( '@id' => $page_id ),
			'mainEntityOfPage' => array( '@id' => $page_id ),
			'headline'         => get_the_title( $post ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'wordCount'        => str_word_count( wp_strip_all_tags( $post->post_content ) ),
			'inLanguage'       => get_bloginfo( 'language' ),
		);
		$author = self::author_ref( (int) $post->post_author );
		if ( $author ) {
			$node['author'] = $author;
		}
		if ( $publisher ) {
			$node['publisher'] = array( '@id' => $publisher['@id'] );
		}
		if ( $img_refs ) {
			$node['image'] = $img_refs;
		}
		$cats = get_the_category( $post->ID );
		if ( $cats ) {
			$node['articleSection'] = wp_list_pluck( $cats, 'name' );
		}
		$tags = get_the_tags( $post->ID );
		if ( $tags ) {
			$node['keywords'] = implode( ', ', wp_list_pluck( $tags, 'name' ) );
		}
		return $node;
	}

	/**
	 * BreadcrumbList node.
	 *
	 * @param string $canonical Canonical URL.
	 * @return array|null
	 */
	private static function breadcrumb( $canonical ) {
		if ( is_front_page() ) {
			return null;
		}
		$items = array(
			array(
				'name' => get_bloginfo( 'name' ),
				'url'  => home_url( '/' ),
			),
		);
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( 'post' === $post->post_type ) {
				$cats = get_the_category( $post->ID );
				if ( $cats ) {
					$items[] = array(
						'name' => $cats[0]->name,
						'url'  => get_category_link( $cats[0] ),
					);
				}
			} elseif ( is_post_type_viewable( $post->post_type ) ) {
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $anc ) {
					$items[] = array(
						'name' => get_the_title( $anc ),
						'url'  => get_permalink( $anc ),
					);
				}
			}
			$items[] = array(
				'name' => get_the_title( $post ),
				'url'  => $canonical,
			);
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$items[] = array(
				'name' => single_term_title( '', false ),
				'url'  => $canonical,
			);
		} elseif ( is_author() ) {
			$items[] = array(
				'name' => get_the_author_meta( 'display_name', get_queried_object_id() ),
				'url'  => $canonical,
			);
		} else {
			$items[] = array(
				'name' => wp_get_document_title(),
				'url'  => $canonical,
			);
		}

		$list = array();
		foreach ( $items as $i => $item ) {
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'name'     => wp_strip_all_tags( $item['name'] ),
				'item'     => $item['url'],
			);
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => self::id( 'BreadcrumbList', md5( $canonical ) ),
			'itemListElement' => $list,
		);
	}

	/**
	 * ImageObject node for an attachment.
	 *
	 * @param int $att_id Attachment ID.
	 * @return array|null
	 */
	private static function image_node( $att_id ) {
		if ( ! $att_id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $att_id, 'full' );
		if ( ! $src ) {
			return null;
		}
		$node = array(
			'@type'      => 'ImageObject',
			'@id'        => self::id( 'ImageObject', $att_id ),
			'url'        => $src[0],
			'contentUrl' => $src[0],
			'width'      => $src[1],
			'height'     => $src[2],
			'inLanguage' => get_bloginfo( 'language' ),
		);
		$alt  = trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) );
		if ( $alt ) {
			$node['caption'] = $alt;
		}
		return $node;
	}
}
