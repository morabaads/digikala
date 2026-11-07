<?php
/**
 * Fuzzy text similarity for Persian/English names.
 *
 * @package DKBridge
 */

namespace DKBridge\Matching;

use DKBridge\Text\Normalizer;

/**
 * Similarity helpers (pure).
 */
class Similarity {

	/**
	 * Common synonyms, folded form → canonical.
	 *
	 * @var array<string,string>
	 */
	const SYNONYMS = array(
		'سیاه'         => 'مشکی',
		'black'        => 'مشکی',
		'white'        => 'سفید',
		'خاکستری'      => 'طوسی',
		'gray'         => 'طوسی',
		'grey'         => 'طوسی',
		'red'          => 'قرمز',
		'blue'         => 'ابی',
		'green'        => 'سبز',
		'yellow'       => 'زرد',
		'orange'       => 'نارنجی',
		'purple'       => 'بنفش',
		'pink'         => 'صورتی',
		'brown'        => 'قهوه ای',
		'silver'       => 'نقره ای',
		'gold'         => 'طلایی',
		'navy'         => 'سرمه ای',
		'navy blue'    => 'سرمه ای',
		'beige'        => 'کرم',
		'بژ'           => 'کرم',
		'rose gold'    => 'رزگلد',
		'cream'        => 'کرم',
		'لاجوردی'      => 'ابی',
		'فیلی'         => 'طوسی',
		'نقره'         => 'نقره ای',
		'طلا'          => 'طلایی',
		'small'        => 's',
		'medium'       => 'm',
		'large'        => 'l',
		'x large'      => 'xl',
		'xlarge'       => 'xl',
		'xx large'     => 'xxl',
		'2xl'          => 'xxl',
		'3xl'          => 'xxxl',
		'فری سایز'     => 'free size',
		'فریسایز'      => 'free size',
		'تک سایز'      => 'free size',
	);

	/**
	 * Canonical folded form.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function canon( $text ) {
		$f = Normalizer::fold( $text );
		return isset( self::SYNONYMS[ $f ] ) ? self::SYNONYMS[ $f ] : $f;
	}

	/**
	 * Token Jaccard similarity.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return float
	 */
	public static function jaccard( $a, $b ) {
		$ta = array_unique( array_filter( explode( ' ', Normalizer::fold( $a ) ) ) );
		$tb = array_unique( array_filter( explode( ' ', Normalizer::fold( $b ) ) ) );
		if ( ! $ta || ! $tb ) {
			return 0.0;
		}
		$inter = count( array_intersect( $ta, $tb ) );
		$union = count( array_unique( array_merge( $ta, $tb ) ) );
		return $union ? $inter / $union : 0.0;
	}

	/**
	 * Multibyte Levenshtein ratio (1 = identical).
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return float
	 */
	public static function levenshtein_ratio( $a, $b ) {
		$a = preg_split( '//u', Normalizer::fold( $a ), -1, PREG_SPLIT_NO_EMPTY );
		$b = preg_split( '//u', Normalizer::fold( $b ), -1, PREG_SPLIT_NO_EMPTY );
		$la = count( $a );
		$lb = count( $b );
		if ( 0 === $la && 0 === $lb ) {
			return 1.0;
		}
		if ( 0 === $la || 0 === $lb ) {
			return 0.0;
		}
		// Keep it cheap for long strings.
		if ( $la > 120 || $lb > 120 ) {
			return self::jaccard( implode( '', $a ), implode( '', $b ) );
		}
		$prev = range( 0, $lb );
		for ( $i = 1; $i <= $la; $i++ ) {
			$cur = array( $i );
			for ( $j = 1; $j <= $lb; $j++ ) {
				$cost    = ( $a[ $i - 1 ] === $b[ $j - 1 ] ) ? 0 : 1;
				$cur[ $j ] = min( $prev[ $j ] + 1, $cur[ $j - 1 ] + 1, $prev[ $j - 1 ] + $cost );
			}
			$prev = $cur;
		}
		return 1 - ( $prev[ $lb ] / max( $la, $lb ) );
	}

	/**
	 * Combined score.
	 *
	 * @param string $a A.
	 * @param string $b B.
	 * @return float
	 */
	public static function score( $a, $b ) {
		$ca = self::canon( $a );
		$cb = self::canon( $b );
		if ( '' !== $ca && $ca === $cb ) {
			return 1.0;
		}
		return round( 0.55 * self::jaccard( $ca, $cb ) + 0.45 * self::levenshtein_ratio( $ca, $cb ), 4 );
	}

	/**
	 * Best candidate.
	 *
	 * @param string               $needle     Needle.
	 * @param array<string|int,string> $candidates key => label.
	 * @param float                $threshold  Minimum score.
	 * @return array{key:string|int|null,score:float}
	 */
	public static function best( $needle, array $candidates, $threshold = 0.0 ) {
		$best_key   = null;
		$best_score = 0.0;
		foreach ( $candidates as $key => $label ) {
			$s = self::score( $needle, (string) $label );
			if ( $s > $best_score ) {
				$best_score = $s;
				$best_key   = $key;
			}
		}
		if ( $best_score < $threshold ) {
			$best_key = null;
		}
		return array(
			'key'   => $best_key,
			'score' => $best_score,
		);
	}

	/**
	 * Nearest color by RGB distance.
	 *
	 * @param string               $hex        #rrggbb.
	 * @param array<string|int,string> $candidates key => #rrggbb.
	 * @return array{key:string|int|null,distance:float}
	 */
	public static function nearest_color( $hex, array $candidates ) {
		$rgb = self::hex_to_rgb( $hex );
		if ( ! $rgb ) {
			return array(
				'key'      => null,
				'distance' => INF,
			);
		}
		$best = null;
		$dist = INF;
		foreach ( $candidates as $key => $cand ) {
			$c = self::hex_to_rgb( $cand );
			if ( ! $c ) {
				continue;
			}
			// Weighted Euclidean ("redmean") approximation of perceived difference.
			$rm = ( $rgb[0] + $c[0] ) / 2;
			$d  = sqrt( ( 2 + $rm / 256 ) * pow( $rgb[0] - $c[0], 2 ) + 4 * pow( $rgb[1] - $c[1], 2 ) + ( 2 + ( 255 - $rm ) / 256 ) * pow( $rgb[2] - $c[2], 2 ) );
			if ( $d < $dist ) {
				$dist = $d;
				$best = $key;
			}
		}
		return array(
			'key'      => $best,
			'distance' => $dist,
		);
	}

	/**
	 * Parse hex color.
	 *
	 * @param string $hex Hex.
	 * @return int[]|null
	 */
	public static function hex_to_rgb( $hex ) {
		$hex = ltrim( trim( (string) $hex ), '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( ! preg_match( '/^[0-9a-f]{6}$/i', $hex ) ) {
			return null;
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}
}
