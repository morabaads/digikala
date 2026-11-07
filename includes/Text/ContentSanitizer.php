<?php
/**
 * Cleans descriptions & titles from things Digikala's content team rejects.
 *
 * @package DKBridge
 */

namespace DKBridge\Text;

/**
 * Content sanitizer (pure).
 */
class ContentSanitizer {

	/**
	 * Promotional / value-judgement words not allowed in product titles.
	 *
	 * @var string[]
	 */
	const TITLE_PROMO_WORDS = array(
		'ارزان', 'ارزانترین', 'ارزان‌ترین', 'ارزان قیمت', 'ارزان‌قیمت', 'تخفیف', 'تخفیف‌دار', 'تخفیف ویژه', 'فروش ویژه', 'پیشنهاد ویژه',
		'حراج', 'اورجینال', 'اوریجینال', 'اورجینالی', 'اصل', 'درجه یک', 'درجه 1', 'های کپی', 'هایکپی', 'کپی', 'فیک', 'طرح اصلی',
		'بهترین', 'پرفروش', 'پر فروش', 'پرفروش‌ترین', 'جدید', 'جدیدترین', 'شگفت انگیز', 'شگفت‌انگیز', 'ارسال رایگان', 'ارسال فوری',
		'فوری', 'تعداد محدود', 'هدیه‌دار', 'هدیه دار', 'با هدیه', 'کیفیت عالی', 'باکیفیت', 'با کیفیت', 'بی‌نظیر', 'بی نظیر',
		'لوکس', 'لاکچری', 'VIP', 'فقط امروز', 'اقساطی', 'قسطی', 'عمده', 'موجود شد', 'تضمینی', 'ضمانت بازگشت وجه',
		'best seller', 'sale', 'free shipping', 'cheap', 'original', 'luxury', 'top quality', 'premium quality',
		'100%', '100٪', '۱۰۰٪', '۱۰۰%',
	);

	/**
	 * Promotional phrases removed from descriptions.
	 *
	 * @var string[]
	 */
	const DESCRIPTION_PROMO_PHRASES = array(
		'ارسال رایگان', 'ارسال فوری', 'تخفیف ویژه', 'فروش ویژه', 'حراج', 'ارزان‌ترین', 'ارزانترین', 'بهترین قیمت', 'کمترین قیمت',
		'فقط در فروشگاه ما', 'فقط در سایت ما', 'همین حالا خرید کنید', 'همین الان سفارش دهید', 'برای خرید کلیک کنید', 'تعداد محدود',
		'موجودی محدود', 'فقط امروز', 'تا تمام نشده', 'خرید از فروشگاه', 'free shipping', 'buy now',
	);

	/**
	 * Words that mark contact channels.
	 *
	 * @var string[]
	 */
	const CONTACT_WORDS = array( 'واتساپ', 'واتس اپ', 'واتس‌اپ', 'تلگرام', 'اینستاگرام', 'اینستا', 'دایرکت', 'روبیکا', 'ایتا', 'whatsapp', 'telegram', 'instagram', 'direct', 'پیج ما', 'کانال ما', 'شماره تماس', 'تماس بگیرید', 'تلفن', 'آدرس سایت', 'وب‌سایت', 'وبسایت' );

	/**
	 * Convert HTML (WooCommerce description) to Digikala-friendly plain text.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = (string) $html;
		// Shortcodes.
		$html = preg_replace( '/\[(\/?)[a-zA-Z0-9_\-]+[^\]]*\]/u', ' ', $html );
		// Drop non-content elements entirely.
		$html = preg_replace( '#<(script|style|iframe|noscript|svg|object|video|audio|table|form)\b[^>]*>.*?</\1>#isu', ' ', $html );
		// Block level → newlines.
		$html = preg_replace( '#<\s*(br|hr)\s*/?>#iu', "\n", $html );
		$html = preg_replace( '#</\s*(p|div|h[1-6]|li|ul|ol|section|article|blockquote|tr)\s*>#iu', "\n", $html );
		$html = preg_replace( '#<\s*li[^>]*>#iu', "\n", $html );
		$text = strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = Normalizer::strip_emoji( $text );
		$lines = array_map( 'trim', explode( "\n", str_replace( "\r", '', $text ) ) );
		$lines = array_filter(
			$lines,
			static function ( $line ) {
				return '' !== $line;
			}
		);
		$text = implode( "\n", $lines );
		return Normalizer::normalize( $text );
	}

	/**
	 * Extract <li> items (candidates for advantages).
	 *
	 * @param string $html HTML.
	 * @param int    $max  Max items.
	 * @return string[]
	 */
	public static function list_items( $html, $max = 5 ) {
		$items = array();
		if ( preg_match_all( '#<li[^>]*>(.*?)</li>#isu', (string) $html, $m ) ) {
			foreach ( $m[1] as $raw ) {
				$item = Normalizer::normalize( html_entity_decode( strip_tags( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				$item = trim( self::remove_contact( $item )['text'], " \t\n-–•*:." );
				$len  = Normalizer::length( $item );
				if ( $len >= 3 && $len <= 100 ) {
					$items[] = $item;
				}
				if ( count( $items ) >= $max ) {
					break;
				}
			}
		}
		return $items;
	}

	/**
	 * Remove phone numbers, emails, URLs, social handles and contact phrases.
	 *
	 * @param string $text Text.
	 * @return array{text:string,found:string[]}
	 */
	public static function remove_contact( $text ) {
		$found = array();
		$text  = (string) $text;
		$latin = Normalizer::latin_digits( $text );

		$patterns = array(
			'email' => '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
			'url'   => '#\b(?:https?://|www\.)[^\s<>"\'،]+|\b[a-z0-9\-]+\.(?:ir|com|net|org|shop|store|co)(?:/[^\s]*)?\b#iu',
			'phone' => '/(?:\+|00)?98[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}|\b0?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}\b|\b0\d{2,3}[\s\-]?\d{3,4}[\s\-]?\d{4}\b/u',
			'handle'=> '/(?<![\w.])@[A-Za-z0-9_.]{3,}/u',
		);
		foreach ( $patterns as $type => $pattern ) {
			if ( preg_match_all( $pattern, $latin, $m ) ) {
				foreach ( $m[0] as $hit ) {
					$found[] = $type . ':' . $hit;
				}
				$latin = preg_replace( $pattern, '', $latin );
			}
		}
		// Contact channel words (e.g. "در اینستاگرام ما") — remove the word plus a following "ما".
		foreach ( self::CONTACT_WORDS as $word ) {
			$re = '/(?<![\p{L}])' . preg_quote( $word, '/' ) . '(\s+ما)?(?![\p{L}])/iu';
			if ( preg_match( $re, $latin ) ) {
				$found[] = 'word:' . $word;
				$latin   = preg_replace( $re, '', $latin );
			}
		}
		// Restore Persian digits only if the original had no Latin digits? Digikala accepts both, keep Latin.
		$latin = preg_replace( '/[ ]{2,}/', ' ', $latin );
		$latin = preg_replace( '/\s+([،.,:؛])/u', '$1', $latin );
		return array(
			'text'  => trim( $latin ),
			'found' => $found,
		);
	}

	/**
	 * Remove a list of words/phrases as whole words.
	 *
	 * @param string   $text  Text.
	 * @param string[] $words Words.
	 * @return array{text:string,found:string[]}
	 */
	public static function remove_words( $text, array $words ) {
		$found = array();
		// Longest first so "ارزان قیمت" wins over "ارزان".
		usort(
			$words,
			static function ( $a, $b ) {
				return Normalizer::length( $b ) - Normalizer::length( $a );
			}
		);
		foreach ( $words as $word ) {
			$word = trim( (string) $word );
			if ( '' === $word ) {
				continue;
			}
			$re = '/(?<![\p{L}\p{N}])' . str_replace( '\ ', '[\s\x{200C}]+', preg_quote( $word, '/' ) ) . '(?![\p{L}\p{N}])/iu';
			if ( preg_match( $re, $text ) ) {
				$found[] = $word;
				$text    = preg_replace( $re, ' ', $text );
			}
		}
		$text = preg_replace( '/[ ]{2,}/', ' ', $text );
		$text = preg_replace( '/\s+([،.,:؛])/u', '$1', $text );
		$text = preg_replace( '/(^|\s)[،,\-–]+(\s|$)/u', ' ', $text );
		return array(
			'text'  => trim( $text ),
			'found' => $found,
		);
	}

	/**
	 * Find (without removing) words from a list.
	 *
	 * @param string   $text  Text.
	 * @param string[] $words Words.
	 * @return string[]
	 */
	public static function find_words( $text, array $words ) {
		return self::remove_words( $text, $words )['found'];
	}

	/**
	 * Remove decorative symbols not allowed in titles.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function strip_title_symbols( $text ) {
		$text = Normalizer::strip_emoji( $text );
		$text = preg_replace( '/[!¡★☆✔✓✅❌❤♥*#$^~|<>\[\]{}=_؟?«»"“”‘’`…•●○◆◇■□▪▫※]/u', ' ', $text );
		// Repeated punctuation.
		$text = preg_replace( '/([.,،\-+\/])\1+/u', '$1', $text );
		$text = preg_replace( '/[ ]{2,}/', ' ', $text );
		return trim( $text, " \t\n\r\0\x0B-–—،,.:;" );
	}

	/**
	 * Whether a string has disallowed title symbols.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_title_symbols( $text ) {
		return (bool) preg_match( '/[!¡★☆✔✓✅❌❤♥*#$^~|<>\[\]{}=_؟?«»"“”`…•●○◆◇■□▪▫※]|[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}]/u', (string) $text );
	}

	/**
	 * Remove price mentions (e.g. "۲۵۰ هزار تومان").
	 *
	 * @param string $text Text.
	 * @return array{text:string,found:string[]}
	 */
	public static function remove_prices( $text ) {
		$found = array();
		$re    = '/(?:قیمت\s*:?\s*)?[0-9۰-۹][0-9۰-۹,٬.]*\s*(?:هزار|میلیون|میلیارد)?\s*(?:تومان|تومن|ریال|ت\b)/u';
		if ( preg_match_all( $re, (string) $text, $m ) ) {
			$found = $m[0];
			$text  = preg_replace( $re, ' ', $text );
		}
		return array(
			'text'  => trim( preg_replace( '/[ ]{2,}/', ' ', (string) $text ) ),
			'found' => $found,
		);
	}

	/**
	 * Remove warranty mentions (warranty is selected on the variant, not the title).
	 *
	 * @param string $text Text.
	 * @return array{text:string,found:string[]}
	 */
	public static function remove_warranty( $text ) {
		$re = '/(?:(?:همراه\s+)?با\s+)?(?:گارانتی|ضمانت|وارانتی|warranty)(?:\s+(?:اصالت|سلامت|و|فیزیکی|کالا|تعویض|بازگشت|معتبر|شرکتی|اصلی|رسمی|بین‌المللی|[0-9۰-۹]+|ماهه|ساله|ماه|سال|روزه|روز))*/iu';
		$found = array();
		if ( preg_match_all( $re, (string) $text, $m ) ) {
			$found = $m[0];
			$text  = preg_replace( $re, ' ', $text );
		}
		return array(
			'text'  => trim( preg_replace( '/[ ]{2,}/', ' ', (string) $text ) ),
			'found' => $found,
		);
	}

	/**
	 * Remove "رنگ X" / "سایز X" phrases and explicit variant values.
	 *
	 * @param string   $text   Text.
	 * @param string[] $values Variant values (color/size names) to remove as whole words.
	 * @param bool     $colors Strip "رنگ ..." phrases.
	 * @param bool     $sizes  Strip "سایز ..." phrases.
	 * @return array{text:string,found:string[]}
	 */
	public static function remove_variant_values( $text, array $values, $colors = true, $sizes = true ) {
		$found = array();
		if ( $colors && preg_match_all( '/(?:در\s+)?رنگ\s*(?:بندی)?\s+[\x{0600}-\x{06FF}\x{200C}]+(?:\s+(?:و|،)\s+[\x{0600}-\x{06FF}\x{200C}]+)*/u', $text, $m ) ) {
			$found = array_merge( $found, $m[0] );
			$text  = preg_replace( '/(?:در\s+)?رنگ\s*(?:بندی)?\s+[\x{0600}-\x{06FF}\x{200C}]+(?:\s+(?:و|،)\s+[\x{0600}-\x{06FF}\x{200C}]+)*/u', ' ', $text );
		}
		if ( $sizes && preg_match_all( '/(?:سایز|size)\s*:?\s*[A-Za-z0-9۰-۹]+(?:\s*(?:تا|-|\/)\s*[A-Za-z0-9۰-۹]+)?/iu', $text, $m ) ) {
			$found = array_merge( $found, $m[0] );
			$text  = preg_replace( '/(?:سایز|size)\s*:?\s*[A-Za-z0-9۰-۹]+(?:\s*(?:تا|-|\/)\s*[A-Za-z0-9۰-۹]+)?/iu', ' ', $text );
		}
		$values = array_filter( array_map( 'trim', $values ) );
		if ( $values ) {
			$res   = self::remove_words( $text, $values );
			$text  = $res['text'];
			$found = array_merge( $found, $res['found'] );
		}
		return array(
			'text'  => trim( preg_replace( '/[ ]{2,}/', ' ', $text ) ),
			'found' => $found,
		);
	}
}
