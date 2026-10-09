<?php
/**
 * 100-point content score.
 *
 * @package MannyWenasSEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scores a post against the Manny Wenas rubric.
 *
 * Sections: Text & Content 45, Keyphrase placement 30, Technical 15, Optional
 * keyphrases 10. Checks that cannot apply to a post (no related keyphrases, no
 * images, under 300 words) are marked N/A: they are left out of the maximum and
 * the score is rescaled, so they neither add nor remove points. Advisory notices
 * (short text, no external link) never change the score.
 *
 * Every sub-score is its own method so it can be tested or overridden alone.
 */
class MWSEO_Scorer {

	/**
	 * Points per section.
	 */
	const SECTIONS = array(
		'content'   => 45,
		'placement' => 30,
		'technical' => 15,
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
			'verdict'  => self::verdict( $score, $checks, '' !== $ctx['focus'] ),
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
			__( 'Use the focus keyphrase in the body text.', 'manny-wenas-seo' )
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
			$bad = __( 'Add at least one H2 subheading.', 'manny-wenas-seo' );
		} else {
			$bad = __( 'Heading levels skip a step (for example an H4 straight after an H2).', 'manny-wenas-seo' );
		}
		return self::result( 'headings', 'content', 10, $ratio, __( 'The heading structure is logical.', 'manny-wenas-seo' ), $bad );
	}

	/**
	 * Readability: average sentence length of 25 words or less, or a Flesch
	 * reading ease of 60 or more (English text only) (10).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_readability( array $ctx ) {
		$count = count( $ctx['sentences'] );
		if ( 0 === $count ) {
			return self::result( 'readability', 'content', 10, 0, '', __( 'Add some text to assess readability.', 'manny-wenas-seo' ) );
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
			sprintf( __( 'Sentences are easy to read (about %d words on average).', 'manny-wenas-seo' ), round( $avg ) ),
			/* translators: %d: average words per sentence */
			sprintf( __( 'Sentences average %d words; aim for 25 or fewer.', 'manny-wenas-seo' ), round( $avg ) )
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
			__( 'Add at least one internal link.', 'manny-wenas-seo' )
		);
	}

	// ---- Keyphrase placement (30) ----------------------------------------------

	/**
	 * Keyphrase placement section.
	 *
	 * @param array $ctx Context.
	 * @return array[]
	 */
	public static function score_placement( array $ctx ) {
		return array(
			self::check_title( $ctx ),
			self::check_description( $ctx ),
			self::check_slug( $ctx ),
			self::check_first_tenth( $ctx ),
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
			__( 'Use the focus keyphrase (or close variants) in the SEO title.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Meta description contains the keyphrase (7).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_description( array $ctx ) {
		return self::keyphrase_check(
			'desc_keyphrase',
			'placement',
			7,
			$ctx['focus'],
			$ctx['desc'],
			__( 'The focus keyphrase appears in the meta description.', 'manny-wenas-seo' ),
			__( 'Mention the focus keyphrase in the meta description.', 'manny-wenas-seo' )
		);
	}

	/**
	 * URL slug contains the keyphrase (7).
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_slug( array $ctx ) {
		return self::keyphrase_check(
			'slug_keyphrase',
			'placement',
			7,
			$ctx['focus'],
			str_replace( '-', ' ', $ctx['slug'] ),
			__( 'The focus keyphrase appears in the URL slug.', 'manny-wenas-seo' ),
			__( 'Put the focus keyphrase in the URL slug.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Keyphrase appears in the first 10% of the body text (8). Very short texts
	 * use at least their first 20 words, so a multi-word keyphrase can fit.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_first_tenth( array $ctx ) {
		$length = max( 20, (int) ceil( $ctx['word_count'] * 0.1 ) );
		return self::keyphrase_check(
			'intro_keyphrase',
			'placement',
			8,
			$ctx['focus'],
			implode( ' ', array_slice( $ctx['words'], 0, $length ) ),
			__( 'The focus keyphrase appears in the first 10% of the text.', 'manny-wenas-seo' ),
			__( 'Use the focus keyphrase in the first 10% of the text.', 'manny-wenas-seo' )
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
			self::check_word_count( $ctx ),
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
			return self::not_applicable( 'clean_slug', 'technical', 3, __( 'The URL slug is generated when the post is saved.', 'manny-wenas-seo' ) );
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
			__( 'Tidy the URL slug: no double hyphens, and more than just stop words.', 'manny-wenas-seo' )
		);
	}

	/**
	 * Word count (4). Under 300 words is an advisory notice only and takes no
	 * points: the check is N/A, so the score is not affected either way.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_word_count( array $ctx ) {
		if ( $ctx['word_count'] < self::MIN_WORDS ) {
			return self::not_applicable( 'word_count', 'technical', 4, __( 'Word count is not scored for texts under 300 words.', 'manny-wenas-seo' ) );
		}
		return self::result(
			'word_count',
			'technical',
			4,
			1,
			/* translators: %d: word count */
			sprintf( __( 'The text has %d words.', 'manny-wenas-seo' ), $ctx['word_count'] ),
			''
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
	 * keyphrases). N/A, and left out of the total, when none is set.
	 *
	 * @param array $ctx Context.
	 * @return array
	 */
	public static function check_related( array $ctx ) {
		if ( empty( $ctx['related'] ) ) {
			return self::not_applicable( 'related_body', 'optional', 10, __( 'Optional: add related keyphrases to score them.', 'manny-wenas-seo' ) );
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
			$out[] = __( 'This post is under 300 words. Consider expanding it.', 'manny-wenas-seo' );
		}
		if ( 0 === $ctx['links']['external'] && $ctx['word_count'] > 0 ) {
			$out[] = __( 'Add at least one external source link.', 'manny-wenas-seo' );
		}
		return $out;
	}

	/**
	 * Build the written verdict.
	 *
	 * @param int   $score     Score out of 100.
	 * @param array $checks    Results.
	 * @param bool  $has_focus Whether a focus keyphrase exists.
	 * @return string
	 */
	private static function verdict( $score, array $checks, $has_focus ) {
		if ( $score >= self::GREEN ) {
			$text = __( 'Excellent. This content is well optimised and easy to read.', 'manny-wenas-seo' );
		} elseif ( $score >= self::ORANGE ) {
			$text = __( 'Good. A few tweaks would make this stronger.', 'manny-wenas-seo' );
		} else {
			$text = __( 'Needs work. Start with the keyphrase, title and description.', 'manny-wenas-seo' );
		}
		if ( ! $has_focus ) {
			return $text . ' ' . __( 'Set a focus keyphrase to unlock the keyphrase checks.', 'manny-wenas-seo' );
		}
		$misses = array_filter(
			$checks,
			static function ( $c ) {
				return ! $c['na'] && '' !== $c['message'] && $c['points'] < $c['max'] * 0.6;
			}
		);
		usort(
			$misses,
			static function ( $a, $b ) {
				return ( $b['max'] - $b['points'] ) <=> ( $a['max'] - $a['points'] );
			}
		);
		$top = array_slice( $misses, 0, 2 );
		if ( $top ) {
			$text .= ' ' . sprintf(
				/* translators: %s: list of fixes */
				__( 'Biggest wins: %s', 'manny-wenas-seo' ),
				implode( ' ', array_column( $top, 'message' ) )
			);
		}
		return $text;
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
			return self::result( $id, $group, $max, 0, '', __( 'Set a focus keyphrase.', 'manny-wenas-seo' ) );
		}
		$m     = MWSEO_Analyzer::match( $focus, $haystack );
		$ratio = $m >= 1 ? 1 : ( $m >= 0.6 ? 0.5 : 0 );
		return self::result( $id, $group, $max, $ratio, $good, $bad );
	}

	/**
	 * Flesch reading ease (English only; null for other languages).
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
	 * Rough English syllable count.
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
		$ratio = max( 0, min( 1, (float) $ratio ) );
		if ( $ratio >= 0.999 ) {
			$status = 'good';
		} elseif ( $ratio >= 0.4 ) {
			$status = 'ok';
		} else {
			$status = 'bad';
		}
		return array(
			'id'      => $id,
			'group'   => $group,
			'points'  => round( $max * $ratio, 2 ),
			'max'     => $max,
			'status'  => $status,
			'na'      => false,
			'message' => 'good' === $status ? $good : $bad,
		);
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
			'id'      => $id,
			'group'   => $group,
			'points'  => 0,
			'max'     => $max,
			'status'  => 'na',
			'na'      => true,
			'message' => $message,
		);
	}
}
