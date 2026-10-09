<?php
/**
 * Text analysis helpers.
 *
 * @package MannyWenasSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Text analysis helpers (parsing, semantic keyphrase matching, language data).
 *
 * The scoring itself lives in MWSEO_Scorer; analyze() is kept as the entry point
 * used by the REST API and the Abilities API.
 */
class MWSEO_Analyzer {

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
		return MWSEO_Scorer::score( $in );
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
	public static function lang() {
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
	 * Lower-case a string. WordPress polyfills mb_strlen/mb_substr but not
	 * mb_strtolower, so fall back to strtolower when mbstring is missing.
	 *
	 * @param string $s String.
	 * @return string
	 */
	public static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s, 'UTF-8' ) : strtolower( (string) $s );
	}

	/**
	 * Character length (multibyte-safe).
	 *
	 * @param string $s String.
	 * @return int
	 */
	public static function len( $s ) {
		return mb_strlen( trim( (string) $s ) );
	}

	/**
	 * Strip HTML and normalise whitespace.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function plain( $html ) {
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
	public static function paragraphs( $html ) {
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
	public static function sentences( $text ) {
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
	public static function headings( $html ) {
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
	 * Count internal and external links.
	 *
	 * @param string $html HTML.
	 * @return array
	 */
	public static function links( $html ) {
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
	public static function images( $html ) {
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
