<?php
/**
 * Article schema wordCount must follow the analyzer's word-counting contract.
 * php tests/schema-word-count.php [source-root]
 *
 * Runs the actual production MWSEO_Schema::article() (via reflection) and
 * MWSEO_Scorer::score(). WordPress is not loaded: the helpers below are
 * deterministic shims for these literal fixtures only (no shortcodes, and
 * wpautop is a simplified stand-in used by one fixture). This is isolated PHP
 * evidence, not WordPress runtime verification.
 *
 * The contract is what MWSEO_Analyzer::words() does today: runs of Unicode
 * letters/digits plus apostrophe, right single quote and hyphen. It does not
 * segment unspaced scripts (e.g. Chinese) and makes no language claims.
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $number, $domain = '' ) { return 1 === $number ? $single : $plural; }
function get_locale() { return 'en_US'; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function strip_shortcodes( $text ) { return $text; } // No shortcodes in the fixtures.
function wp_strip_all_tags( $text, $remove_breaks = false ) {
	$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
	$text = strip_tags( $text );
	return trim( $remove_breaks ? preg_replace( '/[\r\n\t ]+/', ' ', $text ) : $text );
}
function wpautop( $text ) { return '<p>' . str_replace( "\n", "<br />\n", trim( $text ) ) . '</p>'; } // Simplified stand-in.
function home_url( $path = '' ) { return 'https://disposable.example' . $path; }
function get_home_url() { return 'https://disposable.example'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function get_the_title( $post ) { return 'Title'; }
function get_post_time() { return '2026-01-01T00:00:00+00:00'; }
function get_post_modified_time() { return '2026-01-02T00:00:00+00:00'; }
function get_bloginfo( $show = '' ) { return 'en-US'; }
function get_userdata( $id ) { return false; }
function get_the_category( $id ) { return false; }
function get_the_tags( $id ) { return false; }
class MWSEO_Options {
	public static function get( $key ) { return ''; }
}
$root = $argv[1] ?? dirname( __DIR__ );
require $root . '/includes/class-mwseo-analyzer.php';
require $root . '/includes/class-mwseo-scorer.php';
require $root . '/includes/class-mwseo-schema.php';

$article = new ReflectionMethod( 'MWSEO_Schema', 'article' );
$article->setAccessible( true );
function schema_node( $article, $content ) {
	$post = (object) array( 'ID' => 7, 'post_author' => 3, 'post_content' => $content );
	return $article->invoke( null, $post, 'https://disposable.example/page/', null, array() );
}

// label => array( content, words per the analyzer contract ).
$cases = array(
	'accents (the reported example)'      => array( '<p>naïef café</p>', 2 ),
	'accents, uppercase and combined'     => array( '<p>Été à Zürich, señor Müller</p>', 5 ),
	'Cyrillic, spaced CJK and Greek'      => array( '<p>Привіт світ 你好 世界 Γειά σου</p>', 6 ),
	'unspaced CJK is one run (contract)'  => array( '<p>你好世界</p>', 1 ),
	'digits count as words'               => array( '<p>2026 café 3rd</p>', 3 ),
	'ASCII apostrophe and hyphens'        => array( "<p>don't state-of-the-art</p>", 2 ),
	'right single quote inside word'      => array( '<p>l’été rock ’n’ roll</p>', 4 ),
	'adjacent paragraphs are separate'    => array( '<p>one</p><p>two</p>', 2 ),
	'inline tags do not split a word'     => array( '<p><strong>na</strong>ïef café</p>', 2 ),
	'entities are decoded'                => array( '<p>Caf&eacute; &amp; cr&egrave;me</p>', 2 ),
	'script and style are ignored'        => array( '<p>één</p><script>var x = "a b c";</script><style>p { color: red }</style>', 1 ),
	'block comments are ignored'          => array( "<!-- wp:paragraph -->\n<p>naïef café</p>\n<!-- /wp:paragraph -->", 2 ),
	'no <p> goes through wpautop'         => array( "naïef café\nmooie dag", 4 ),
	'empty content'                       => array( '', 0 ),
	'whitespace-only paragraph'           => array( '<p> </p>', 0 ),
	'comment-only content'                => array( '<!-- wp:paragraph --><!-- /wp:paragraph -->', 0 ),
);

$checks   = 0;
$failures = array();
foreach ( $cases as $label => $case ) {
	list( $content, $expected ) = $case;
	$node     = schema_node( $article, $content );
	$analysis = MWSEO_Scorer::score( array( 'content' => $content ) );
	$checks  += 2;
	// Literal expectation: guards the fixture itself.
	if ( $expected !== $analysis['words'] ) {
		$failures[] = "$label: fixture expects $expected, analyzer returns {$analysis['words']}";
	}
	// The contract under test: schema equals the analyzer.
	if ( ! array_key_exists( 'wordCount', $node ) || $analysis['words'] !== $node['wordCount'] ) {
		$failures[] = "$label: schema wordCount " . var_export( $node['wordCount'] ?? null, true ) . " != analyzer {$analysis['words']}";
	}
}

// Everything but wordCount is untouched, and the value is an integer.
$node = schema_node( $article, '<p>naïef café</p>' );
$checks += 3;
if ( ! is_int( $node['wordCount'] ) ) { $failures[] = 'wordCount is not an integer'; }
$other = $node;
unset( $other['wordCount'] );
$expected_other = array(
	'@type'            => 'Article',
	'@id'              => 'https://disposable.example/#/schema/Article/7',
	'isPartOf'         => array( '@id' => 'https://disposable.example/page/' ),
	'mainEntityOfPage' => array( '@id' => 'https://disposable.example/page/' ),
	'headline'         => 'Title',
	'datePublished'    => '2026-01-01T00:00:00+00:00',
	'dateModified'     => '2026-01-02T00:00:00+00:00',
	'inLanguage'       => 'en-US',
);
if ( $other !== $expected_other ) { $failures[] = 'non-wordCount fields changed: ' . json_encode( $other ); }
if ( 'Article' !== $node['@type'] ) { $failures[] = '@type changed'; }

// Scoring version stays pinned.
if ( '1.2' !== MWSEO_Scorer::SCORING_VERSION ) { $failures[] = 'SCORING_VERSION is not 1.2'; }

echo ( $checks - count( $failures ) ) . '/' . $checks . " checks passed\n";
foreach ( $failures as $f ) { echo "FAIL: $f\n"; }
exit( $failures ? 1 : 0 );
