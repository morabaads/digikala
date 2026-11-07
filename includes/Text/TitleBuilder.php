<?php
/**
 * Builds and cleans Digikala-compliant product titles.
 *
 * @package DKBridge
 */

namespace DKBridge\Text;

/**
 * Title builder (pure).
 *
 * Digikala's general title formula: «ماهیت کالا + برند + مدل + ویژگی‌های متمایزکننده».
 * Categories may expose their own formula through the auto-title `hint_fa`, which
 * takes precedence.
 */
class TitleBuilder {

	/**
	 * Build a Persian title from parts.
	 *
	 * @param array $parts {nature, brand, model, features[], fake(bool), formula(string)}.
	 * @return string
	 */
	public static function build( array $parts ) {
		$nature   = isset( $parts['nature'] ) ? trim( (string) $parts['nature'] ) : '';
		$brand    = ! empty( $parts['fake'] ) ? '' : ( isset( $parts['brand'] ) ? trim( (string) $parts['brand'] ) : '' );
		$model    = isset( $parts['model'] ) ? trim( (string) $parts['model'] ) : '';
		$features = isset( $parts['features'] ) ? array_filter( array_map( 'trim', (array) $parts['features'] ) ) : array();

		if ( in_array( Normalizer::fold( $brand ), array( 'متفرقه', 'miscellaneous', 'other', 'نامشخص' ), true ) ) {
			$brand = '';
		}

		$tokens = array(
			'nature'   => $nature,
			'brand'    => $brand,
			'model'    => '' !== $model ? 'مدل ' . $model : '',
			'features' => implode( ' ', $features ),
		);

		$order = self::formula_order( isset( $parts['formula'] ) ? (string) $parts['formula'] : '' );
		$out   = array();
		foreach ( $order as $key ) {
			if ( isset( $tokens[ $key ] ) && '' !== $tokens[ $key ] ) {
				$out[] = $tokens[ $key ];
			}
		}
		return Normalizer::normalize( Normalizer::dedupe_words( implode( ' ', $out ) ) );
	}

	/**
	 * Translate a Digikala formula hint ("ماهیت کالا + برند کالا + مدل کالا") to token order.
	 *
	 * @param string $formula Hint.
	 * @return string[]
	 */
	public static function formula_order( $formula ) {
		$default = array( 'nature', 'brand', 'model', 'features' );
		if ( '' === trim( $formula ) ) {
			return $default;
		}
		$order = array();
		foreach ( preg_split( '/\s*\+\s*/u', $formula ) as $piece ) {
			$piece = Normalizer::fold( $piece );
			if ( false !== mb_strpos( $piece, 'ماهیت' ) || false !== mb_strpos( $piece, 'نوع' ) ) {
				$order[] = 'nature';
			} elseif ( false !== mb_strpos( $piece, 'برند' ) ) {
				$order[] = 'brand';
			} elseif ( false !== mb_strpos( $piece, 'مدل' ) ) {
				$order[] = 'model';
			} else {
				$order[] = 'features';
			}
		}
		foreach ( $default as $key ) {
			if ( ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		return array_values( array_unique( $order ) );
	}

	/**
	 * Clean an existing title so it passes Digikala's rules.
	 *
	 * @param string $title Title.
	 * @param array  $ctx   {brand, brand_en, model, fake, variant_values[], strip_colors, strip_sizes, forbidden[], max}.
	 * @return array{title:string,changes:string[]}
	 */
	public static function clean( $title, array $ctx = array() ) {
		$changes = array();
		$t       = Normalizer::normalize( (string) $title );

		$step = function ( $label, array $res ) use ( &$t, &$changes ) {
			if ( ! empty( $res['found'] ) ) {
				$changes[] = $label . ': ' . implode( '، ', array_unique( $res['found'] ) );
			}
			$t = $res['text'];
		};

		$step( 'تماس', ContentSanitizer::remove_contact( $t ) );
		$step( 'قیمت', ContentSanitizer::remove_prices( $t ) );
		$step( 'گارانتی', ContentSanitizer::remove_warranty( $t ) );
		$forbidden = isset( $ctx['forbidden'] ) ? (array) $ctx['forbidden'] : ContentSanitizer::TITLE_PROMO_WORDS;
		$step( 'کلمات تبلیغاتی', ContentSanitizer::remove_words( $t, $forbidden ) );

		if ( ! empty( $ctx['strip_colors'] ) || ! empty( $ctx['strip_sizes'] ) || ! empty( $ctx['variant_values'] ) ) {
			$step(
				'رنگ/سایز',
				ContentSanitizer::remove_variant_values(
					$t,
					isset( $ctx['variant_values'] ) ? (array) $ctx['variant_values'] : array(),
					! empty( $ctx['strip_colors'] ),
					! empty( $ctx['strip_sizes'] )
				)
			);
		}

		$before = $t;
		$t      = ContentSanitizer::strip_title_symbols( $t );
		if ( $before !== $t ) {
			$changes[] = 'حذف نمادها';
		}

		// Fake products must not mention any brand.
		if ( ! empty( $ctx['fake'] ) ) {
			$brands = array_filter( array( isset( $ctx['brand'] ) ? $ctx['brand'] : '', isset( $ctx['brand_en'] ) ? $ctx['brand_en'] : '' ) );
			if ( $brands ) {
				$step( 'برند در کالای غیراصل', ContentSanitizer::remove_words( $t, $brands ) );
			}
		}

		// Ensure "مدل" precedes the model code.
		$model = isset( $ctx['model'] ) ? trim( (string) $ctx['model'] ) : '';
		if ( '' !== $model ) {
			$quoted = preg_quote( $model, '/' );
			if ( preg_match( '/(?<![\p{L}\p{N}])' . $quoted . '(?![\p{L}\p{N}])/iu', $t ) ) {
				if ( ! preg_match( '/مدل\s+' . $quoted . '/iu', $t ) ) {
					$t         = preg_replace( '/(?<![\p{L}\p{N}])(?:model\s+)?' . $quoted . '(?![\p{L}\p{N}])/iu', 'مدل ' . $model, $t, 1 );
					$changes[] = 'افزودن «مدل»';
				}
			}
		}

		$before = $t;
		$t      = Normalizer::soften_caps( Normalizer::dedupe_words( $t ) );
		if ( $before !== $t ) {
			$changes[] = 'حذف تکرار';
		}
		$t = Normalizer::normalize( $t );

		$max = isset( $ctx['max'] ) ? (int) $ctx['max'] : 120;
		if ( $max > 0 && Normalizer::length( $t ) > $max ) {
			$t         = Normalizer::truncate_words( $t, $max );
			$changes[] = 'کوتاه‌سازی';
		}

		return array(
			'title'   => $t,
			'changes' => $changes,
		);
	}

	/**
	 * Insert brand / model into a cleaned title if missing.
	 *
	 * @param string $title  Title.
	 * @param string $nature Product nature (e.g. "گوشی موبایل").
	 * @param string $brand  Brand (fa).
	 * @param string $model  Model.
	 * @param bool   $fake   Fake product.
	 * @return string
	 */
	public static function complete( $title, $nature, $brand, $model, $fake ) {
		$title = trim( (string) $title );
		$fold  = Normalizer::fold( $title );
		if ( '' === $title ) {
			return self::build( compact( 'nature', 'brand', 'model', 'fake' ) );
		}
		$brand_missing = ! $fake && '' !== $brand && 'متفرقه' !== $brand && false === mb_strpos( $fold, Normalizer::fold( $brand ) );
		$model_missing = '' !== $model && false === mb_strpos( $fold, Normalizer::fold( $model ) );
		if ( $brand_missing ) {
			$nature_fold = Normalizer::fold( $nature );
			if ( '' !== $nature_fold && 0 === mb_strpos( $fold, $nature_fold ) ) {
				// Insert right after the nature words.
				$words  = preg_split( '/\s+/u', $title );
				$n      = count( preg_split( '/\s+/u', trim( $nature ) ) );
				$title  = implode( ' ', array_merge( array_slice( $words, 0, $n ), array( $brand ), array_slice( $words, $n ) ) );
			} else {
				$title .= ' ' . $brand;
			}
		}
		if ( $model_missing ) {
			$title .= ' مدل ' . $model;
		}
		return Normalizer::normalize( $title );
	}

	/**
	 * Build an English title.
	 *
	 * @param string $nature_en Nature (en) — may be empty.
	 * @param string $brand_en  Brand (en).
	 * @param string $model     Model.
	 * @param bool   $fake      Fake product.
	 * @return string
	 */
	public static function build_en( $nature_en, $brand_en, $model, $fake ) {
		$parts = array();
		if ( ! $fake && '' !== trim( (string) $brand_en ) && 'miscellaneous' !== strtolower( trim( (string) $brand_en ) ) ) {
			$parts[] = trim( $brand_en );
		}
		if ( '' !== trim( (string) $nature_en ) ) {
			$parts[] = trim( $nature_en );
		}
		if ( '' !== trim( (string) $model ) ) {
			$parts[] = 'Model ' . trim( $model );
		}
		return self::clean_en( implode( ' ', $parts ) );
	}

	/**
	 * Keep only Latin characters allowed in English titles.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function clean_en( $title ) {
		$t = Normalizer::latin_digits( Normalizer::strip_emoji( (string) $title ) );
		$t = preg_replace( '/[^A-Za-z0-9\s\-\.,()\/+&\'"]/u', ' ', $t );
		$t = preg_replace( '/\s{2,}/', ' ', $t );
		return trim( Normalizer::soften_caps( $t ), " -,." );
	}
}
