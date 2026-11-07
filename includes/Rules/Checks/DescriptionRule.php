<?php
/**
 * Description, advantages & disadvantages rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;
use DKBridge\Text\ContentSanitizer;
use DKBridge\Text\Normalizer;

/**
 * description / advantage / disadvantage sections.
 */
class DescriptionRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'description';
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();
		$d      = (string) $p->description;
		$min    = (int) $this->cfg( 'desc_min', 150 );
		$max    = (int) $this->cfg( 'desc_max', 5000 );

		if ( '' === trim( $d ) ) {
			$issues[] = $this->issue( 'desc.empty', Issue::WARNING, 'description', 'توضیحات کالا خالی است (۲ امتیاز محتوا).', '' !== trim( strip_tags( $p->description_html ) ) );
		} else {
			if ( $d !== strip_tags( $d ) || preg_match( '/&[a-z]+;|\[[a-z_\-]+[^\]]*\]/i', $d ) ) {
				$issues[] = $this->issue( 'desc.html', Issue::WARNING, 'description', 'توضیحات شامل HTML یا شورت‌کد است.', true );
			}
			$contact = ContentSanitizer::remove_contact( $d );
			if ( $contact['found'] ) {
				$issues[] = $this->issue( 'desc.contact', Issue::ERROR, 'description', 'اطلاعات تماس، لینک یا شبکهٔ اجتماعی در توضیحات مجاز نیست.', true, array( 'found' => $contact['found'] ) );
			}
			$promo = ContentSanitizer::find_words( $d, ContentSanitizer::DESCRIPTION_PROMO_PHRASES );
			if ( $promo ) {
				$issues[] = $this->issue( 'desc.promo', Issue::ERROR, 'description', 'عبارات تبلیغاتی در توضیحات: ' . implode( '، ', $promo ), true );
			}
			if ( ContentSanitizer::remove_prices( $d )['found'] ) {
				$issues[] = $this->issue( 'desc.price', Issue::WARNING, 'description', 'ذکر قیمت در توضیحات توصیه نمی‌شود.', true );
			}
			$len = Normalizer::length( $d );
			if ( $len < $min ) {
				$issues[] = $this->issue( 'desc.length', Issue::WARNING, 'description', sprintf( 'توضیحات کوتاه است (%d نویسه؛ حداقل پیشنهادی %d).', $len, $min ) );
			} elseif ( $len > $max ) {
				$issues[] = $this->issue( 'desc.too_long', Issue::WARNING, 'description', sprintf( 'توضیحات طولانی است (%d نویسه؛ حداکثر %d).', $len, $max ), true );
			}
			if ( Normalizer::persian_ratio( $d ) < 0.5 ) {
				$issues[] = $this->issue( 'desc.persian', Issue::WARNING, 'description', 'بخش عمدهٔ توضیحات فارسی نیست.' );
			}
			if ( Normalizer::strip_emoji( $d ) !== trim( preg_replace( '/ {2,}/', ' ', $d ) ) ) {
				$issues[] = $this->issue( 'desc.emoji', Issue::WARNING, 'description', 'ایموجی در توضیحات وجود دارد.', true );
			}
		}

		// Advantages.
		$adv = array_values( array_filter( array_map( 'trim', $p->advantages ) ) );
		if ( ! $adv ) {
			$can      = (bool) ContentSanitizer::list_items( $p->description_html, 1 );
			$issues[] = $this->issue( 'adv.empty', Issue::WARNING, 'advantage', 'نقاط قوت کالا وارد نشده است (۲ امتیاز محتوا).', $can );
		} else {
			if ( count( $adv ) > 5 ) {
				$issues[] = $this->issue( 'adv.count', Issue::INFO, 'advantage', 'بیش از ۵ نقطهٔ قوت؛ ۵ مورد اول ارسال می‌شود.', true );
			}
			foreach ( $adv as $item ) {
				if ( Normalizer::length( $item ) > 100 || ContentSanitizer::remove_contact( $item )['found'] || ContentSanitizer::find_words( $item, ContentSanitizer::TITLE_PROMO_WORDS ) ) {
					$issues[] = $this->issue( 'adv.invalid', Issue::WARNING, 'advantage', 'یکی از نقاط قوت طولانی است یا عبارت تبلیغاتی/تماس دارد.', true );
					break;
				}
			}
		}

		$dis = array_values( array_filter( array_map( 'trim', $p->disadvantages ) ) );
		if ( ! $dis ) {
			$issues[] = $this->issue( 'disadv.empty', Issue::WARNING, 'disadvantage', 'نقاط ضعف کالا وارد نشده است (۲ امتیاز محتوا). نوشتن یک نقطهٔ ضعف واقعی اعتماد مشتری را بیشتر می‌کند.', '' !== (string) $this->cfg( 'default_disadvantage', '' ) );
		} else {
			foreach ( $dis as $item ) {
				if ( in_array( Normalizer::fold( $item ), array( 'ندارد', 'هیچ', 'ندارد ندارد', 'none', '-' ), true ) ) {
					$issues[] = $this->issue( 'disadv.placeholder', Issue::WARNING, 'disadvantage', '«ندارد» به‌عنوان نقطهٔ ضعف پذیرفته نمی‌شود.', '' !== (string) $this->cfg( 'default_disadvantage', '' ) );
					break;
				}
			}
		}
		return $issues;
	}

	/**
	 * Fix.
	 *
	 * @param ProductData $p      Product.
	 * @param Issue[]     $issues Issues.
	 * @return string[]
	 */
	public function fix( ProductData $p, array $issues ) {
		$changes = array();
		$before  = $p->description;
		$d       = '' === trim( $p->description ) ? $p->description_html : $p->description;
		$d       = ContentSanitizer::html_to_text( $d );
		$c       = ContentSanitizer::remove_contact( $d );
		$d       = $c['text'];
		$r       = ContentSanitizer::remove_words( $d, ContentSanitizer::DESCRIPTION_PROMO_PHRASES );
		$d       = $r['text'];
		$d       = ContentSanitizer::remove_prices( $d )['text'];
		$d       = Normalizer::normalize( Normalizer::strip_emoji( $d ) );
		$max     = (int) $this->cfg( 'desc_max', 5000 );
		if ( Normalizer::length( $d ) > $max ) {
			$d = Normalizer::truncate_words( $d, $max );
		}
		$p->description = $d;
		if ( $before !== $d ) {
			$changes[] = 'پاک‌سازی توضیحات (HTML، تماس، عبارات تبلیغاتی)';
		}

		$adv = array_values( array_filter( array_map( 'trim', $p->advantages ) ) );
		if ( ! $adv ) {
			$adv = ContentSanitizer::list_items( $p->description_html, 5 );
			if ( $adv ) {
				$changes[] = 'استخراج نقاط قوت از فهرست توضیحات';
			}
		}
		$clean = array();
		foreach ( $adv as $item ) {
			$item = ContentSanitizer::remove_words( ContentSanitizer::remove_contact( Normalizer::normalize( Normalizer::strip_emoji( $item ) ) )['text'], ContentSanitizer::TITLE_PROMO_WORDS )['text'];
			$item = Normalizer::truncate_words( trim( $item, " -–•*:." ), 100 );
			if ( Normalizer::length( $item ) >= 3 ) {
				$clean[] = $item;
			}
		}
		$p->advantages = array_slice( array_values( array_unique( $clean ) ), 0, 5 );

		$dis = array();
		foreach ( $p->disadvantages as $item ) {
			$item = Normalizer::truncate_words( Normalizer::normalize( Normalizer::strip_emoji( trim( (string) $item ) ) ), 100 );
			if ( '' !== $item && ! in_array( Normalizer::fold( $item ), array( 'ندارد', 'هیچ', 'none', '-' ), true ) ) {
				$dis[] = $item;
			}
		}
		if ( ! $dis && '' !== (string) $this->cfg( 'default_disadvantage', '' ) ) {
			$dis[]     = (string) $this->cfg( 'default_disadvantage' );
			$changes[] = 'افزودن نقطهٔ ضعف پیش‌فرض';
		}
		$p->disadvantages = array_slice( $dis, 0, 5 );
		return $changes;
	}
}
