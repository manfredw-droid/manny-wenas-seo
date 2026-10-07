<?php
/**
 * SEO and readability scoring (100 points).
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Scores a piece of content against the Manny Wenas rubric.
 *
 * Rubric: SEO 58 points, readability 36 points (94 raw points). The twelve
 * SEO rows in the rubric sheet sum to 65, so each SEO weight is scaled by
 * 58/65 to make the SEO block total exactly 58. The final score is the raw
 * total normalised to 0-100.
 */
class MWSEO_Analyzer {

	/**
	 * Scale applied to the SEO weights below so the SEO block totals 58.
	 */
	const SEO_SCALE = 58 / 69;

	/**
	 * Point weights per check as listed in the rubric sheet (SEO rows are scaled
	 * by SEO_SCALE when used; see check()).
	 *
	 * @var array
	 */
	const MAX = array(
		'title_present'   => 5,
		'title_length'    => 3,
		'title_keyphrase' => 8,
		'desc_present'    => 5,
		'desc_length'     => 3,
		'desc_keyphrase'  => 7,
		'intro_keyphrase' => 8,
		'subheading_kp'   => 6,
		'slug_keyphrase'  => 5,
		'content_length'  => 6,
		'internal_links'  => 3,
		'related_body'    => 6,
		'image_kp'        => 4,
		'sentence_length' => 9,
		'paragraph_len'   => 7,
		'passive_voice'   => 6,
		'transition'      => 5,
		'subheading_dist' => 5,
		'image_alt'       => 4,
	);

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
	 * @return array Score, verdict and per-check results.
	 */
	public static function analyze( array $in ) {
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

		$focus   = trim( (string) $in['focus'] );
		$related = array_slice( array_filter( array_map( 'trim', (array) $in['related'] ) ), 0, 2 );

		$html = (string) $in['content'];
		$html = strip_shortcodes( $html );
		$html = preg_replace( '/<!--.*?-->/s', '', $html );
		if ( false === stripos( $html, '<p' ) ) {
			$html = wpautop( $html );
		}

		$text       = self::plain( $html );
		$paragraphs = self::paragraphs( $html );
		$sentences  = self::sentences( $text );
		$words      = self::words( $text );
		$word_count = count( $words );
		$headings   = self::headings( $html );

		$r    = array();
		$imgs = self::images( $html );

		// ---- SEO -----------------------------------------------------------------
		$title_len = self::len( $in['title'] );
		$desc_len  = self::len( $in['desc'] );

		$r[] = self::check( 'title_present', 'seo', '' !== trim( $in['title'] ) ? 1 : 0, __( 'SEO title is set.', 'manny-wenas-seo' ), __( 'Add an SEO title.', 'manny-wenas-seo' ) );
		$r[] = self::check(
			'title_length',
			'seo',
			self::range_score( $title_len, 50, 60, 30, 70 ),
			/* translators: %d: length */
			sprintf( __( 'SEO title length is %d characters (target 50–60).', 'manny-wenas-seo' ), $title_len ),
			/* translators: %d: length */
			sprintf( __( 'SEO title is %d characters; aim for 50–60.', 'manny-wenas-seo' ), $title_len )
		);
		$r[] = self::kp_check( 'title_keyphrase', $focus, $in['title'], __( 'The focus keyphrase appears in the SEO title.', 'manny-wenas-seo' ), __( 'Use the focus keyphrase (or close variants) in the SEO title.', 'manny-wenas-seo' ) );
		$r[] = self::check( 'desc_present', 'seo', '' !== trim( $in['desc'] ) ? 1 : 0, __( 'Meta description is set.', 'manny-wenas-seo' ), __( 'Add a meta description.', 'manny-wenas-seo' ) );
		$r[] = self::check(
			'desc_length',
			'seo',
			self::range_score( $desc_len, 120, 155, 70, 200 ),
			/* translators: %d: length */
			sprintf( __( 'Meta description length is %d characters (target 120–155).', 'manny-wenas-seo' ), $desc_len ),
			/* translators: %d: length */
			sprintf( __( 'Meta description is %d characters; aim for 120–155.', 'manny-wenas-seo' ), $desc_len )
		);
		$r[] = self::kp_check( 'desc_keyphrase', $focus, $in['desc'], __( 'The focus keyphrase appears in the meta description.', 'manny-wenas-seo' ), __( 'Mention the focus keyphrase in the meta description.', 'manny-wenas-seo' ) );
		$r[] = self::kp_check( 'intro_keyphrase', $focus, isset( $paragraphs[0] ) ? $paragraphs[0] : '', __( 'The focus keyphrase appears in the opening paragraph.', 'manny-wenas-seo' ), __( 'Use the focus keyphrase in the first paragraph.', 'manny-wenas-seo' ) );
		$r[] = self::kp_check( 'subheading_kp', $focus, implode( ' ', array_column( $headings, 'text' ) ), __( 'The focus keyphrase appears in a subheading.', 'manny-wenas-seo' ), __( 'Use the focus keyphrase in at least one subheading.', 'manny-wenas-seo' ) );
		$r[] = self::kp_check( 'slug_keyphrase', $focus, str_replace( '-', ' ', (string) $in['slug'] ), __( 'The focus keyphrase appears in the URL slug.', 'manny-wenas-seo' ), __( 'Put the focus keyphrase in the URL slug.', 'manny-wenas-seo' ) );
		$r[] = self::check(
			'content_length',
			'seo',
			min( 1, $word_count / 300 ),
			/* translators: %d: word count */
			sprintf( __( 'The text has %d words, which is enough.', 'manny-wenas-seo' ), $word_count ),
			/* translators: %d: word count */
			sprintf( __( 'The text has %d words; 300 or more is recommended.', 'manny-wenas-seo' ), $word_count )
		);

		$links = self::links( $html );
		$r[]   = self::check( 'internal_links', 'seo', $links['internal'] >= 1 ? 1 : 0, __( 'The text links internally.', 'manny-wenas-seo' ), __( 'Add at least one internal link.', 'manny-wenas-seo' ) );

		if ( empty( $related ) ) {
			$r[] = self::check( 'related_body', 'seo', 0, '', __( 'Add a related keyphrase and use it in the body.', 'manny-wenas-seo' ) );
		} else {
			$found = 0;
			foreach ( $related as $phrase ) {
				if ( self::match( $phrase, $text ) >= 1 ) {
					++$found;
				}
			}
			$r[] = self::check(
				'related_body',
				'seo',
				$found / count( $related ),
				/* translators: 1: found, 2: total */
				sprintf( __( '%1$d of %2$d related keyphrases appear in the text.', 'manny-wenas-seo' ), $found, count( $related ) ),
				/* translators: 1: found, 2: total */
				sprintf( __( '%1$d of %2$d related keyphrases appear in the text.', 'manny-wenas-seo' ), $found, count( $related ) )
			);
		}

		$alt_haystack = implode( ' ', $imgs['alts'] );
		$r[]          = self::kp_check(
			'image_kp',
			$focus,
			$alt_haystack,
			__( 'The focus keyphrase appears in an image alt text.', 'manny-wenas-seo' ),
			__( 'Use the focus keyphrase in the alt text of at least one image.', 'manny-wenas-seo' )
		);

		// ---- Readability ---------------------------------------------------------
		$lang = self::lang();

		$long = 0;
		foreach ( $sentences as $s ) {
			if ( count( self::words( $s ) ) > 20 ) {
				++$long;
			}
		}
		$long_pct = $sentences ? 100 * $long / count( $sentences ) : 0;
		$r[]      = self::check(
			'sentence_length',
			'readability',
			self::falloff( $long_pct, 25, 60 ),
			/* translators: %d: percentage */
			sprintf( __( '%d%% of sentences are longer than 20 words.', 'manny-wenas-seo' ), round( $long_pct ) ),
			/* translators: %d: percentage */
			sprintf( __( '%d%% of sentences are longer than 20 words; keep it under 25%%.', 'manny-wenas-seo' ), round( $long_pct ) )
		);

		$long_p = 0;
		foreach ( $paragraphs as $p ) {
			if ( count( self::words( $p ) ) > 150 ) {
				++$long_p;
			}
		}
		$r[] = self::check(
			'paragraph_len',
			'readability',
			0 === $long_p ? 1 : max( 0, 1 - ( $long_p / max( 1, count( $paragraphs ) ) ) * 2 ),
			__( 'Paragraphs are a comfortable length.', 'manny-wenas-seo' ),
			/* translators: %d: count */
			sprintf( _n( '%d paragraph is longer than 150 words.', '%d paragraphs are longer than 150 words.', $long_p, 'manny-wenas-seo' ), $long_p )
		);

		$passive = 0;
		$trans   = 0;
		foreach ( $sentences as $s ) {
			if ( preg_match( $lang['passive'], $s ) ) {
				++$passive;
			}
			if ( preg_match( $lang['transition'], $s ) ) {
				++$trans;
			}
		}
		$passive_pct = $sentences ? 100 * $passive / count( $sentences ) : 0;
		$trans_pct   = $sentences ? 100 * $trans / count( $sentences ) : 0;

		$r[] = self::check(
			'passive_voice',
			'readability',
			self::falloff( $passive_pct, 10, 30 ),
			/* translators: %d: percentage */
			sprintf( __( '%d%% of sentences use passive voice.', 'manny-wenas-seo' ), round( $passive_pct ) ),
			/* translators: %d: percentage */
			sprintf( __( '%d%% of sentences use passive voice; keep it under 10%%.', 'manny-wenas-seo' ), round( $passive_pct ) )
		);
		$r[] = self::check(
			'transition',
			'readability',
			$sentences ? min( 1, $trans_pct / 30 ) : 0,
			/* translators: %d: percentage */
			sprintf( __( '%d%% of sentences contain a transition word.', 'manny-wenas-seo' ), round( $trans_pct ) ),
			/* translators: %d: percentage */
			sprintf( __( 'Only %d%% of sentences contain a transition word; aim for 30%%.', 'manny-wenas-seo' ), round( $trans_pct ) )
		);

		$longest = self::longest_section( $html );
		if ( $word_count <= 300 ) {
			$dist = 1;
		} elseif ( $longest <= 300 ) {
			$dist = 1;
		} else {
			$dist = max( 0, 1 - ( $longest - 300 ) / 300 );
		}
		$r[] = self::check(
			'subheading_dist',
			'readability',
			$dist,
			__( 'Subheadings are well distributed.', 'manny-wenas-seo' ),
			/* translators: %d: word count */
			sprintf( __( 'One section runs %d words without a subheading; break it up (max 300).', 'manny-wenas-seo' ), $longest )
		);

		$r[] = self::check(
			'image_alt',
			'readability',
			0 === $imgs['total'] ? 1 : $imgs['with_alt'] / $imgs['total'],
			0 === $imgs['total'] ? __( 'No images to check.', 'manny-wenas-seo' ) : __( 'All images have alt text.', 'manny-wenas-seo' ),
			/* translators: 1: missing, 2: total */
			sprintf( __( '%1$d of %2$d images lack alt text.', 'manny-wenas-seo' ), $imgs['total'] - $imgs['with_alt'], $imgs['total'] )
		);

		// With no text there is nothing to read: readability cannot earn points.
		if ( 0 === $word_count ) {
			foreach ( $r as $i => $c ) {
				if ( 'readability' === $c['group'] ) {
					$r[ $i ]['points']  = 0;
					$r[ $i ]['status']  = 'bad';
					$r[ $i ]['message'] = __( 'Add some content to assess readability.', 'manny-wenas-seo' );
				}
			}
		}

		// ---- Totals --------------------------------------------------------------
		$raw_total = 0;
		$earned    = 0;
		$seo       = 0;
		$read      = 0;
		$seo_max   = 0;
		$read_max  = 0;
		foreach ( $r as $c ) {
			$raw_total += $c['max'];
			$earned    += $c['points'];
			if ( 'seo' === $c['group'] ) {
				$seo     += $c['points'];
				$seo_max += $c['max'];
			} else {
				$read     += $c['points'];
				$read_max += $c['max'];
			}
		}
		$score = (int) round( 100 * $earned / $raw_total );

		$tips = array();
		if ( 0 === $links['external'] && $word_count > 0 ) {
			$tips[] = __( 'Tip: consider linking to a reputable external source (not scored).', 'manny-wenas-seo' );
		}

		return array(
			'score'       => $score,
			'seo'         => array(
				'points' => round( $seo, 1 ),
				'max'    => round( $seo_max ),
			),
			'readability' => array(
				'points' => round( $read, 1 ),
				'max'    => round( $read_max ),
			),
			'verdict'     => self::verdict( $score, $r, '' !== $focus ),
			'checks'      => $r,
			'tips'        => $tips,
			'words'       => $word_count,
		);
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
		if ( $score >= 85 ) {
			$text = __( 'Excellent. This content is well optimised and easy to read.', 'manny-wenas-seo' );
		} elseif ( $score >= 65 ) {
			$text = __( 'Good. A few tweaks would make this stronger.', 'manny-wenas-seo' );
		} elseif ( $score >= 40 ) {
			$text = __( 'Needs work. Several important checks are failing.', 'manny-wenas-seo' );
		} else {
			$text = __( 'Poor. Start with the keyphrase, title and description.', 'manny-wenas-seo' );
		}
		if ( ! $has_focus ) {
			$text .= ' ' . __( 'Set a focus keyphrase to unlock the keyphrase checks.', 'manny-wenas-seo' );
			return $text;
		}
		$misses = array_filter(
			$checks,
			static function ( $c ) {
				return $c['points'] < $c['max'] * 0.6;
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
	 * Build one check result.
	 *
	 * @param string    $id    Check ID.
	 * @param string    $group seo|readability.
	 * @param float|int $ratio 0..1 fraction of the maximum.
	 * @param string    $good  Message when passing.
	 * @param string    $bad   Message when not passing.
	 * @return array
	 */
	private static function check( $id, $group, $ratio, $good, $bad ) {
		$ratio  = max( 0, min( 1, (float) $ratio ) );
		$max    = 'seo' === $group ? self::MAX[ $id ] * self::SEO_SCALE : self::MAX[ $id ];
		$points = round( $max * $ratio, 2 );
		$max    = round( $max, 2 );
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
			'points'  => $points,
			'max'     => $max,
			'status'  => $status,
			'message' => 'good' === $status ? $good : $bad,
		);
	}

	/**
	 * Keyphrase check wrapper: full points for a full match, half for a partial one.
	 *
	 * @param string $id     Check ID.
	 * @param string $focus  Focus keyphrase.
	 * @param string $haystack Text to search.
	 * @param string $good   Pass message.
	 * @param string $bad    Fail message.
	 * @return array
	 */
	private static function kp_check( $id, $focus, $haystack, $good, $bad ) {
		if ( '' === $focus ) {
			return self::check( $id, 'seo', 0, '', __( 'Set a focus keyphrase.', 'manny-wenas-seo' ) );
		}
		$m     = self::match( $focus, $haystack );
		$ratio = $m >= 1 ? 1 : ( $m >= 0.6 ? 0.5 : 0 );
		return self::check( $id, 'seo', $ratio, $good, $bad );
	}

	/**
	 * Semantic keyphrase match: the share of the phrase's content words (stemmed,
	 * order-independent, synonym-aware) that occur in the text. 1 means all.
	 *
	 * @param string $phrase Keyphrase.
	 * @param string $text   Text to search.
	 * @return float 0..1
	 */
	public static function match( $phrase, $text ) {
		$needles = self::stems( $phrase, true );
		if ( ! $needles ) {
			return 0.0;
		}
		$hay      = array_flip( self::stems( $text, false ) );
		$synonyms = apply_filters( 'mwseo_keyphrase_synonyms', array(), $phrase );
		$hit      = 0;
		foreach ( $needles as $stem ) {
			if ( isset( $hay[ $stem ] ) ) {
				++$hit;
				continue;
			}
			if ( ! empty( $synonyms[ $stem ] ) ) {
				foreach ( (array) $synonyms[ $stem ] as $alt ) {
					if ( isset( $hay[ self::stem( self::lower( $alt ) ) ] ) ) {
						++$hit;
						break;
					}
				}
			}
		}
		return $hit / count( $needles );
	}

	/**
	 * Stem all words in a string.
	 *
	 * @param string $text          Text.
	 * @param bool   $drop_stopwords Remove stopwords.
	 * @return string[]
	 */
	public static function stems( $text, $drop_stopwords ) {
		$out  = array();
		$stop = self::lang()['stop'];
		foreach ( self::words( self::lower( wp_strip_all_tags( (string) $text ) ) ) as $w ) {
			if ( $drop_stopwords && in_array( $w, $stop, true ) ) {
				continue;
			}
			$out[] = self::stem( $w );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Light suffix-stripping stemmer (language-agnostic, deliberately conservative).
	 *
	 * @param string $w Lower-case word.
	 * @return string
	 */
	private static function stem( $w ) {
		if ( mb_strlen( $w ) < 5 ) {
			return $w;
		}
		foreach ( array( 'ations', 'ation', 'ings', 'ing', 'ies', 'ied', 'ers', 'er', 'ed', 'es', 'en', 's', 'ly', 'e' ) as $suffix ) {
			$len = mb_strlen( $suffix );
			if ( mb_strlen( $w ) - $len >= 4 && mb_substr( $w, -$len ) === $suffix ) {
				$w = mb_substr( $w, 0, -$len );
				break;
			}
		}
		return $w;
	}

	/**
	 * Language data (English default, Dutch for nl_* locales).
	 *
	 * @return array
	 */
	private static function lang() {
		static $cache = array();
		$code         = 0 === strpos( get_locale(), 'nl' ) ? 'nl' : 'en';
		if ( isset( $cache[ $code ] ) ) {
			return $cache[ $code ];
		}
		if ( 'nl' === $code ) {
			$data = array(
				'stop'       => explode( ' ', 'de het een en van in op te dat die is voor met als aan zijn er maar om ook dan of bij naar uit over je ik we wat nog wel niet' ),
				'passive'    => '/\b(word|wordt|worden|werd|werden|is|zijn|was|waren)\b(?:\s+\S+){0,3}?\s+ge\w{3,}\b/iu',
				'transition' => '/\b(bovendien|daarnaast|echter|daarom|dus|tenslotte|vervolgens|bijvoorbeeld|ten eerste|ten slotte|omdat|hoewel|maar|want|dan|ook|bovendien|tevens|kortom)\b/iu',
			);
		} else {
			$irregular = 'made|done|given|taken|seen|known|shown|written|built|found|held|kept|left|lost|paid|sold|told|thought|brought|bought|caught|chosen|driven|eaten|fallen|forgotten|gotten|hidden|run|sent|set|put';
			$data      = array(
				'stop'       => explode( ' ', 'the a an and of in on to that this is for with as at are was be by it from or but not you we they have has had will would can could what which who their there been into than then so if' ),
				'passive'    => '/\b(am|is|are|was|were|be|been|being)\b\s+(?:\w+ly\s+)?(\w{3,}(?:ed)|' . $irregular . ')\b/iu',
				'transition' => '/\b(however|therefore|moreover|furthermore|consequently|additionally|meanwhile|nevertheless|because|although|while|for example|for instance|in addition|as a result|finally|first|second|third|next|then|also|thus|besides|similarly|in conclusion|in summary|on the other hand|in fact)\b/iu',
			);
		}
		$cache[ $code ] = apply_filters( 'mwseo_language_data', $data, $code );
		return $cache[ $code ];
	}

	/**
	 * Score 1 inside [lo, hi], falling linearly to 0 at floor/ceil.
	 *
	 * @param int $v     Value.
	 * @param int $lo    Lower target bound.
	 * @param int $hi    Upper target bound.
	 * @param int $floor Value at which the score reaches 0 (low side).
	 * @param int $ceil  Value at which the score reaches 0 (high side).
	 * @return float
	 */
	private static function range_score( $v, $lo, $hi, $floor, $ceil ) {
		if ( $v >= $lo && $v <= $hi ) {
			return 1;
		}
		if ( $v <= 0 ) {
			return 0;
		}
		if ( $v < $lo ) {
			return max( 0, ( $v - $floor ) / ( $lo - $floor ) );
		}
		return max( 0, ( $ceil - $v ) / ( $ceil - $hi ) );
	}

	/**
	 * Score 1 up to a limit, falling to 0 at a bound.
	 *
	 * @param float $pct   Measured percentage.
	 * @param float $limit Percentage that still earns full marks.
	 * @param float $zero  Percentage that earns nothing.
	 * @return float
	 */
	private static function falloff( $pct, $limit, $zero ) {
		if ( $pct <= $limit ) {
			return 1;
		}
		return max( 0, 1 - ( $pct - $limit ) / ( $zero - $limit ) );
	}

	/**
	 * Lower-case a string. WordPress polyfills mb_strlen/mb_substr but not
	 * mb_strtolower, so fall back to strtolower when mbstring is missing.
	 *
	 * @param string $s String.
	 * @return string
	 */
	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/**
	 * Character length (multibyte-safe).
	 *
	 * @param string $s String.
	 * @return int
	 */
	private static function len( $s ) {
		return mb_strlen( trim( (string) $s ) );
	}

	/**
	 * Strip HTML and normalise whitespace.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private static function plain( $html ) {
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#</(p|h[1-6]|li|div|blockquote)>#i', "$0\n", $html );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
	}

	/**
	 * Non-empty paragraph texts.
	 *
	 * @param string $html HTML.
	 * @return string[]
	 */
	private static function paragraphs( $html ) {
		preg_match_all( '#<p[^>]*>(.*?)</p>#is', $html, $m );
		$out = array();
		foreach ( $m[1] as $p ) {
			$p = trim( html_entity_decode( wp_strip_all_tags( $p ), ENT_QUOTES, 'UTF-8' ) );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Split text into sentences.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	private static function sentences( $text ) {
		$parts = preg_split( '/(?<=[.!?])\s+|\n+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return array_values(
			array_filter(
				array_map( 'trim', (array) $parts ),
				static function ( $s ) {
					return count( self::words( $s ) ) >= 2;
				}
			)
		);
	}

	/**
	 * Split text into words.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function words( $text ) {
		return preg_split( "/[^\p{L}\p{N}'’-]+/u", (string) $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Headings h2–h6.
	 *
	 * @param string $html HTML.
	 * @return array[] Each with level and text.
	 */
	private static function headings( $html ) {
		preg_match_all( '#<h([2-6])[^>]*>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $h ) {
			$out[] = array(
				'level' => (int) $h[1],
				'text'  => trim( wp_strip_all_tags( $h[2] ) ),
			);
		}
		return $out;
	}

	/**
	 * Word count of the longest stretch without a subheading.
	 *
	 * @param string $html HTML.
	 * @return int
	 */
	private static function longest_section( $html ) {
		$chunks  = preg_split( '#<h[1-6][^>]*>.*?</h[1-6]>#is', $html );
		$longest = 0;
		foreach ( $chunks as $chunk ) {
			$longest = max( $longest, count( self::words( self::plain( $chunk ) ) ) );
		}
		return $longest;
	}

	/**
	 * Count internal and external links.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private static function links( $html ) {
		preg_match_all( '#<a\s[^>]*href=["\']([^"\']+)["\']#i', $html, $m );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out  = array(
			'internal' => 0,
			'external' => 0,
		);
		foreach ( $m[1] as $href ) {
			if ( 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'mailto:' ) || 0 === strpos( $href, 'tel:' ) ) {
				continue;
			}
			$h = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $h || $h === $host ) {
				++$out['internal'];
			} else {
				++$out['external'];
			}
		}
		return $out;
	}

	/**
	 * Count images and those with alt text.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	private static function images( $html ) {
		preg_match_all( '#<img\b[^>]*>#i', $html, $m );
		$with = 0;
		$alts = array();
		foreach ( $m[0] as $img ) {
			if ( preg_match( '#\balt=(["\'])(.*?)\1#is', $img, $a ) && '' !== trim( $a[2] ) ) {
				++$with;
				$alts[] = trim( $a[2] );
			}
		}
		return array(
			'total'    => count( $m[0] ),
			'with_alt' => $with,
			'alts'     => $alts,
		);
	}
}
