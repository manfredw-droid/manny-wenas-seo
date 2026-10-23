<?php
defined( 'ABSPATH' ) || exit;
/**
 * 100-point content score.
 *
 * @package MannyWenasSEO
 */

/**
 * Scores a post against the Manny Wenas rubric.
 *
 * Sections: Text & Content 45, Keyphrase placement 30, Technical 11, Optional
 * keyphrases 10. Checks that cannot apply to a post (for example no focus
 * keyphrase, no related keyphrases, no images, no subheadings, no slug yet) are
 * marked N/A: they are left out of the maximum and the score is rescaled, so they
 * neither add nor remove points. Advisory notices (fewer than 300 words, no
 * external link) never change the score.
 *
 * Every sub-score is its own method so it can be tested or overridden alone.
 */
final class MWSEO_Scorer {

	/**
	 * Behavioral baseline. Increment this (independently of the plugin release
	 * version) whenever a scoring rule changes intentionally, so constitutional
	 * tests have a defined contract to verify against.
	 */
	const SCORING_VERSION = '1.3';

	/**
	 * Points per section.
	 */
	const SECTIONS = array(
		'content'   => 45,
		'placement' => 30,
		'technical' => 11,
		'optional'  => 10,
	);

	/**
	 * Minimum score for the green traffic light.
	 */
	const GREEN = 80;

	/**
	 * Minimum score for the orange traffic light (below it is red).
	 */
	const ORANGE = 50;

	/**
	 * Word count below which the advisory notice is shown.
	 */
	const MIN_WORDS = 300;

	/**
	 * Human-readable section names.
	 *
	 * @return array
	 */
	public static function section_labels() {
		return array(
			'content'   => __( 'Text & Content', 'manny-wenas-seo' ),
			'placement' => __( 'Keyphrase placement', 'manny-wenas-seo' ),
			'technical' => __( 'Technical', 'manny-wenas-seo' ),
			'optional'  => __( 'Related keyphrases', 'manny-wenas-seo' ),
		);
	}

	/**
	 * Run the analysis.
	 *
	 * @param array $in {
	 *     Input.
	 *
	 *     @type string   $title   SEO title.
	 *     @type string   $desc    Meta description.
	 *     @type string   $slug    Post slug.
	 *     @type string   $focus   Focus keyphrase.
	 *     @type string[] $related Related keyphrases.
	 *     @type string   $content Post HTML.
	 * }
	 * @return array score, state, sections, verdict, checks, notices, words.
	 */
	public static function score( array $in ) {
		$ctx = self::context( $in );

		$checks = array_merge(
			self::score_text_content( $ctx ),
			self::score_placement( $ctx ),
			self::score_technical( $ctx ),
			self::score_optional( $ctx )
		);

		$labels   = self::section_labels();
		$sections = array();
		foreach ( self::SECTIONS as $key => $max ) {
			$sections[ $key ] = array(
				'label'  => $labels[ $key ],
				'points' => 0,
				'max'    => 0,
				'na'     => true,
			);
		}
		$earned     = 0;
		$applicable = 0;
		foreach ( $checks as $c ) {
			if ( $c['na'] ) {
				continue;
			}
			$sections[ $c['group'] ]['na']      = false;
			$sections[ $c['group'] ]['points'] += $c['points'];
			$sections[ $c['group'] ]['max']    += $c['max'];
			$earned                            += $c['points'];
			$applicable                        += $c['max'];
		}
		foreach ( $sections as $key => $sec ) {
			$sections[ $key ]['points'] = round( $sec['points'], 1 );
			$sections[ $key ]['max']    = round( $sec['max'], 1 );
		}

		// Rescale over the checks that apply, so N/A never lowers the score.
		$score = $applicable > 0 ? (int) round( 100 * $earned / $applicable ) : 0;

		return array(
			'score'    => $score,
			'state'    => self::state( $score ),
			'sections' => $sections,
			'verdict'  => self::verdict( $checks, '' !== $ctx['focus'] ),
			'checks'   => $checks,
			'notices'  => self::notices( $ctx ),
			'words'    => $ctx['word_count'],
		);
	}

	/**
	 * Traffic-light state for a score: good (green), ok (orange) or bad (red).
	 *
	 * @param int $score Score 0-100.
	 * @return string
	 */
	public static function state( $score ) {
		if ( $score >= self::GREEN ) {
			return 'good';
		}
		return $score >= self::ORANGE ? 'ok' : 'bad';
	}

	/**
	 * Parse the input once for all checks.
	 *
	 * @param array $in Raw input.
	 * @return array
	 */
	private static function context( array $in ) {
		$in = wp_parse_args(
			$in,
			array(
				'title'   => '',
				'desc'    => '',
				'slug'    => '',
				'focus'   => '',
				'related' => array(),
				'content' => '',
			)
		);

		$html = (string) $in['content'];
		$html = strip_shortcodes( $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		if ( false === stripos( $html, '<p' ) ) {
			$html = wpautop( $html );
		}

		$text  = MWSEO_Analyzer::plain( $html );
		$words = MWSEO_Analyzer::words( $text );

		return array(
			'title'      => (string) $in['title'],
			'desc'       => (string) $in['desc'],
			'slug'       => (string) $in['slug'],
			'focus'      => trim( (string) $in['focus'] ),
			'related'    => array_values( array_slice( array_filter( array_map( 'trim', (array) $in['related'] ) ), 0, 2 ) ),
			'html'       => $html,
			'text'       => $text,
			'words'      => $words,
			'word_count' => count( $words ),
			'sentences'  => MWSEO_Analyzer::sentences( $text ),
			'paragraphs' => MWSEO_Analyzer::paragraphs( $html ),
			'headings'   => MWSEO_Analyzer::headings( $html ),
			'links'      => MWSEO_Analyzer::links( $html ),
			'images'     => MWSEO_Analyzer::images( $html ),
		);
	}

	// ---- Text & Content (45) ---------------------------------------------------

	/**
	 * Text & Content section.
	 *
	 * @param array $ctx Context.
	 * @return array[]
	 */
	public static function score_text_content( array $ctx ) {
		return array(
			self::check_keyphrase_in_body( $ctx ),
			self::check_headings( $ctx ),
			self::check_readability( $ctx ),
			self::check_internal_link( $ctx ),
		);
	}

	/**
	 * Keyphrase appears in the body text (15).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_keyphrase_in_body( array $ctx ) {
		return self::keyphrase_check(
			'body_keyphrase',
			'content',
			15,
			$ctx['focus'],
			$ctx['text'],
			__( 'The focus keyphrase appears in the body text.', 'manny-wenas-seo' ),
			__( 'Focus keyphrase not found in the body text.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Heading structure: an H2 is present (6) and levels are logical (4) (10).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_headings( array $ctx ) {
		$has_h2  = false;
		$logical = true;
		$prev    = 1;
		foreach ( $ctx['headings'] as $h ) {
			if ( 2 === $h['level'] ) {
				$has_h2 = true;
			}
			if ( $h['level'] > $prev + 1 ) {
				$logical = false;
			}
			$prev = $h['level'];
		}
		$ratio = ( $has_h2 ? 0.6 : 0 ) + ( $has_h2 && $logical ? 0.4 : 0 );

		if ( ! $has_h2 ) {
			$bad = __( 'No H2 subheading detected.', 'manny-wenas-seo' );
		} else {
			$bad = __( 'Heading levels are not sequential (for example H4 follows H2 with no H3).', 'manny-wenas-seo' );
		}
		return self::result( 'headings', 'content', 10, $ratio, __( 'The heading structure is logical.', 'manny-wenas-seo' ), $bad );
	}

	/**
	 * Readability (10): full credit when the average sentence length is 25 words
	 * or less, or when the Flesch reading ease is 60 or more. Above 25 words the
	 * sentence-length result falls to zero at 40 words; a Flesch value between 30
	 * and 60 gives partial credit. Flesch is skipped only for Dutch locales (nl*)
	 * and uses an English syllable heuristic for every other locale; it can raise
	 * the result but never lowers it.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_readability( array $ctx ) {
		$count = count( $ctx['sentences'] );
		if ( 0 === $count ) {
			return self::result( 'readability', 'content', 10, 0, '', __( 'No text available for readability analysis.', 'manny-wenas-seo' ) );
		}

		$sentence_words = 0;
		foreach ( $ctx['sentences'] as $s ) {
			$sentence_words += count( MWSEO_Analyzer::words( $s ) );
		}
		$avg   = $sentence_words / $count;
		$ratio = $avg <= 25 ? 1 : max( 0, 1 - ( $avg - 25 ) / 15 );

		$flesch = self::flesch_reading_ease( $ctx );
		if ( null !== $flesch ) {
			$ratio = max( $ratio, $flesch >= 60 ? 1 : max( 0, ( $flesch - 30 ) / 30 ) );
		}

		return self::result(
			'readability',
			'content',
			10,
			$ratio,
			/* translators: %d: average words per sentence */
			sprintf( __( 'Sentences average about %d words.', 'manny-wenas-seo' ), round( $avg ) ),
			/* translators: %d: average words per sentence */
			sprintf( __( 'Sentences average %d words.', 'manny-wenas-seo' ), round( $avg ) )
		);
	}

	/**
	 * At least one internal link (10).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_internal_link( array $ctx ) {
		return self::result(
			'internal_link',
			'content',
			10,
			$ctx['links']['internal'] >= 1 ? 1 : 0,
			__( 'The text links to other pages on this site.', 'manny-wenas-seo' ),
			__( 'No internal links detected.', 'manny-wenas-seo' )
		);
	}

	// ---- Keyphrase placement (30) ----------------------------------------------

	/**
	 * Keyphrase placement section.
	 *
	 * Five constitutional locations: SEO title, meta description, URL slug,
	 * opening paragraph, and a subheading (H2–H6).
	 *
	 * @param array $ctx Context.
	 * @return array[]
	 */
	public static function score_placement( array $ctx ) {
		return array(
			self::check_title( $ctx ),
			self::check_description( $ctx ),
			self::check_slug( $ctx ),
			self::check_keyphrase_in_opening_paragraph( $ctx ),
			self::check_keyphrase_in_subheading( $ctx ),
		);
	}

	/**
	 * SEO title contains the keyphrase (8).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_title( array $ctx ) {
		return self::keyphrase_check(
			'title_keyphrase',
			'placement',
			8,
			$ctx['focus'],
			$ctx['title'],
			__( 'The focus keyphrase appears in the SEO title.', 'manny-wenas-seo' ),
			__( 'Focus keyphrase not found in the SEO title.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Meta description contains the keyphrase (6).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_description( array $ctx ) {
		return self::keyphrase_check(
			'desc_keyphrase',
			'placement',
			6,
			$ctx['focus'],
			$ctx['desc'],
			__( 'The focus keyphrase appears in the meta description.', 'manny-wenas-seo' ),
			__( 'Focus keyphrase not found in the meta description.', 'manny-wenas-seo' )
		);
	}

	/**
	 * URL slug contains the keyphrase (6).
	 *
	 * N/A while the post has no slug yet (a draft whose slug WordPress has not
	 * generated): an empty slug cannot lack the keyphrase. A nonempty slug scores
	 * as before: 6 with the keyphrase, 0 without it.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_slug( array $ctx ) {
		if ( '' !== $ctx['focus'] && '' === $ctx['slug'] ) {
			return self::not_applicable( 'slug_keyphrase', 'placement', 6, self::slug_unavailable_message() );
		}
		return self::keyphrase_check(
			'slug_keyphrase',
			'placement',
			6,
			$ctx['focus'],
			str_replace( '-', ' ', $ctx['slug'] ),
			__( 'The focus keyphrase appears in the URL slug.', 'manny-wenas-seo' ),
			__( 'Focus keyphrase not found in the URL slug.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Keyphrase appears in the opening paragraph (6).
	 *
	 * Uses the first non-empty block extracted by MWSEO_Analyzer::paragraphs(): a
	 * <p> element, or the first <div> with at least 10 words if no <p> is present.
	 * This is explicit and body-length-independent: an article's opening paragraph
	 * is what it is regardless of total word count.
	 *
	 * N/A only when the content contains no qualifying text block at all.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_keyphrase_in_opening_paragraph( array $ctx ) {
		if ( empty( $ctx['paragraphs'] ) ) {
			return self::not_applicable(
				'intro_keyphrase',
				'placement',
				6,
				__( 'No paragraph detected in the content.', 'manny-wenas-seo' )
			);
		}
		return self::keyphrase_check(
			'intro_keyphrase',
			'placement',
			6,
			$ctx['focus'],
			$ctx['paragraphs'][0],
			__( 'The focus keyphrase appears in the opening paragraph.', 'manny-wenas-seo' ),
			__( 'Focus keyphrase not found in the opening paragraph.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Keyphrase appears in at least one subheading (H2–H6) (4).
	 * N/A when the content contains no subheadings.
	 *
	 * The check uses the same semantic match as every other placement check, so
	 * stemmed variants and synonyms qualify. The writer is never expected to force
	 * the keyphrase into a heading; this location is worth fewer points than the
	 * title or opening section precisely because the editorial decision belongs
	 * to the writer.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_keyphrase_in_subheading( array $ctx ) {
		if ( empty( $ctx['headings'] ) ) {
			return self::not_applicable(
				'subheading_keyphrase',
				'placement',
				4,
				__( 'No subheadings detected.', 'manny-wenas-seo' )
			);
		}

		if ( '' === $ctx['focus'] ) {
			return self::not_applicable( 'subheading_keyphrase', 'placement', 4, __( 'No focus keyphrase set.', 'manny-wenas-seo' ) );
		}

		// Evaluate each heading independently and award the best single match.
		//
		// Concatenating all heading text before matching would let a keyphrase
		// split across two unrelated headings ("Natural Expression" + "Writing
		// Techniques") receive full credit for a focus of "natural writing".
		// That is never a natural placement; the check requires the keyphrase to
		// appear — even in stemmed, synonym-aware form — within a single heading.
		$best = 0.0;
		foreach ( $ctx['headings'] as $h ) {
			$best = max( $best, MWSEO_Analyzer::match( $ctx['focus'], $h['text'] ) );
		}
		$ratio = $best >= 1 ? 1 : ( $best >= 0.6 ? 0.5 : 0 );

		return self::result(
			'subheading_keyphrase',
			'placement',
			4,
			$ratio,
			__( 'The focus keyphrase appears in a subheading.', 'manny-wenas-seo' ),
			__( 'Focus keyphrase not found in any subheading.', 'manny-wenas-seo' )
		);
	}

	// ---- Technical (15) --------------------------------------------------------

	/**
	 * Technical section.
	 *
	 * @param array $ctx Context.
	 * @return array[]
	 */
	public static function score_technical( array $ctx ) {
		return array(
			self::check_alt_text( $ctx ),
			self::check_clean_slug( $ctx ),
		);
	}

	/**
	 * Images have alt text (8). Any non-empty alt passes: this is an
	 * accessibility check, the keyphrase is irrelevant. N/A without images.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_alt_text( array $ctx ) {
		$imgs = $ctx['images'];
		if ( 0 === $imgs['total'] ) {
			return self::not_applicable( 'image_alt', 'technical', 8, __( 'No images to check.', 'manny-wenas-seo' ) );
		}
		return self::result(
			'image_alt',
			'technical',
			8,
			$imgs['with_alt'] / $imgs['total'],
			__( 'All images have alt text.', 'manny-wenas-seo' ),
			/* translators: 1: number of images without alt text, 2: total number of images */
			sprintf( __( '%1$d of %2$d images lack alt text.', 'manny-wenas-seo' ), $imgs['total'] - $imgs['with_alt'], $imgs['total'] )
		);
	}

	/**
	 * Clean slug: no double hyphens, not made only of stop words (3). N/A while
	 * there is no slug yet.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_clean_slug( array $ctx ) {
		$slug = $ctx['slug'];
		if ( '' === $slug ) {
			return self::not_applicable( 'clean_slug', 'technical', 3, self::slug_unavailable_message() );
		}
		$stop    = MWSEO_Analyzer::lang()['stop'];
		$tokens  = array_filter( explode( '-', strtolower( $slug ) ) );
		$content = array_diff( $tokens, $stop );
		$clean   = false === strpos( $slug, '--' ) && ! empty( $content );
		return self::result(
			'clean_slug',
			'technical',
			3,
			$clean ? 1 : 0,
			__( 'The URL slug is clean.', 'manny-wenas-seo' ),
			__( 'URL slug contains double hyphens or consists only of stop words.', 'manny-wenas-seo' )
		);
	}

	// ---- Optional / related keyphrases (10) ------------------------------------

	/**
	 * Optional section.
	 *
	 * @param array $ctx Context.
	 * @return array[]
	 */
	public static function score_optional( array $ctx ) {
		return array( self::check_related( $ctx ) );
	}

	/**
	 * Related keyphrases appear in the body (10 in total, shared between the
	 * keyphrases). N/A, and left out of the total, without a focus keyphrase
	 * or when no related keyphrases are set.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_related( array $ctx ) {
		if ( '' === $ctx['focus'] ) {
			return self::not_applicable( 'related_body', 'optional', 10, __( 'No focus keyphrase set.', 'manny-wenas-seo' ) );
		}
		if ( empty( $ctx['related'] ) ) {
			return self::not_applicable( 'related_body', 'optional', 10, __( 'No related keyphrases configured.', 'manny-wenas-seo' ) );
		}
		$found = 0;
		foreach ( $ctx['related'] as $phrase ) {
			if ( MWSEO_Analyzer::match( $phrase, $ctx['text'] ) >= 1 ) {
				++$found;
			}
		}
		$total   = count( $ctx['related'] );
		$message = sprintf(
			/* translators: 1: related keyphrases found in the text, 2: total related keyphrases */
			__( '%1$d of %2$d related keyphrases appear in the text.', 'manny-wenas-seo' ),
			$found,
			$total
		);
		return self::result( 'related_body', 'optional', 10, $found / $total, $message, $message );
	}

	// ---- Notices, verdict and helpers ------------------------------------------

	/**
	 * Advisory notices. They never change the score.
	 *
	 * @param array $ctx Context.
	 * @return string[]
	 */
	public static function notices( array $ctx ) {
		$out = array();
		if ( $ctx['word_count'] < self::MIN_WORDS ) {
			$out[] = __( 'This post has fewer than 300 words.', 'manny-wenas-seo' );
		}
		if ( 0 === $ctx['links']['external'] && $ctx['word_count'] > 0 ) {
			$out[] = __( 'No external links detected.', 'manny-wenas-seo' );
		}
		return $out;
	}

	/**
	 * Build the structured verdict.
	 *
	 * Returns an array with a factual summary string and structured counts,
	 * so the UI can present the outcome without reinterpreting a string.
	 *
	 * Classification is strict and deterministic:
	 *   full    — points === max (no threshold tolerance)
	 *   partial — 0 < points < max
	 *   none    — points === 0
	 *   na      — check does not apply (excluded from all counts)
	 *
	 * @param array $checks    Results from all scored checks.
	 * @param bool  $has_focus Whether a focus keyphrase exists.
	 * @return array {
	 *     @type string   $summary       Factual one-line summary.
	 *     @type string[] $observations  Messages for unfulfilled checks, highest gap first.
	 *     @type int      $full_count    Checks that received full credit.
	 *     @type int      $partial_count Checks that received partial credit.
	 *     @type int      $none_count    Checks that received no credit.
	 * }
	 */
	private static function verdict( array $checks, $has_focus ) {
		// Report the absence of a focus keyphrase as a neutral observation.
		// Keyphrase checks are simply inactive, not failed.
		if ( ! $has_focus ) {
			return array(
				'summary'       => __( 'Focus keyphrase not set. Keyphrase checks are inactive.', 'manny-wenas-seo' ),
				'observations'  => array(),
				'full_count'    => 0,
				'partial_count' => 0,
				'none_count'    => 0,
			);
		}

		$full_count    = 0;
		$partial_count = 0;
		$none_count    = 0;
		$unfulfilled   = array();

		foreach ( $checks as $c ) {
			if ( $c['na'] ) {
				continue;
			}
			if ( 'full' === $c['classification'] ) {
				++$full_count;
			} elseif ( 'partial' === $c['classification'] ) {
				++$partial_count;
				$unfulfilled[] = $c;
			} else {
				++$none_count;
				$unfulfilled[] = $c;
			}
		}

		// Order unfulfilled checks by the gap between max and earned points so
		// the most impactful observations appear first. Break ties by check ID
		// for a stable, predictable order across PHP versions.
		usort(
			$unfulfilled,
			static function ( $a, $b ) {
				$gap = ( $b['max'] - $b['points'] ) <=> ( $a['max'] - $a['points'] );
				return 0 !== $gap ? $gap : strcmp( $a['id'], $b['id'] );
			}
		);

		if ( 0 === $none_count && 0 === $partial_count ) {
			// Every applicable check received full credit — state it plainly.
			$summary = __( 'All applicable checks received full credit.', 'manny-wenas-seo' );
		} else {
			// Build a precise factual count: "2 checks received no credit; 1 received partial credit."
			$parts = array();
			if ( $none_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of checks with no credit */
					_n( '%d check received no credit', '%d checks received no credit', $none_count, 'manny-wenas-seo' ),
					$none_count
				);
			}
			if ( $partial_count > 0 ) {
				$parts[] = sprintf(
					/* translators: %d: number of checks with partial credit */
					_n( '%d received partial credit', '%d received partial credit', $partial_count, 'manny-wenas-seo' ),
					$partial_count
				);
			}
			$summary = implode( '; ', $parts ) . '.';
		}

		return array(
			'summary'       => $summary,
			'observations'  => array_values( array_column( $unfulfilled, 'message' ) ),
			'full_count'    => $full_count,
			'partial_count' => $partial_count,
			'none_count'    => $none_count,
		);
	}

	/**
	 * Keyphrase check: a full semantic match (stems, synonyms, any order) earns
	 * everything, a partial match half.
	 *
	 * @param string $id       Check ID.
	 * @param string $group    Section key.
	 * @param int    $max      Points.
	 * @param string $focus    Focus keyphrase.
	 * @param string $haystack Text to search.
	 * @param string $good     Pass message.
	 * @param string $bad      Fail message.
	 * @return array
	 */
	private static function keyphrase_check( $id, $group, $max, $focus, $haystack, $good, $bad ) {
		if ( '' === $focus ) {
			return self::not_applicable( $id, $group, $max, __( 'No focus keyphrase set.', 'manny-wenas-seo' ) );
		}
		$m     = MWSEO_Analyzer::match( $focus, $haystack );
		$ratio = $m >= 1 ? 1 : ( $m >= 0.6 ? 0.5 : 0 );
		return self::result( $id, $group, $max, $ratio, $good, $bad );
	}

	/**
	 * Flesch reading ease, computed with an English syllable heuristic for every
	 * locale except Dutch (nl*). Null for Dutch locales and for text without words
	 * or sentences.
	 *
	 * @param array $ctx Context.
	 * @return float|null
	 */
	private static function flesch_reading_ease( array $ctx ) {
		if ( 0 === strpos( get_locale(), 'nl' ) || 0 === $ctx['word_count'] || ! $ctx['sentences'] ) {
			return null;
		}
		$syllables = 0;
		foreach ( $ctx['words'] as $w ) {
			$syllables += self::syllables( $w );
		}
		return 206.835 - 1.015 * ( $ctx['word_count'] / count( $ctx['sentences'] ) ) - 84.6 * ( $syllables / $ctx['word_count'] );
	}

	/**
	 * Rough English syllable count. The word is lower-cased and everything outside
	 * a-z is dropped, so accented and non-Latin letters are ignored; a word left
	 * with no a-z letters counts as one syllable.
	 *
	 * @param string $word Word.
	 * @return int
	 */
	private static function syllables( $word ) {
		$word = preg_replace( '/[^a-z]/', '', strtolower( $word ) );
		if ( '' === $word ) {
			return 1;
		}
		$word = preg_replace( '/(?:[^laeiouy]es|ed|[^laeiouy]e)$/', '', $word );
		$word = preg_replace( '/^y/', '', (string) $word );
		return max( 1, (int) preg_match_all( '/[aeiouy]{1,2}/', (string) $word ) );
	}

	/**
	 * Build one scored check.
	 *
	 * @param string    $id    Check ID.
	 * @param string    $group Section key.
	 * @param int       $max   Points.
	 * @param float|int $ratio 0..1 fraction of the maximum.
	 * @param string    $good  Message when passing.
	 * @param string    $bad   Message when not passing.
	 * @return array
	 */
	private static function result( $id, $group, $max, $ratio, $good, $bad ) {
		$ratio = max( 0.0, min( 1.0, (float) $ratio ) );

		if ( $ratio >= 1.0 ) {
			$classification = 'full';
			$status         = 'good';
		} elseif ( $ratio > 0.0 ) {
			$classification = 'partial';
			$status         = $ratio >= 0.4 ? 'ok' : 'bad';
		} else {
			$classification = 'none';
			$status         = 'bad';
		}

		return array(
			'id'             => $id,
			'group'          => $group,
			'points'         => round( $max * $ratio, 2 ),
			'max'            => $max,
			'status'         => $status,
			'classification' => $classification,
			'na'             => false,
			'message'        => 'full' === $classification ? $good : $bad,
		);
	}

	/**
	 * Explanation shown by both slug checks while the post has no slug yet.
	 *
	 * @return string
	 */
	private static function slug_unavailable_message() {
		return __( 'The URL slug is not available yet.', 'manny-wenas-seo' );
	}

	/**
	 * Build a check that does not apply to this post.
	 *
	 * @param string $id      Check ID.
	 * @param string $group   Section key.
	 * @param int    $max     Points it would have been worth.
	 * @param string $message Explanation (may be empty).
	 * @return array
	 */
	private static function not_applicable( $id, $group, $max, $message ) {
		return array(
			'id'             => $id,
			'group'          => $group,
			'points'         => 0,
			'max'            => $max,
			'status'         => 'na',
			'classification' => 'na',
			'na'             => true,
			'message'        => $message,
		);
	}
}
