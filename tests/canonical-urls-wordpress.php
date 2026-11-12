<?php
/**
 * Canonical URL regression (1.0.9.3). Real HTTP requests against a disposable site.
 *
 *     php tests/canonical-urls-wordpress.php /path/to/wordpress
 *
 * Checks, for every requested URL, that the canonical link is the expected URL and
 * that og:url, the schema WebPage node (@id and url) and the last breadcrumb item
 * agree with it.
 *
 * Fails on 1.0.9.2 for: a separate posts page (canonical pointed at the homepage),
 * a paginated singular post (canonical pointed at page 1) and paginated archives
 * requested with unrelated query parameters (they were copied into the canonical).
 * The ordinary cases (homepage, single post, unpaged archives) must keep passing.
 */

require __DIR__ . '/regression-1093-helpers.php';

/**
 * Canonical, og:url and schema URLs of a page.
 *
 * @param string $html Page HTML.
 * @return array
 */
function mwt_page_urls( $html ) {
	$out = array(
		'canonical'  => null,
		'og'         => null,
		'schema_id'  => null,
		'schema_url' => null,
		'crumb'      => null,
		'count'      => 0,
	);
	if ( preg_match_all( '#<link rel="canonical" href="([^"]*)"#', $html, $m ) ) {
		$out['count']     = count( $m[1] );
		$out['canonical'] = html_entity_decode( $m[1][0], ENT_QUOTES, 'UTF-8' );
	}
	if ( preg_match( '#<meta property="og:url" content="([^"]*)"#', $html, $m ) ) {
		$out['og'] = html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' );
	}
	if ( preg_match( '#<script type="application/ld\+json" class="mwseo-schema-graph">(.*?)</script>#s', $html, $m ) ) {
		$data = json_decode( $m[1], true );
		foreach ( isset( $data['@graph'] ) ? $data['@graph'] : array() as $node ) {
			$type = isset( $node['@type'] ) ? $node['@type'] : '';
			if ( in_array( $type, array( 'WebPage', 'CollectionPage', 'SearchResultsPage' ), true ) ) {
				$out['schema_id']  = $node['@id'];
				$out['schema_url'] = $node['url'];
			}
			if ( 'BreadcrumbList' === $type ) {
				$last         = end( $node['itemListElement'] );
				$out['crumb'] = $last['item'];
			}
		}
	}
	return $out;
}

/**
 * Request a path and compare all URLs with the expected canonical.
 *
 * @param string $label    Case description.
 * @param string $path     Requested path.
 * @param string $expected Expected canonical URL.
 * @param bool   $served   Also request the canonical URL itself: WordPress must serve it and it
 *                         must name itself as canonical.
 */
function mwt_expect_canonical( $label, $path, $expected, $served = false ) {
	$res  = mwt_fetch( $path );
	$urls = mwt_page_urls( $res['body'] );
	if ( $served ) {
		$target = (string) $urls['canonical'];
		$base   = untrailingslashit( home_url() );
		$again  = 0 === strpos( $target, $base ) ? mwt_fetch( (string) substr( $target, strlen( $base ) ) ) : null;
		$self   = $again ? mwt_page_urls( $again['body'] ) : array( 'canonical' => null );
		mwt_check( $again && 200 === $again['code'] && $self['canonical'] === $target, $label . ': the canonical URL is served by WordPress and names itself', 'canonical ' . $target . ' -> HTTP ' . ( $again ? $again['code'] : 'n/a' ) . ', its canonical: ' . var_export( $self['canonical'], true ) );
	}
	mwt_check( 200 === $res['code'] && 1 === $urls['count'], $label . ': page is served with exactly one canonical link', 'GET ' . $path . ' -> HTTP ' . $res['code'] . ', canonical links: ' . $urls['count'] );
	mwt_check( $urls['canonical'] === $expected, $label . ': canonical', 'GET ' . $path . "\n       expected " . $expected . "\n       got      " . var_export( $urls['canonical'], true ) );
	$agree = $urls['og'] === $urls['canonical'] && $urls['schema_id'] === $urls['canonical'] && $urls['schema_url'] === $urls['canonical']
		&& ( null === $urls['crumb'] || $urls['crumb'] === $urls['canonical'] );
	mwt_check( $agree && null !== $urls['canonical'], $label . ': og:url, schema @id/url and breadcrumb agree with the canonical', wp_json_encode( $urls, JSON_UNESCAPED_SLASHES ) );
}

$saved = array();
foreach ( array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page' ) as $option ) {
	$saved[ $option ] = get_option( $option );
}
$post_ids = array();
$term_id  = 0;
$plain    = false;

try {
	if ( '/%postname%/' !== get_option( 'permalink_structure' ) ) {
		throw new RuntimeException( 'The disposable site must use the /%postname%/ permalink structure.' );
	}

	// Fixtures.
	$front      = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'MW Canon Front',
			'post_name'    => 'mw-canon-front',
			'post_content' => "<p>Front part one.</p>\n<!--nextpage-->\n<p>Front part two.</p>",
		)
	);
	$blog       = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => 'MW Canon Blog',
			'post_name'   => 'mw-canon-blog',
		)
	);
	$post_ids[] = $front;
	$post_ids[] = $blog;
	$term       = wp_insert_term( 'MW Canon Cat', 'category', array( 'slug' => 'mw-canon-cat' ) );
	$term_id    = (int) $term['term_id'];
	$author     = get_current_user_id();
	for ( $i = 1; $i <= 5; $i++ ) {
		$post_ids[] = wp_insert_post(
			array(
				'post_status'   => 'publish',
				'post_title'    => 'MW Canon Post ' . $i,
				'post_name'     => 'mw-canon-post-' . $i,
				'post_content'  => '<p>Archive fixture mwcanonword number ' . $i . '.</p>',
				'post_date'     => '2019-03-1' . $i . ' 10:00:00',
				'post_category' => array( $term_id ),
				'post_author'   => $author,
			)
		);
	}
	$single     = $post_ids[2];
	$paged_post = wp_insert_post(
		array(
			'post_status'   => 'publish',
			'post_title'    => 'MW Canon Paged',
			'post_name'     => 'mw-canon-paged',
			'post_content'  => "<p>Part one.</p>\n<!--nextpage-->\n<p>Part two.</p>\n<!--nextpage-->\n<p>Part three.</p>",
			'post_date'     => '2018-06-01 10:00:00',
			'post_category' => array( $term_id ),
		)
	);
	$post_ids[] = $paged_post;

	update_option( 'posts_per_page', 2 );
	$home = home_url( '/' );

	// --- Default homepage: latest posts on the front page (ordinary cases) --------
	update_option( 'show_on_front', 'posts' );
	mwt_expect_canonical( 'default homepage', '/', $home );
	mwt_expect_canonical( 'default homepage with a tracking parameter', '/?utm_source=x', $home );
	mwt_expect_canonical( 'default homepage, page 2', '/page/2/', $home . 'page/2/' );
	mwt_expect_canonical( 'default homepage, page 2 with unrelated parameters', '/page/2/?utm_source=x&foo=bar', $home . 'page/2/' );
	mwt_expect_canonical( 'default homepage: single post', '/mw-canon-post-1/', get_permalink( $single ) );

	// --- "A static page" selected, a Posts page chosen, but no Homepage -----------
	// WordPress then shows the latest posts at the site root as well. The root must stay
	// its own canonical (as in 1.0.9.2) and must not point at the posts page.
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', 0 );
	update_option( 'page_for_posts', $blog );
	mwt_expect_canonical( 'posts page set, no homepage: site root', '/', $home );
	mwt_expect_canonical( 'posts page set, no homepage: site root, page 2 with unrelated parameters', '/page/2/?utm_source=x', $home . 'page/2/' );
	mwt_expect_canonical( 'posts page set, no homepage: the posts page', '/mw-canon-blog/', get_permalink( $blog ) );
	mwt_expect_canonical( 'posts page set, no homepage: the posts page, page 2', '/mw-canon-blog/page/2/', get_permalink( $blog ) . 'page/2/' );

	// --- Static front page and a separate posts page ----------------------------
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $front );
	update_option( 'page_for_posts', $blog );

	$blog_url = get_permalink( $blog );
	$cat_url  = get_category_link( $term_id );

	// --- Pretty permalinks ------------------------------------------------------
	mwt_expect_canonical( 'static front page', '/', $home );
	mwt_expect_canonical( 'static front page with a tracking parameter', '/?utm_source=x', $home );
	mwt_expect_canonical( 'static front page, second content page', '/page/2/', $home . 'page/2/' );

	mwt_expect_canonical( 'posts page', '/mw-canon-blog/', $blog_url );
	mwt_expect_canonical( 'posts page with a tracking parameter', '/mw-canon-blog/?utm_source=x', $blog_url );
	mwt_expect_canonical( 'posts page, page 2', '/mw-canon-blog/page/2/', $blog_url . 'page/2/' );
	mwt_expect_canonical( 'posts page, page 2 with unrelated parameters', '/mw-canon-blog/page/2/?utm_source=x&foo=bar', $blog_url . 'page/2/' );

	mwt_expect_canonical( 'single post', '/mw-canon-post-1/', get_permalink( $single ) );
	mwt_expect_canonical( 'single post with a tracking parameter', '/mw-canon-post-1/?utm_source=x', get_permalink( $single ) );

	mwt_expect_canonical( 'paginated post, page 1', '/mw-canon-paged/', get_permalink( $paged_post ) );
	mwt_expect_canonical( 'paginated post, page 2', '/mw-canon-paged/2/', get_permalink( $paged_post ) . '2/' );
	mwt_expect_canonical( 'paginated post, page 3 with a tracking parameter', '/mw-canon-paged/3/?utm_source=x', get_permalink( $paged_post ) . '3/' );

	mwt_expect_canonical( 'category archive', '/category/mw-canon-cat/', $cat_url );
	mwt_expect_canonical( 'category archive with a tracking parameter', '/category/mw-canon-cat/?utm_source=x', $cat_url );
	mwt_expect_canonical( 'category archive, page 2', '/category/mw-canon-cat/page/2/', $cat_url . 'page/2/' );
	mwt_expect_canonical( 'category archive, page 2 with unrelated parameters', '/category/mw-canon-cat/page/2/?utm_source=x&foo=bar', $cat_url . 'page/2/' );

	mwt_expect_canonical( 'month archive', '/2019/03/', $home . '2019/03/' );
	mwt_expect_canonical( 'month archive, page 2', '/2019/03/page/2/', $home . '2019/03/page/2/' );
	mwt_expect_canonical( 'month archive, page 2 with unrelated parameters', '/2019/03/page/2/?fbclid=abc', $home . '2019/03/page/2/' );

	$author_url = get_author_posts_url( $author );
	mwt_expect_canonical( 'author archive', wp_parse_url( $author_url, PHP_URL_PATH ), $author_url );
	mwt_expect_canonical( 'author archive, page 2 with unrelated parameters', wp_parse_url( $author_url, PHP_URL_PATH ) . 'page/2/?utm_source=x', $author_url . 'page/2/' );

	// Search results are noindex; their canonical is unchanged apart from dropping unrelated parameters.
	mwt_expect_canonical( 'search results (unchanged)', '/?s=mwcanonword', $home );
	mwt_expect_canonical( 'search results, page 2 keeps only the search term', '/page/2/?s=mwcanonword&utm_source=x', $home . 'page/2/?s=mwcanonword' );

	// --- Plain permalinks -------------------------------------------------------
	$plain = true;
	$wp_rewrite->set_permalink_structure( '' );
	flush_rewrite_rules( false );

	mwt_expect_canonical( 'plain: posts page', '/?page_id=' . $blog, $home . '?page_id=' . $blog );
	mwt_expect_canonical( 'plain: single post with a tracking parameter', '/?p=' . $single . '&utm_source=x', $home . '?p=' . $single );
	mwt_expect_canonical( 'plain: paginated post, page 2', '/?p=' . $paged_post . '&page=2', $home . '?p=' . $paged_post . '&page=2' );
	mwt_expect_canonical( 'plain: category archive', '/?cat=' . $term_id, $home . '?cat=' . $term_id );
	mwt_expect_canonical( 'plain: category archive, page 2 with unrelated parameters', '/?cat=' . $term_id . '&paged=2&utm_source=x', $home . '?cat=' . $term_id . '&paged=2' );
	mwt_expect_canonical( 'plain: month archive, page 2 with unrelated parameters', '/?m=201903&paged=2&utm_source=x', $home . '?m=201903&paged=2' );
	// Requests that only WordPress's own query variables identify keep exactly those variables on later pages.
	mwt_expect_canonical( 'plain: week archive, page 2 with unrelated parameters', '/?m=2019&w=11&paged=2&utm_source=x', $home . '?m=2019&w=11&paged=2' );
	mwt_expect_canonical( 'plain: search results, page 2 with unrelated parameters', '/?s=mwcanonword&paged=2&utm_source=x', $home . '?s=mwcanonword&paged=2' );
	// As in 1.0.9.2, the first page of these requests still points at the homepage (known limitation); it now has its trailing slash.
	mwt_expect_canonical( 'plain: month archive, first page (still the homepage, as in 1.0.9.2)', '/?m=201903', $home );
	mwt_expect_canonical( 'plain: search results, first page (still the homepage, as in 1.0.9.2)', '/?s=mwcanonword', $home );

	// --- PATHINFO permalinks: every pretty URL carries the index.php segment -------
	// Used where the web server cannot rewrite URLs. A URL without the segment is not served
	// there, so each canonical is also requested itself.
	$wp_rewrite->set_permalink_structure( '/index.php/%postname%/' );
	flush_rewrite_rules( false );
	$ix       = $home . 'index.php/';
	// Written out by hand: this process registered its taxonomy link structures before the switch.
	$blog_url = $ix . 'mw-canon-blog/';
	$cat_url  = $ix . 'category/mw-canon-cat/';
	$auth_url = $ix . 'author/' . get_userdata( $author )->user_nicename . '/';
	$post_url = $ix . 'mw-canon-post-1/';
	mwt_expect_canonical( 'pathinfo: static front page', '/', $home, true );
	// Not covered: later content pages of a static front page. WordPress itself redirects every
	// form of that address to /page/2/, which this structure cannot serve, so there is no page to check.
	mwt_expect_canonical( 'pathinfo: posts page', '/index.php/mw-canon-blog/', $blog_url, true );
	mwt_expect_canonical( 'pathinfo: posts page, page 2 with unrelated parameters', '/index.php/mw-canon-blog/page/2/?utm_source=x', $blog_url . 'page/2/', true );
	mwt_expect_canonical( 'pathinfo: single post with a tracking parameter', '/index.php/mw-canon-post-1/?utm_source=x', $post_url, true );
	mwt_expect_canonical( 'pathinfo: paginated post, page 2', '/index.php/mw-canon-paged/2/', $ix . 'mw-canon-paged/2/', true );
	mwt_expect_canonical( 'pathinfo: category archive', '/index.php/category/mw-canon-cat/', $cat_url, true );
	mwt_expect_canonical( 'pathinfo: category archive, page 2 with unrelated parameters', '/index.php/category/mw-canon-cat/page/2/?utm_source=x', $cat_url . 'page/2/', true );
	mwt_expect_canonical( 'pathinfo: author archive, page 2 with unrelated parameters', wp_parse_url( $auth_url, PHP_URL_PATH ) . 'page/2/?utm_source=x', $auth_url . 'page/2/', true );
	mwt_expect_canonical( 'pathinfo: month archive', '/index.php/2019/03/', $ix . '2019/03/', true );
	mwt_expect_canonical( 'pathinfo: month archive, page 2', '/index.php/2019/03/page/2/', $ix . '2019/03/page/2/', true );
	mwt_expect_canonical( 'pathinfo: month archive, page 2 with unrelated parameters', '/index.php/2019/03/page/2/?fbclid=abc', $ix . '2019/03/page/2/', true );
	mwt_expect_canonical( 'pathinfo: day archive', '/index.php/2019/03/11/', $ix . '2019/03/11/', true );
	mwt_expect_canonical( 'pathinfo: search results, first page (the homepage, as before)', '/?s=mwcanonword', $home, true );
	mwt_expect_canonical( 'pathinfo: search results, page 2 keeps only the search term', '/index.php/page/2/?s=mwcanonword&utm_source=x', $ix . 'page/2/?s=mwcanonword', true );
	update_option( 'show_on_front', 'posts' );
	mwt_expect_canonical( 'pathinfo: default homepage', '/', $home, true );
	mwt_expect_canonical( 'pathinfo: default homepage, page 2 with unrelated parameters', '/index.php/page/2/?utm_source=x', $ix . 'page/2/', true );
} catch ( Throwable $e ) {
	mwt_check( false, 'test ran to completion', get_class( $e ) . ': ' . $e->getMessage() );
} finally {
	if ( $plain ) {
		$wp_rewrite->set_permalink_structure( '/%postname%/' );
		flush_rewrite_rules( false );
	}
	foreach ( $saved as $option => $value ) {
		update_option( $option, $value );
	}
	foreach ( $post_ids as $id ) {
		wp_delete_post( $id, true );
	}
	if ( $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
}

mwt_finish( 'canonical-urls' );
