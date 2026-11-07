<?php
/**
 * Persian text normalization.
 *
 * @package DKBridge
 */

namespace DKBridge\Text;

/**
 * Pure helpers — no WordPress dependency.
 */
class Normalizer {

	const ZWNJ = "\u{200C}";

	/**
	 * Arabic → Persian letter map.
	 *
	 * @var array<string,string>
	 */
	const LETTERS = array(
		'ي' => 'ی',
		'ى' => 'ی',
		'ئ' => 'ی',
		'ك' => 'ک',
		'ة' => 'ه',
		'ۀ' => 'ه',
		'أ' => 'ا',
		'إ' => 'ا',
		'ٱ' => 'ا',
		'ؤ' => 'و',
		'٪' => '%',
		'٫' => '.',
		'٬' => ',',
	);

	/**
	 * Full normalization pipeline used on all outgoing Persian text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return '';
		}
		$text = strtr( $text, self::LETTERS );
		// Diacritics, tatweel, superscript alef.
		$text = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text );
		// Invisible / bidi control characters (keep ZWNJ).
		$text = preg_replace( '/[\x{200B}\x{200D}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}\x{00AD}]/u', '', $text );
		// Non-breaking & exotic spaces → normal space.
		$text = preg_replace( '/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}\t]/u', ' ', $text );
		$text = self::fix_zwnj( $text );
		// Space before punctuation, none after opening bracket.
		$text = preg_replace( '/\s+([،؛:.!؟?,)\]])/u', '$1', $text );
		$text = preg_replace( '/([(\[])\s+/u', '$1', $text );
		// Collapse repeated ZWNJ / spaces.
		$text = preg_replace( '/\x{200C}{2,}/u', self::ZWNJ, $text );
		$text = preg_replace( '/ *\x{200C} */u', self::ZWNJ, $text );
		$text = preg_replace( '/[ ]{2,}/', ' ', $text );
		$text = preg_replace( "/ *\n */", "\n", $text );
		return trim( $text );
	}

	/**
	 * Insert ZWNJ for common prefixes / suffixes written with a space.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fix_zwnj( $text ) {
		// "می شود" → "می‌شود", "نمی خواهد" → "نمی‌خواهد".
		$text = preg_replace( '/(^|[\s(])(ن?می) +(?=[\x{0600}-\x{06FF}]{2,})/u', '$1$2' . self::ZWNJ, $text );
		// Plural / comparative suffixes.
		$text = preg_replace( '/([\x{0600}-\x{06FF}]{2,}) +(ها|های|هایی|هایم|هایش|تر|ترین|تری)(?=$|[\s،.؛:!؟)])/u', '$1' . self::ZWNJ . '$2', $text );
		return $text;
	}

	/**
	 * Convert Persian / Arabic digits to Latin.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function latin_digits( $text ) {
		return strtr(
			(string) $text,
			array(
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			)
		);
	}

	/**
	 * Remove emoji and decorative pictographs.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_emoji( $text ) {
		$pattern = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}\x{25A0}-\x{25FF}\x{2190}-\x{21FF}\x{FE0F}\x{20E3}\x{E000}-\x{F8FF}]/u';
		return trim( preg_replace( '/ {2,}/', ' ', preg_replace( $pattern, '', (string) $text ) ) );
	}

	/**
	 * Fold text for fuzzy comparisons.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function fold( $text ) {
		$text = self::latin_digits( self::normalize( $text ) );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = str_replace( self::ZWNJ, ' ', $text );
		$text = str_replace( array( 'آ' ), 'ا', $text );
		$text = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	/**
	 * Ratio (0..1) of Persian letters among all letters.
	 *
	 * @param string $text Text.
	 * @return float
	 */
	public static function persian_ratio( $text ) {
		$letters = preg_match_all( '/\p{L}/u', (string) $text );
		if ( ! $letters ) {
			return 0.0;
		}
		$persian = preg_match_all( '/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}]/u', (string) $text );
		return $persian / $letters;
	}

	/**
	 * Whether the text contains Persian/Arabic letters.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_persian( $text ) {
		return (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', (string) $text );
	}

	/**
	 * Multibyte length.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $text, 'UTF-8' ) : strlen( (string) $text );
	}

	/**
	 * Truncate at a word boundary.
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 * @return string
	 */
	public static function truncate_words( $text, $max ) {
		$text = trim( (string) $text );
		if ( self::length( $text ) <= $max ) {
			return $text;
		}
		$cut  = mb_substr( $text, 0, $max + 1, 'UTF-8' );
		$last = mb_strrpos( $cut, ' ', 0, 'UTF-8' );
		if ( false !== $last && $last > $max * 0.6 ) {
			$cut = mb_substr( $cut, 0, $last, 'UTF-8' );
		} else {
			$cut = mb_substr( $cut, 0, $max, 'UTF-8' );
		}
		return rtrim( $cut, " \t\n\r\0\x0B،,-–—:؛" );
	}

	/**
	 * Remove repeated adjacent / duplicate words (case-insensitive) while keeping order.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function dedupe_words( $text ) {
		$words = preg_split( '/\s+/u', trim( (string) $text ) );
		$seen  = array();
		$out   = array();
		foreach ( $words as $word ) {
			$key = self::fold( $word );
			// Allow repeating short connectors, numbers and the word "مدل" is unique anyway.
			$is_connector = in_array( $key, array( 'و', 'با', 'در', 'از', 'به', 'برای', 'x', '-', '+' ), true ) || '' === $key || is_numeric( $key );
			if ( ! $is_connector && isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $word;
		}
		return implode( ' ', $out );
	}

	/**
	 * Title-case all-caps Latin words longer than 4 letters.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function soften_caps( $text ) {
		return preg_replace_callback(
			'/\b[A-Z]{5,}\b/',
			static function ( $m ) {
				return ucfirst( strtolower( $m[0] ) );
			},
			(string) $text
		);
	}
}
