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
$focused_baseline = array(
    'none' => '746025822f67f6be0a487595d1de312ec4357336ee2b1cedba5b34a866b098d3',
    'one_present' => 'b94b48c7633108ad10b8bc7e66f699d2861ce24386fe3206ec3451be185ea1d0',
    'one_absent' => 'f67e9b95e5c817915aead95b21151c320b5fbf59d165ecc6fbea2892c98a5ef6',
    'two_present' => '35080a26b6e96b5ae246cfad60acf210efafe86a13bc595a70ab53150975685f',
    'two_mixed' => '91f57a443a149362c68eb72dba5dc5a2051a696964d5c3c807d117b8ff8e7871',
    'two_absent' => 'ec984704776ec37b9fb41dfb6241aee0a278c066a99fad9ee36ca56cebe9d96f',
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
$tests['Approved version 1.2 and unchanged section weights'] = function () {
    same( '1.2', MWSEO_Scorer::SCORING_VERSION );
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
