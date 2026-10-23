<?php
/**
 * Finding #8 isolated regressions: actual production scorer and analyzer.
 * php tests/related-without-focus.php [source-root]
 * Helpers below cover only these literal HTML fixtures; no WordPress runtime.
 */
define( 'ABSPATH', __DIR__ . '/' );
function __( $text, $domain = '' ) { return $text; }
function _n( $single, $plural, $number, $domain = '' ) { return $number === 1 ? $single : $plural; }
function get_locale() { return 'en_US'; }
function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, $args ); }
function strip_shortcodes( $text ) { return $text; } // No shortcodes in the fixtures.
function wp_strip_all_tags( $text ) { return trim( strip_tags( $text ) ); }
function home_url() { return 'https://disposable.example'; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; } // No custom synonyms/language filters.
function update_post_meta() { throw new RuntimeException( 'Unexpected metadata write' ); }
function add_post_meta() { throw new RuntimeException( 'Unexpected metadata write' ); }
function delete_post_meta() { throw new RuntimeException( 'Unexpected metadata deletion' ); }
$root = $argv[1] ?? dirname( __DIR__ );
require $root . '/includes/class-mwseo-analyzer.php';
require $root . '/includes/class-mwseo-scorer.php';
$base = array(
    'title' => 'Solar garden notes', 'desc' => 'Solar garden notes for a quiet afternoon.',
    'slug' => 'solar-garden',
    'content' => '<p>Solar garden paths bring calm. Cedar lantern lights the path. River pebble rests nearby.</p><h2>Solar garden notes</h2><p>Read our <a href="/guide">local guide</a> for more.</p>',
);
$matrix = array(
    'none' => array(),
    'one_present' => array( 'cedar lantern' ),
    'one_absent' => array( 'cobalt zeppelin' ),
    'two_present' => array( 'cedar lantern', 'river pebble' ),
    'two_mixed' => array( 'cedar lantern', 'cobalt zeppelin' ),
    'two_absent' => array( 'cobalt zeppelin', 'violet submarine' ),
);
// Full output fingerprints from the unchanged 0399fe2 baseline / scoring 1.1.
// Includes every score, section, check, verdict, notice and word count. Derived
// from the recorded research fixtures, which use WordPress's real formatting.
// Since the readability pass message became 'Sentences average about N words.'
// the six hashes differ from the original output only in that one string.
$focused_baseline = array(
    'none' => 'd89a9251e0fad2ccf90d550f0e87ac2ee8cbe61e56e8c4c06a46aaff6835d221',
    'one_present' => '2625780f00271d2ce3616edce11afe7c0ff5fdaaee3384eedcc48d1a46f5f920',
    'one_absent' => '5dabb19b70758222de62cd0c5852120c2a80344a008ae0a14c1a5a6f23787c78',
    'two_present' => '10cb31ec6578bc7d292afc290aa8eb3831b83ad73dd43a3ed4d96772e9f4fe7a',
    'two_mixed' => 'ff0b08023d117a1eb4a00bcb22e5524afe45fa7eaacd661a9147003a255a3682',
    'two_absent' => '7ea7cdc146af131ef3141cc13cad4bcfb6c781f44b4adeabde93afd17c11ee61',
);
function same( $expected, $actual ) {
    if ( $expected !== $actual ) { throw new RuntimeException( 'Expected ' . json_encode( $expected ) . '; got ' . json_encode( $actual ) ); }
}
function totals( $analysis ) {
    $earned = 0; $max = 0;
    foreach ( $analysis['checks'] as $check ) {
        if ( ! $check['na'] ) { $earned += $check['points']; $max += $check['max']; }
    }
    return array( $earned, $max );
}
function no_focus_contract( $analysis, $earned, $max, $score ) {
    $checks = array_column( $analysis['checks'], null, 'id' );
    same( true, $checks['related_body']['na'] );
    same( 'na', $checks['related_body']['classification'] );
    same( 'na', $checks['related_body']['status'] );
    same( 0, $checks['related_body']['points'] );
    same( 10, $checks['related_body']['max'] ); // Declared weight; excluded from actual denominator.
    same( array( (float) $earned, $max ), totals( $analysis ) );
    same( $score, $analysis['score'] );
    same( true, $analysis['sections']['optional']['na'] );
    same( 0.0, $analysis['sections']['optional']['points'] );
    same( 0.0, $analysis['sections']['optional']['max'] );
    foreach ( array( 'body_keyphrase', 'title_keyphrase', 'desc_keyphrase', 'slug_keyphrase', 'intro_keyphrase', 'subheading_keyphrase' ) as $id ) { same( true, $checks[$id]['na'] ); }
    same( array( 'summary' => 'Focus keyphrase not set. Keyphrase checks are inactive.', 'observations' => array(), 'full_count' => 0, 'partial_count' => 0, 'none_count' => 0 ), $analysis['verdict'] );
}
$tests = array();
$tests['Current scoring version (1.3 since 1.0.9; the related-phrase rule is from 1.2) and unchanged section weights'] = function () {
    same( '1.3', MWSEO_Scorer::SCORING_VERSION );
    same( array( 'content' => 45, 'placement' => 30, 'technical' => 11, 'optional' => 10 ), MWSEO_Scorer::SECTIONS );
};
foreach ( array( '', " \t\n" ) as $focus_index => $focus ) {
    foreach ( $matrix as $name => $related ) {
        $tests['No focus ' . $focus_index . ': ' . $name] = function () use ( $base, $focus, $related ) {
            $input = array_merge( $base, array( 'focus' => $focus, 'related' => $related ) );
            $original = $input;
            no_focus_contract( MWSEO_Scorer::score( $input ), 33, 33, 100 );
            same( $original, $input );
        };
    }
}
foreach ( $matrix as $name => $related ) {
    $tests['Focus baseline unchanged: ' . $name] = function () use ( $base, $related, $name, $focused_baseline ) {
        $analysis = MWSEO_Scorer::score( array_merge( $base, array( 'focus' => 'solar garden', 'related' => $related ) ) );
        same( $focused_baseline[$name], hash( 'sha256', json_encode( $analysis ) ) );
    };
}
$tests['Previously 75 now 100: empty slug and missing related phrase'] = function () use ( $base ) {
    no_focus_contract( MWSEO_Scorer::score( array_merge( $base, array( 'slug' => '', 'focus' => '', 'related' => array( 'cobalt zeppelin' ) ) ) ), 30, 30, 100 );
};
$tests['Previously 77 now 70: missing link and present related phrase'] = function () use ( $base ) {
    $input = array_merge( $base, array( 'focus' => '', 'related' => array( 'cedar lantern' ), 'content' => str_replace( '<a href="/guide">local guide</a>', 'local guide', $base['content'] ) ) );
    no_focus_contract( MWSEO_Scorer::score( $input ), 23, 33, 70 );
};
foreach ( array( '', 'solar garden' ) as $focus ) {
    $tests['Blank related entries are absent; focus=' . $focus] = function () use ( $base, $focus ) {
        $input = array_merge( $base, array( 'focus' => $focus, 'related' => array( '', " \t" ) ) );
        $analysis = MWSEO_Scorer::score( $input );
        $input['related'] = array();
        same( MWSEO_Scorer::score( $input ), $analysis );
    };
}
$passed = 0; $failed = 0;
foreach ( $tests as $name => $test ) {
    try { $test(); $passed++; echo "PASS $name\n"; }
    catch ( Throwable $error ) { $failed++; echo "FAIL $name: " . $error->getMessage() . "\n"; }
}
echo "$passed passed; $failed failed. Isolated PHP; not WordPress runtime verification.\n";
exit( $failed ? 1 : 0 );
