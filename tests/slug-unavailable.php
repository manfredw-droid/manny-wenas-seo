<?php
/**
 * 1.0.9 isolated regressions: the keyphrase-in-slug check while the slug is unavailable (scoring 1.3).
 * php tests/slug-unavailable.php [source-root]
 * Uses the production analyzer and scorer with the few WordPress helpers they call; no WordPress runtime.
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === $number ? $single : $plural; }
function get_locale() { return 'en_US'; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function strip_shortcodes( $text ) { return $text; }
function wp_strip_all_tags( $text ) { return trim( strip_tags( $text ) ); }
function home_url() { return 'https://disposable.example'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
// WordPress supplies fallbacks for these; plain PHP without mbstring does not.
if ( ! function_exists( 'mb_strlen' ) ) { function mb_strlen( $s ) { return strlen( $s ); } }
if ( ! function_exists( 'mb_substr' ) ) { function mb_substr( $s, $a, $l = null ) { return null === $l ? substr( $s, $a ) : substr( $s, $a, $l ); } }
if ( ! function_exists( 'mb_strtolower' ) ) { function mb_strtolower( $s ) { return strtolower( $s ); } }
$root = $argv[1] ?? dirname( __DIR__ );
require $root . '/includes/class-mwseo-analyzer.php';
require $root . '/includes/class-mwseo-scorer.php';
$UNAVAILABLE = 'The URL slug is generated when the post is saved.';
$base = array(
	'title'   => 'Solar garden notes',
	'desc'    => 'Solar garden notes for a quiet afternoon.',
	'focus'   => 'solar garden',
	'related' => array(),
	'content' => '<p>Solar garden paths bring calm. Cedar lantern lights the path. River pebble rests nearby.</p><h2>Solar garden notes</h2><p>Read our <a href="/guide">local guide</a> for more.</p>',
);
function analyse( array $base, array $over ) { return MWSEO_Scorer::score( array_merge( $base, $over ) ); }
function by_id( $analysis ) { return array_column( $analysis['checks'], null, 'id' ); }
function same( $expected, $actual, $label = '' ) {
	if ( $expected !== $actual ) { throw new RuntimeException( $label . ' expected ' . json_encode( $expected ) . '; got ' . json_encode( $actual ) ); }
}
function totals( $analysis ) {
	$earned = 0; $max = 0;
	foreach ( $analysis['checks'] as $c ) { if ( ! $c['na'] ) { $earned += $c['points']; $max += $c['max']; } }
	return array( $earned, $max );
}
$tests = array();
$tests['Scoring version is 1.3'] = function () {
	same( '1.3', MWSEO_Scorer::SCORING_VERSION );
};
$tests['Focus keyphrase and no slug yet: keyphrase-in-slug is N/A, not 0/6'] = function () use ( $base, $UNAVAILABLE ) {
	$c = by_id( analyse( $base, array( 'slug' => '' ) ) )['slug_keyphrase'];
	same( true, $c['na'] );
	same( 'na', $c['classification'] );
	same( 'na', $c['status'] );
	same( 0, $c['points'] );
	same( 6, $c['max'] ); // Declared weight; excluded from the applicable maximum.
	same( $UNAVAILABLE, $c['message'] );
};
$tests['Nonempty slug with the keyphrase: 6/6'] = function () use ( $base ) {
	$c = by_id( analyse( $base, array( 'slug' => 'solar-garden-notes' ) ) )['slug_keyphrase'];
	same( false, $c['na'] );
	same( 'full', $c['classification'] );
	same( 6.0, (float) $c['points'] );
	same( 6, $c['max'] );
};
$tests['Nonempty slug without the keyphrase: 0/6, not N/A'] = function () use ( $base ) {
	$c = by_id( analyse( $base, array( 'slug' => 'quiet-afternoon' ) ) )['slug_keyphrase'];
	same( false, $c['na'] );
	same( 'none', $c['classification'] );
	same( 'bad', $c['status'] );
	same( 0.0, (float) $c['points'] );
	same( 6, $c['max'] );
	same( 'Focus keyphrase not found in the URL slug.', $c['message'] );
};
$tests['Both slug checks give the same explanation while the slug is unavailable'] = function () use ( $base, $UNAVAILABLE ) {
	$checks = by_id( analyse( $base, array( 'slug' => '' ) ) );
	same( $checks['slug_keyphrase']['message'], $checks['clean_slug']['message'] );
	same( $UNAVAILABLE, $checks['clean_slug']['message'] );
	same( true, $checks['clean_slug']['na'] );
};
$tests['No focus keyphrase: keyphrase-in-slug stays N/A with its own reason, with or without a slug'] = function () use ( $base ) {
	foreach ( array( '', 'quiet-afternoon', 'solar-garden' ) as $slug ) {
		$c = by_id( analyse( $base, array( 'focus' => '', 'slug' => $slug ) ) )['slug_keyphrase'];
		same( true, $c['na'], "slug '$slug'" );
		same( 'No focus keyphrase set.', $c['message'], "slug '$slug'" );
	}
};
$tests['N/A leaves the slug check out of the denominator; no other check changes'] = function () use ( $base ) {
	$none = analyse( $base, array( 'slug' => '' ) );
	$some = analyse( $base, array( 'slug' => 'solar-garden-notes' ) );
	list( $e0, $m0 ) = totals( $none );
	list( $e1, $m1 ) = totals( $some );
	same( 9, $m1 - $m0, 'slug keyphrase 6 + clean slug 3 are both left out' );
	same( $e1 - 9.0, (float) $e0, 'only the two slug checks differ in earned points' );
	same( (int) round( 100 * $e0 / $m0 ), $none['score'] );
	$a = by_id( $none ); $b = by_id( $some );
	unset( $a['slug_keyphrase'], $a['clean_slug'], $b['slug_keyphrase'], $b['clean_slug'] );
	same( $b, $a, 'all other checks identical' );
};
// Results recorded for this fixture with the unmodified 1.0.8 scorer (SCORING_VERSION 1.2):
// empty slug 92 (69/75), slug with the keyphrase 100 (78/78), slug without it 92 (72/78).
$tests['Recorded fixture results: only the draft changes (92 becomes 100); nonempty slugs score as in 1.2'] = function () use ( $base ) {
	$draft = analyse( $base, array( 'slug' => '' ) );
	same( array( 69.0, 69.0 ), array_map( 'floatval', totals( $draft ) ), 'draft earned/applicable (1.2: 69/75)' );
	same( 100, $draft['score'], 'draft score (1.2: 92)' );
	$with = analyse( $base, array( 'slug' => 'solar-garden-notes' ) );
	same( array( 78.0, 78.0 ), array_map( 'floatval', totals( $with ) ) );
	same( 100, $with['score'] );
	$without = analyse( $base, array( 'slug' => 'quiet-afternoon' ) );
	same( array( 72.0, 78.0 ), array_map( 'floatval', totals( $without ) ) );
	same( 92, $without['score'] );
};
$failed = 0;
foreach ( $tests as $name => $test ) {
	try { $test(); echo "PASS $name\n"; } catch ( Throwable $e ) { ++$failed; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
echo ( count( $tests ) - $failed ) . ' passed; ' . $failed . " failed. Isolated PHP; not WordPress runtime verification.\n";
exit( $failed ? 1 : 0 );
