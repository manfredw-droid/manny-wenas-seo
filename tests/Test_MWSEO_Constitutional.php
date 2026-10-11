<?php
/**
 * Constitutional contract tests for Manny Wenas SEO.
 *
 * These tests verify that MWSEO_Scorer honours the founding principles in
 * PHILOSOPHY.md. They protect the philosophy, not implementation details.
 * A test that passes here means the scorer still believes in the reason
 * the plugin was created.
 *
 * Framework: Brain Monkey + PHPUnit native TestCase.
 * Do NOT mix in WP_Mock — Brain Monkey and WP_Mock are alternative approaches
 * to mocking WordPress functions and must not be combined in the same suite.
 *
 * Run: composer test
 *
 * @package MannyWenasSEO
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/includes/class-mwseo-analyzer.php';
require_once dirname( __DIR__ ) . '/includes/class-mwseo-scorer.php';

/**
 * Constitutional contract test suite.
 */
final class Test_MWSEO_Constitutional extends TestCase {

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stub_wordpress_functions();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ── 0. Scoring-version baseline ───────────────────────────────────────────

	/**
	 * The scorer must expose a named SCORING_VERSION constant so the test suite
	 * always knows which behavioral contract it is verifying. When this version
	 * changes, every constitutional test must be re-reviewed.
	 */
	public function test_scoring_version_constant_is_defined(): void {
		$this->assertTrue(
			defined( 'MWSEO_Scorer::SCORING_VERSION' ),
			'MWSEO_Scorer must expose SCORING_VERSION as a named public constant.'
		);
		$this->assertMatchesRegularExpression(
			'/^\d+\.\d+$/',
			MWSEO_Scorer::SCORING_VERSION,
			'SCORING_VERSION must use major.minor format (e.g. "1.0").'
		);
	}

	// ── 1. The five placement locations, verified independently ───────────────
	//
	// Each test starts from a shared baseline where the focus keyphrase is absent
	// from all five placement locations. It then adds the keyphrase to exactly one
	// location and asserts that the corresponding check improves while the input
	// is otherwise unchanged.
	//
	// This structure catches a scorer that awards full placement points for four
	// locations while completely ignoring the fifth.

	public function test_title_placement_check_responds_to_keyphrase_in_title(): void {
		$focus   = 'natural writing';
		$base    = $this->baseline_without_placement( $focus );
		$variant = array_merge( $base, [ 'title' => 'Natural writing for working writers' ] );

		$base_pts    = $this->check_points( MWSEO_Scorer::score( $base ),    'title_keyphrase' );
		$variant_pts = $this->check_points( MWSEO_Scorer::score( $variant ), 'title_keyphrase' );

		$this->assertGreaterThan(
			$base_pts,
			$variant_pts,
			'Adding the keyphrase to the SEO title must improve the title_keyphrase check.'
		);
	}

	public function test_description_placement_check_responds_to_keyphrase_in_description(): void {
		$focus   = 'natural writing';
		$base    = $this->baseline_without_placement( $focus );
		$variant = array_merge( $base, [ 'desc' => 'Natural writing improves reader engagement.' ] );

		$base_pts    = $this->check_points( MWSEO_Scorer::score( $base ),    'desc_keyphrase' );
		$variant_pts = $this->check_points( MWSEO_Scorer::score( $variant ), 'desc_keyphrase' );

		$this->assertGreaterThan(
			$base_pts,
			$variant_pts,
			'Adding the keyphrase to the meta description must improve the desc_keyphrase check.'
		);
	}

	public function test_slug_placement_check_responds_to_keyphrase_in_slug(): void {
		$focus   = 'natural writing';
		$base    = $this->baseline_without_placement( $focus );
		$variant = array_merge( $base, [ 'slug' => 'natural-writing-guide' ] );

		$base_pts    = $this->check_points( MWSEO_Scorer::score( $base ),    'slug_keyphrase' );
		$variant_pts = $this->check_points( MWSEO_Scorer::score( $variant ), 'slug_keyphrase' );

		$this->assertGreaterThan(
			$base_pts,
			$variant_pts,
			'Adding the keyphrase to the URL slug must improve the slug_keyphrase check.'
		);
	}

	public function test_intro_placement_check_responds_to_keyphrase_in_opening_section(): void {
		$focus = 'natural writing';
		$base  = $this->baseline_without_placement( $focus );

		// Place the keyphrase in the opening paragraph (the first <p> tag).
		// The scorer now checks the first paragraph directly, not the first 10%
		// of all words — this variant passes cleanly under both approaches.
		$variant            = $base;
		$variant['content'] = '<h2>The Power of Clarity</h2>'
			. '<p>Natural writing is the foundation of effective prose. '
			. str_repeat( 'Good communication requires clarity and purpose. ', 15 )
			. '</p>';

		$base_pts    = $this->check_points( MWSEO_Scorer::score( $base ),    'intro_keyphrase' );
		$variant_pts = $this->check_points( MWSEO_Scorer::score( $variant ), 'intro_keyphrase' );

		$this->assertGreaterThan(
			$base_pts,
			$variant_pts,
			'Adding the keyphrase to the opening section must improve the intro_keyphrase check.'
		);
	}

	public function test_subheading_placement_check_responds_to_keyphrase_in_subheading(): void {
		$focus = 'natural writing';
		$base  = $this->baseline_without_placement( $focus );

		// Variant: keyphrase appears only in the H2 heading; body and title unchanged.
		$variant            = $base;
		$variant['content'] = '<h2>Natural Writing in Practice</h2>'
			. '<p>' . str_repeat( 'Good prose connects with readers through well-chosen words. ', 15 ) . '</p>';

		$base_pts    = $this->check_points( MWSEO_Scorer::score( $base ),    'subheading_keyphrase' );
		$variant_pts = $this->check_points( MWSEO_Scorer::score( $variant ), 'subheading_keyphrase' );

		$this->assertGreaterThan(
			$base_pts,
			$variant_pts,
			'Adding the keyphrase to a subheading must improve the subheading_keyphrase check.'
		);
	}

	/**
	 * When the keyphrase appears in all five constitutional locations the placement
	 * section must reach its defined maximum. This verifies that all five locations
	 * are evaluated and that their combined maximum equals the section budget.
	 */
	public function test_all_five_placement_locations_together_reach_maximum_section_score(): void {
		$focus  = 'natural writing';
		$result = MWSEO_Scorer::score( [
			'focus'   => $focus,
			'title'   => 'Natural writing for working writers',
			'desc'    => 'Natural writing improves reader engagement and clarity.',
			'slug'    => 'natural-writing-guide',
			'content' => '<h2>Natural Writing in Practice</h2>'
				. '<p>Natural writing is the foundation of all good prose. '
				. str_repeat( 'Good prose connects with readers through clarity and purpose. ', 15 )
				. '</p>',
		] );

		$placement = $result['sections']['placement'];

		$this->assertFalse(
			$placement['na'],
			'Placement section must apply when a focus keyphrase is set.'
		);
		$this->assertSame(
			(float) $placement['max'],
			(float) $placement['points'],
			'When the keyphrase is present in all five placement locations the placement '
			. 'section must reach its maximum.'
		);
	}

	// ── 2. Keyword stuffing cannot improve the placement score ────────────────

	/**
	 * Repeating the keyphrase many times in the SEO title must not earn more
	 * placement points than using it once naturally.
	 */
	public function test_keyphrase_repetition_in_title_does_not_increase_placement_score(): void {
		$focus     = 'natural writing';
		$once      = [ 'focus' => $focus, 'title' => 'Natural writing for working writers' ];
		$ten_times = [ 'focus' => $focus, 'title' => str_repeat( 'natural writing ', 10 ) ];

		$pts_once = $this->check_points( MWSEO_Scorer::score( $once ),      'title_keyphrase' );
		$pts_many = $this->check_points( MWSEO_Scorer::score( $ten_times ), 'title_keyphrase' );

		$this->assertSame(
			$pts_once,
			$pts_many,
			'Repeating the keyphrase in the title must not increase the title_keyphrase score.'
		);
	}

	/**
	 * Repeating the keyphrase many times in body paragraphs that come after the
	 * opening section must not affect the placement score.
	 *
	 * Isolation design: both inputs share identical first and second paragraphs.
	 * The keyphrase is absent from both; the intro placement score is therefore 0
	 * in both cases regardless of body length. The only difference is the frequency
	 * of the keyphrase in later paragraphs. Neither body length nor keyphrase count
	 * may change the placement section score.
	 */
	public function test_body_frequency_beyond_intro_does_not_affect_placement_score(): void {
		$focus = 'natural writing';

		// Shared intro paragraph: keyphrase absent. The scorer checks the first
		// paragraph directly, so this paragraph must not contain the keyphrase.
		$intro = '<p>'
			. str_repeat( 'Good prose emerges through careful revision and patient editing. ', 5 )
			. '</p>';

		// Base: filler paragraphs with no keyphrase anywhere.
		$filler = '<p>'
			. str_repeat( 'Effective communication requires clarity and purpose. ', 5 )
			. '</p>';

		// Stuffed: keyphrase repeated heavily in every subsequent paragraph.
		$stuffed = '<p>'
			. str_repeat( 'Natural writing natural writing natural writing natural writing. ', 5 )
			. '</p>';

		$base_body    = $intro . str_repeat( $filler,  5 );
		$stuffed_body = $intro . str_repeat( $stuffed, 5 );

		$base_result    = MWSEO_Scorer::score( [ 'focus' => $focus, 'content' => $base_body ] );
		$stuffed_result = MWSEO_Scorer::score( [ 'focus' => $focus, 'content' => $stuffed_body ] );

		// The intro placement score must be identical in both: the intro window
		// has no keyphrase in either version.
		$this->assertSame(
			$this->check_points( $base_result,    'intro_keyphrase' ),
			$this->check_points( $stuffed_result, 'intro_keyphrase' ),
			'Keyphrase repetitions after the intro must not change the intro placement score.'
		);

		// The overall placement section must also be identical.
		$this->assertSame(
			$base_result['sections']['placement']['points'],
			$stuffed_result['sections']['placement']['points'],
			'Repeating the keyphrase after the intro must not change the placement section total.'
		);
	}

	// ── 3. Scoring is deterministic ───────────────────────────────────────────

	/**
	 * The same inputs must always produce the same score and section breakdown.
	 * Two consecutive calls with an identical payload must return equal results.
	 */
	public function test_identical_inputs_always_produce_identical_scores(): void {
		$input = [
			'focus'   => 'editorial craft',
			'title'   => 'Editorial craft in modern writing',
			'desc'    => 'A guide to editorial craft for working writers.',
			'slug'    => 'editorial-craft-guide',
			'content' => '<h2>Editorial Craft</h2>'
				. '<p>Editorial craft shapes every paragraph of good writing. '
				. str_repeat( 'Skilled editors improve clarity and strengthen structure. ', 10 )
				. '</p>',
			'related' => [ 'editing', 'prose style' ],
		];

		$first  = MWSEO_Scorer::score( $input );
		$second = MWSEO_Scorer::score( $input );

		$this->assertSame(
			$first['score'],
			$second['score'],
			'The same input must always produce the same score.'
		);
		$this->assertSame(
			$first['sections'],
			$second['sections'],
			'The same input must always produce the same section breakdown.'
		);
	}

	// ── 4. Scores are bounded 0–100 ───────────────────────────────────────────

	/**
	 * The scorer must handle all inputs gracefully and always return an integer
	 * score within 0–100. This is the constitutional guarantee: bounded scoring
	 * regardless of input quality.
	 *
	 * Note on contract design: the constitution requires that VALID inputs produce
	 * an integer score. For INVALID inputs (null fields, empty arrays, malformed
	 * content) the scorer must either return a bounded integer through documented
	 * normalisation or throw a named exception. This suite verifies the former.
	 * If the team decides to reject invalid input with a defined exception instead,
	 * update this test and the PHILOSOPHY.md amendment record accordingly.
	 *
	 */
	#[DataProvider( 'malformed_input_provider' )]
	public function test_score_is_always_bounded_between_0_and_100( array $input, string $description ): void {
		$result = MWSEO_Scorer::score( $input );
		$score  = $result['score'];

		$this->assertIsInt(
			$score,
			"Score must be an integer for: {$description}"
		);
		$this->assertGreaterThanOrEqual(
			0,
			$score,
			"Score must not fall below 0 for: {$description}"
		);
		$this->assertLessThanOrEqual(
			100,
			$score,
			"Score must not exceed 100 for: {$description}"
		);
	}

	/**
	 * @return array<string, array{array, string}>
	 */
	public static function malformed_input_provider(): array {
		return [
			'empty input'          => [ [], 'empty array' ],
			'null focus'           => [ [ 'focus' => null ], 'null focus keyphrase' ],
			'whitespace focus'     => [ [ 'focus' => '   ' ], 'whitespace-only focus' ],
			'integer focus'        => [ [ 'focus' => 42 ], 'integer focus keyphrase' ],
			'empty html content'   => [ [ 'content' => '<div></div>' ], 'empty HTML content' ],
			'unicode content'      => [ [ 'focus' => '写作', 'content' => '<p>写作是一种技巧。</p>' ], 'CJK content' ],
			'deeply nested html'   => [
				[ 'content' => str_repeat( '<div>', 20 ) . 'some text' . str_repeat( '</div>', 20 ) ],
				'deeply nested HTML',
			],
		];
	}

	// ── 5. No outbound HTTP during scoring ────────────────────────────────────

	/**
	 * The scoring engine must never make network requests. Editorial content is
	 * private until the author publishes it; transmitting it to an external service
	 * during a local scoring call would violate Section 3.4 of PHILOSOPHY.md.
	 *
	 * This test covers all WordPress HTTP entry points. After the pure scoring
	 * engine is separated from WordPress, the architectural guarantee will be
	 * stronger: the calculation layer will have no network dependencies at all.
	 */
	public function test_no_outbound_http_during_scoring(): void {
		Functions\expect( 'wp_remote_get' )->never();
		Functions\expect( 'wp_remote_post' )->never();
		Functions\expect( 'wp_remote_request' )->never();
		Functions\expect( 'wp_safe_remote_get' )->never();
		Functions\expect( 'wp_safe_remote_post' )->never();
		Functions\expect( 'wp_safe_remote_request' )->never();

		MWSEO_Scorer::score( [
			'focus'   => 'editorial craft',
			'title'   => 'Editorial craft in modern writing',
			'desc'    => 'A guide to editorial craft for working writers.',
			'slug'    => 'editorial-craft-guide',
			'content' => '<h2>Editorial Craft</h2>'
				. '<p>Editorial craft takes years to develop and a career to master.</p>',
		] );

		// Brain Monkey will fail this test automatically if any of the above
		// functions are called. The assertion count increment makes the test
		// explicit in PHPUnit's output.
		$this->addToAssertionCount( 1 );
	}

	// ── 6. Regression: opening-paragraph check is position-exact ─────────────

	/**
	 * A keyphrase appearing in the second paragraph (not the first) must receive
	 * zero intro_keyphrase points.
	 *
	 * This is a regression test for the word-count-based opening check that
	 * searched the first 10% of words. On a long article the 10% window could
	 * extend past the first paragraph, giving unearned placement credit to
	 * keyphrases that appear only in paragraph two or three.
	 *
	 * The scorer now uses paragraphs()[0] — the first <p> element — so this test
	 * must remain true regardless of article length.
	 */
	public function test_keyphrase_in_later_paragraph_does_not_earn_opening_placement(): void {
		$focus = 'natural writing';

		// Paragraph 1: no keyphrase.
		$para1 = '<p>Good prose emerges through careful revision and patient editing. '
			. 'Clarity guides every sentence, and structure guides every paragraph.</p>';

		// Paragraph 2: keyphrase present. This must NOT earn intro credit.
		$para2 = '<p>Natural writing is the hallmark of experienced authors who have '
			. 'learned to trust their instincts over mechanical rules.</p>';

		// Paragraph 3 onwards: more keyphrase occurrences. Also must not earn credit.
		$para3 = '<p>Natural writing cannot be forced. It develops over time through '
			. str_repeat( 'reading widely and writing often. ', 10 ) . '</p>';

		$result = MWSEO_Scorer::score( [
			'focus'   => $focus,
			'content' => $para1 . $para2 . $para3,
		] );

		$this->assertSame(
			0.0,
			$this->check_points( $result, 'intro_keyphrase' ),
			'A keyphrase appearing only in the second paragraph must not earn '
			. 'opening-placement points. The check is position-exact: only the '
			. 'first <p> element qualifies.'
		);
	}

	// ── 7. Regression: subheading check evaluates each heading individually ───

	/**
	 * A keyphrase split across two separate subheadings must receive zero
	 * subheading_keyphrase points.
	 *
	 * This is a regression test for the concatenation bug where all heading text
	 * was joined before matching. "Natural Expression" and "Writing Techniques"
	 * concatenated to "Natural Expression Writing Techniques", which the semantic
	 * match engine satisfied for the focus "natural writing" — even though neither
	 * heading alone contained the keyphrase.
	 *
	 * The scorer now evaluates each heading independently and takes the best
	 * single match, so this test must hold for any number of headings.
	 */
	public function test_keyphrase_split_across_separate_headings_does_not_earn_subheading_credit(): void {
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'content' => '<h2>Natural Expression</h2>'
				. '<h2>Writing Techniques</h2>'
				. '<p>' . str_repeat( 'Good prose emerges through careful revision. ', 10 ) . '</p>',
		] );

		$this->assertSame(
			0.0,
			$this->check_points( $result, 'subheading_keyphrase' ),
			'A keyphrase split across two separate subheadings must not earn placement '
			. 'credit. Each heading is evaluated independently; a match across headings '
			. 'is not a natural placement.'
		);
	}

	// ── 8. Constitutional weights are pinned ──────────────────────────────────

	/**
	 * The five placement checks must be worth exactly the points defined in the
	 * constitution: SEO title 8, meta description 6, URL slug 6, opening paragraph
	 * 6, subheading 4 = 30 total.
	 *
	 * This test pins the contract so any future scoring change to these weights
	 * requires an explicit SCORING_VERSION increment and amendment record, not
	 * just a passing test suite.
	 */
	public function test_placement_check_weights_match_constitution(): void {
		$expected = [
			'title_keyphrase'       => 8,
			'desc_keyphrase'        => 6,
			'slug_keyphrase'        => 6,
			'intro_keyphrase'       => 6,
			'subheading_keyphrase'  => 4,
		];

		// Use a result where all five checks apply (not N/A) so 'max' is reported.
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'title'   => 'Natural writing for working writers',
			'desc'    => 'Natural writing improves reader engagement.',
			'slug'    => 'natural-writing-guide',
			'content' => '<h2>Natural Writing in Practice</h2>'
				. '<p>Natural writing is the foundation of effective prose. '
				. str_repeat( 'Good prose connects with readers. ', 15 ) . '</p>',
		] );

		$checks_by_id = [];
		foreach ( $result['checks'] as $check ) {
			$checks_by_id[ $check['id'] ] = $check;
		}

		foreach ( $expected as $check_id => $expected_max ) {
			$this->assertArrayHasKey(
				$check_id,
				$checks_by_id,
				"Check '{$check_id}' must exist in the scorer output."
			);
			$this->assertSame(
				$expected_max,
				$checks_by_id[ $check_id ]['max'],
				"Check '{$check_id}' must be worth exactly {$expected_max} points (§2 of PHILOSOPHY.md)."
			);
		}

		$this->assertSame(
			30.0,
			(float) $result['sections']['placement']['max'],
			'The placement section must be worth exactly 30 points in total.'
		);
	}

	// ── 9. Markup edge case: <div>-only content is scored ───────────────────

	/**
	 * When the post content contains no <p> elements, the first non-empty <div>
	 * block is treated as the opening paragraph. The check must run (not N/A) and
	 * must award full credit when the keyphrase is present in that block.
	 *
	 * The scorer normalises via wpautop() only when no <p substring is present;
	 * the stub returns the input unchanged, and wpautop preserves <div> blocks in
	 * WordPress as well, so MWSEO_Analyzer::paragraphs() falls back to the div.
	 */
	public function test_keyphrase_in_div_block_is_scored_as_opening_paragraph(): void {
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'content' => '<div>Natural writing is found here but not inside any paragraph tag.</div>',
		] );

		$check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'intro_keyphrase' === $c['id'] ) {
				$check = $c;
				break;
			}
		}

		$this->assertNotNull( $check, 'intro_keyphrase check must always be present in the output.' );
		$this->assertFalse(
			$check['na'],
			'When the content has a <div> block and no <p> elements the opening-paragraph '
			. 'check must run, not be marked N/A.'
		);
		$this->assertSame(
			(float) $check['max'],
			(float) $check['points'],
			'A keyphrase present in the first <div> block must earn full opening-paragraph credit.'
		);
	}

	/**
	 * A div-only post where the keyphrase is absent must score 0 on the
	 * opening-paragraph check, not N/A. The check now runs on div content;
	 * absence of the keyphrase is a genuine miss, not a measurement gap.
	 */
	public function test_missing_keyphrase_in_div_block_scores_zero_not_na(): void {
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'content' => '<div>This paragraph does not contain the focus keyphrase at all.</div>',
		] );

		$check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'intro_keyphrase' === $c['id'] ) {
				$check = $c;
				break;
			}
		}

		$this->assertNotNull( $check, 'intro_keyphrase check must always be present.' );
		$this->assertFalse(
			$check['na'],
			'When div content is present the check must run, not be marked N/A.'
		);
		$this->assertSame(
			0.0,
			(float) $check['points'],
			'A div block without the keyphrase must score 0, not partial credit.'
		);
		$this->assertSame(
			'none',
			$check['classification'],
			'A div block without the keyphrase must be classified "none".'
		);
	}

	/**
	 * A short <div> (a nav or hero element, under 10 words) is not an opening
	 * paragraph. The check must be N/A, not a keyphrase miss.
	 */
	public function test_short_div_nav_block_produces_na_not_a_miss(): void {
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'content' => '<div>Home About Contact</div>',
		] );

		$check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'intro_keyphrase' === $c['id'] ) {
				$check = $c;
				break;
			}
		}

		$this->assertNotNull(
			$check,
			'intro_keyphrase check must always be present in the output.'
		);
		$this->assertTrue(
			$check['na'],
			'A <div> block with fewer than 10 words is a nav/hero element, not an opening '
			. 'paragraph; the check must be N/A.'
		);
		$this->assertSame(
			'na',
			$check['classification'],
			'A <div> block with fewer than 10 words is a nav/hero element, not an opening '
			. 'paragraph; the check must be N/A.'
		);
	}

	// ── 10. Verdict classification contract ───────────────────────────────────
	//
	// §4.5 requires factual observations. The verdict must report the precise
	// outcome of each check — full credit, partial credit, or no credit — without
	// editorial framing or arbitrary score thresholds.

	/**
	 * When every applicable check earns full credit the verdict must say so
	 * accurately, and all three counts must reflect it.
	 *
	 * This is a direct regression test for the old threshold bug where a check
	 * earning 70% of its points was counted as a pass, allowing "all checks passed"
	 * to appear even when some checks had partial or no credit.
	 */
	public function test_verdict_reports_all_full_when_every_check_passes(): void {
		$result  = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'title'   => 'Natural writing for working writers',
			'desc'    => 'Natural writing improves reader engagement and clarity.',
			'slug'    => 'natural-writing-guide',
			'content' => '<h2>Natural Writing in Practice</h2>'
				. '<p>Natural writing is the foundation of all good prose. '
				. str_repeat( 'Good prose connects with readers through clarity and purpose. ', 15 )
				. 'Read more in our <a href="https://example.com/internal">editing guide</a>.</p>',
		] );
		$verdict = $result['verdict'];

		// The verdict is structured data, not a string.
		$this->assertIsArray( $verdict, 'verdict must be an array.' );
		$this->assertArrayHasKey( 'summary',       $verdict );
		$this->assertArrayHasKey( 'observations',  $verdict );
		$this->assertArrayHasKey( 'full_count',    $verdict );
		$this->assertArrayHasKey( 'partial_count', $verdict );
		$this->assertArrayHasKey( 'none_count',    $verdict );

		// Counts: no partial or none, so the full-credit summary applies.
		$this->assertSame( 0, $verdict['partial_count'], 'partial_count must be 0 when all checks pass.' );
		$this->assertSame( 0, $verdict['none_count'],    'none_count must be 0 when all checks pass.' );
		$this->assertGreaterThan( 0, $verdict['full_count'], 'full_count must be positive when checks pass.' );
		$this->assertEmpty( $verdict['observations'], 'observations must be empty when all checks pass.' );

		// The summary must claim full credit accurately — never "all checks passed"
		// based on a score threshold.
		$this->assertStringContainsString(
			'full credit',
			$verdict['summary'],
			'The verdict summary must state full credit when every applicable check earns it.'
		);
	}

	/**
	 * When some checks earn partial credit the verdict must report partial_count
	 * accurately and include those checks in the observations list.
	 *
	 * The subheading check awards 50% for a partial match (ratio 0.5), which is
	 * the simplest way to create a partial-credit check without mocking internals.
	 */
	public function test_verdict_reports_partial_credit_checks_accurately(): void {
		// A subheading that contains only part of the focus keyphrase earns
		// partial subheading credit (ratio 0.5, classification 'partial').
		$result  = MWSEO_Scorer::score( [
			'focus'   => 'natural writing techniques',
			'title'   => 'Natural writing techniques for working writers',
			'desc'    => 'Natural writing techniques improve engagement.',
			'slug'    => 'natural-writing-techniques',
			'content' => '<h2>Natural Expression</h2>'
				. '<p>Natural writing techniques are the foundation of effective prose. '
				. str_repeat( 'Good prose requires careful editing. ', 15 )
				. '</p>',
		] );
		$verdict = $result['verdict'];

		$this->assertIsArray( $verdict );

		// At least the subheading check is partial; there may be others.
		// The contract is that partial_count > 0, not the exact count.
		$partial_or_none = $verdict['partial_count'] + $verdict['none_count'];
		$this->assertGreaterThan(
			0,
			$partial_or_none,
			'When some checks earn partial credit the verdict must report a non-zero partial or none count.'
		);
		$this->assertNotEmpty(
			$verdict['observations'],
			'Unfulfilled checks must appear in the observations list.'
		);

		// The summary must NOT claim full credit.
		$this->assertStringNotContainsString(
			'full credit',
			$verdict['summary'],
			'The verdict must not claim full credit when some checks are partial or none.'
		);
	}

	/**
	 * When a check earns zero credit its classification must be 'none' and it
	 * must be counted in none_count, not partial_count.
	 *
	 * The title keyphrase check is the cleanest sentinel: omitting the focus
	 * keyphrase from the title guarantees ratio 0 → classification 'none'.
	 */
	public function test_verdict_distinguishes_none_from_partial(): void {
		// Title has no keyphrase → title_keyphrase classification must be 'none'.
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'title'   => 'An article about effective communication',
			'desc'    => 'Natural writing improves reader engagement.',
			'slug'    => 'natural-writing-guide',
			'content' => '<h2>Natural Writing in Practice</h2>'
				. '<p>Natural writing is the foundation of all good prose. '
				. str_repeat( 'Good prose connects with readers. ', 15 )
				. '</p>',
		] );

		// Verify the check itself carries classification 'none'.
		$title_check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'title_keyphrase' === $c['id'] ) {
				$title_check = $c;
				break;
			}
		}
		$this->assertNotNull( $title_check );
		$this->assertSame(
			'none',
			$title_check['classification'],
			'A check earning zero points must carry classification "none".'
		);
		$this->assertSame(
			0.0,
			(float) $title_check['points'],
			'A "none" check must have 0 points.'
		);

		// The verdict must reflect it in none_count, not partial_count.
		$verdict = $result['verdict'];
		$this->assertGreaterThan(
			0,
			$verdict['none_count'],
			'none_count must be positive when at least one check earns zero credit.'
		);
	}

	/**
	 * N/A checks must not appear in any verdict count.
	 *
	 * When no focus keyphrase is set, all keyphrase checks are deactivated.
	 * The verdict must reflect the absence of a focus keyphrase with a dedicated
	 * message and all three counts at zero.
	 */
	public function test_verdict_excludes_na_checks_from_all_counts(): void {
		// No focus keyphrase → all keyphrase checks become N/A.
		$result  = MWSEO_Scorer::score( [
			'focus'   => '',
			'content' => '<p>' . str_repeat( 'Some content for testing. ', 20 ) . '</p>',
		] );
		$verdict = $result['verdict'];

		$this->assertIsArray( $verdict );
		$this->assertSame( 0, $verdict['full_count'],    'full_count must be 0 when focus is absent.' );
		$this->assertSame( 0, $verdict['partial_count'], 'partial_count must be 0 when focus is absent.' );
		$this->assertSame( 0, $verdict['none_count'],    'none_count must be 0 when focus is absent.' );
		$this->assertEmpty( $verdict['observations'],    'observations must be empty when focus is absent.' );
		$this->assertStringContainsString(
			'Focus keyphrase not set',
			$verdict['summary'],
			'The verdict must mention the missing focus keyphrase, not report counts.'
		);
	}

	// ── 11. Missing focus is N/A, never a failure ─────────────────────────────

	/**
	 * Without a focus keyphrase no keyphrase check can be performed. Every
	 * keyphrase-related check must be N/A; none may be classified as 'none'.
	 */
	public function test_missing_focus_produces_no_failed_checks(): void {
		$result = MWSEO_Scorer::score( [
			'focus'   => '',
			'title'   => 'An article about effective communication',
			'desc'    => 'How to communicate clearly and connect with your audience.',
			'slug'    => 'effective-communication-guide',
			'content' => '<h2>The Power of Clarity</h2>'
				. '<p>' . str_repeat( 'Good prose connects readers with ideas through clear expression. ', 15 ) . '</p>',
		] );

		$keyphrase_ids = [
			'body_keyphrase',
			'title_keyphrase',
			'desc_keyphrase',
			'slug_keyphrase',
			'intro_keyphrase',
			'subheading_keyphrase',
			'related_body',
		];

		$seen = [];
		foreach ( $result['checks'] as $check ) {
			if ( ! in_array( $check['id'], $keyphrase_ids, true ) ) {
				continue;
			}
			$seen[] = $check['id'];
			$this->assertNotSame(
				'none',
				$check['classification'],
				"Check '{$check['id']}' must not be classified 'none' when no focus keyphrase is set."
			);
			$this->assertSame( 'na', $check['classification'], "Check '{$check['id']}' must be classified 'na'." );
			$this->assertTrue( $check['na'], "Check '{$check['id']}' must have na === true." );
		}

		$this->assertEqualsCanonicalizing(
			$keyphrase_ids,
			$seen,
			'Every keyphrase-related check must be present in the output.'
		);
	}

	// ── 12. Word count is advisory only ───────────────────────────────────────

	/**
	 * Word count appears only in notices(). Two texts that differ only in length
	 * (one under, one over 300 words) must receive the same numeric score.
	 */
	public function test_word_count_does_not_affect_score(): void {
		$sentence = 'Good prose connects readers with ideas through clear expression. ';
		$make     = fn( int $repeats ): array => [
			'focus'   => 'natural writing',
			'title'   => 'Natural writing for working writers',
			'desc'    => 'Natural writing improves reader engagement.',
			'slug'    => 'natural-writing-guide',
			'content' => '<h2>Natural Writing in Practice</h2>'
				. '<p>Natural writing is the foundation of effective prose. '
				. str_repeat( $sentence, $repeats ) . '</p>',
		];

		$short = MWSEO_Scorer::score( $make( 5 ) );
		$long  = MWSEO_Scorer::score( $make( 60 ) );

		$this->assertLessThan( MWSEO_Scorer::MIN_WORDS, $short['words'], 'Short fixture must be under 300 words.' );
		$this->assertGreaterThan( MWSEO_Scorer::MIN_WORDS, $long['words'], 'Long fixture must be over 300 words.' );
		$this->assertSame(
			$short['score'],
			$long['score'],
			'Word count must not change the numeric score; it is an advisory notice only.'
		);
	}

	// ── 13. Body keyphrase check works on div-only content ──────────────────

	/**
	 * The body keyphrase check reads the plain-text representation of the content
	 * (all tags stripped), not the extracted paragraphs, so it must find the
	 * keyphrase in a post that uses <div> blocks and no <p> elements.
	 */
	public function test_keyphrase_in_body_is_found_in_div_only_content(): void {
		$result = MWSEO_Scorer::score( [
			'focus'   => 'natural writing',
			'content' => '<div>Natural writing is the primary theme of this article and it appears exclusively inside a div element.</div>',
		] );

		$check = null;
		foreach ( $result['checks'] as $c ) {
			if ( 'body_keyphrase' === $c['id'] ) {
				$check = $c;
				break;
			}
		}

		$contract = 'check_keyphrase_in_body() reads the plain-text representation of the content '
			. '(all tags stripped), so the keyphrase must be found in div-only posts.';

		$this->assertNotNull( $check, $contract );
		$this->assertFalse( $check['na'], $contract );
		$this->assertSame( 'full', $check['classification'], $contract );
		$this->assertSame( (float) $check['max'], (float) $check['points'], $contract );
	}

	// ── Fixtures and helpers ──────────────────────────────────────────────────

	/**
	 * Return a complete input array where the focus keyphrase is absent from all
	 * five placement locations. Used as the shared baseline for the individual
	 * placement location tests.
	 *
	 * The heading and body content are deliberately generic to ensure that none
	 * of the five placement checks can pass before the variant is applied.
	 */
	private function baseline_without_placement( string $focus ): array {
		return [
			'focus'   => $focus,
			'title'   => 'An article about effective communication',
			'desc'    => 'How to communicate clearly and connect with your audience.',
			'slug'    => 'effective-communication-guide',
			'content' => '<h2>The Power of Clarity</h2>'
				. '<p>'
				. str_repeat( 'Good prose connects readers with ideas through clear expression. ', 15 )
				. '</p>',
		];
	}

	/**
	 * Return the points earned for a named check from a scorer result.
	 * Fails the test immediately if the check ID is not present — this protects
	 * against silent regressions where a check is renamed or removed.
	 *
	 * @param array  $result   Return value of MWSEO_Scorer::score().
	 * @param string $check_id The 'id' field of the check to look up.
	 * @return float
	 */
	private function check_points( array $result, string $check_id ): float {
		foreach ( $result['checks'] as $check ) {
			if ( $check['id'] === $check_id ) {
				return (float) $check['points'];
			}
		}
		$this->fail(
			"Check '{$check_id}' was not found in the scorer output. "
			. 'If the check was renamed, update the test and the amendment record.'
		);
	}

	/**
	 * Stub the WordPress functions that the scorer and analyzer call during a
	 * normal scoring run. Only functions that are actually called are stubbed;
	 * Brain Monkey will record unexpected calls so they can be caught explicitly.
	 *
	 * Notable omissions:
	 * - sprintf() is a native PHP function and must NOT be mocked here.
	 * - The HTTP functions (wp_remote_get etc.) are NOT stubbed. They are
	 *   declared with ->never() in the HTTP test so Brain Monkey fails the
	 *   test if any of them are called.
	 */
	private function stub_wordpress_functions(): void {
		Functions\stubs( [
			// Localisation.
			'__'                => static fn( string $text, string $domain = '' ): string => $text,
			'esc_html__'        => static fn( string $text, string $domain = '' ): string => $text,

			// Input normalisation.
			'wp_parse_args'     => static function ( $args, array $defaults ): array {
				return array_merge( $defaults, (array) $args );
			},

			// Content processing.
			'strip_shortcodes'  => static fn( string $content ): string => $content,
			'wpautop'           => static fn( string $content ): string => $content,
			'wp_strip_all_tags' => static fn( string $text ): string => strip_tags( $text ),

			// Localisation (plural forms).
			'_n'                => static fn( string $single, string $plural, int $number, string $domain = '' ): string => 1 === $number ? $single : $plural,

			// Locale and filters.
			'get_locale'        => static fn(): string => 'en_US',
			'apply_filters'     => static fn( string $tag, $value ) => $value,

			// URL helpers.
			'home_url'          => static fn( string $path = '' ): string => 'https://example.com' . $path,
			'wp_parse_url'      => static function ( string $url, int $component = -1 ) {
				return parse_url( $url, $component );
			},
		] );
	}
}
