<?php
/**
 * Persian title rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;
use DKBridge\Text\ContentSanitizer;
use DKBridge\Text\Normalizer;
use DKBridge\Text\TitleBuilder;

/**
 * title_fa section.
 */
class TitleRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'title_fa';
	}

	/**
	 * Forbidden words (defaults + configured extra).
	 *
	 * @return string[]
	 */
	private function forbidden() {
		$extra = $this->cfg( 'extra_forbidden_words', array() );
		if ( is_string( $extra ) ) {
			$extra = preg_split( '/[\n,،]+/u', $extra );
		}
		return array_values( array_filter( array_unique( array_merge( ContentSanitizer::TITLE_PROMO_WORDS, array_map( 'trim', (array) $extra ) ) ) ) );
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();
		$t      = trim( $p->title_fa );
		$min    = (int) $this->cfg( 'title_min', 15 );
		$max    = (int) $this->cfg( 'title_max', 120 );

		if ( '' === $t ) {
			return array( $this->issue( 'title.empty', Issue::ERROR, 'title_fa', 'عنوان فارسی خالی است.', true ) );
		}
		if ( ! Normalizer::has_persian( $t ) ) {
			$issues[] = $this->issue( 'title.latin_only', Issue::ERROR, 'title_fa', 'عنوان فارسی هیچ حرف فارسی ندارد؛ ماهیت کالا را به فارسی بنویسید.', '' !== $p->nature );
		}
		if ( Normalizer::normalize( $t ) !== $t ) {
			$issues[] = $this->issue( 'title.normalize', Issue::INFO, 'title_fa', 'نویسه‌های عربی، فاصله یا نیم‌فاصلهٔ نادرست در عنوان وجود دارد.', true );
		}
		$len = Normalizer::length( $t );
		if ( $len < $min ) {
			$issues[] = $this->issue( 'title.short', Issue::WARNING, 'title_fa', sprintf( 'عنوان کوتاه است (%d نویسه؛ حداقل %d).', $len, $min ), '' !== $p->nature || '' !== $p->model );
		} elseif ( $len > 255 ) {
			$issues[] = $this->issue( 'title.length', Issue::ERROR, 'title_fa', sprintf( 'عنوان بیش از ۲۵۵ نویسه است (%d).', $len ), true );
		} elseif ( $len > $max ) {
			$issues[] = $this->issue( 'title.length', Issue::WARNING, 'title_fa', sprintf( 'عنوان طولانی است (%d نویسه؛ حداکثر پیشنهادی %d).', $len, $max ), true );
		}

		$promo = ContentSanitizer::find_words( $t, $this->forbidden() );
		if ( $promo ) {
			$issues[] = $this->issue( 'title.promo_words', Issue::ERROR, 'title_fa', 'کلمات تبلیغاتی یا ارزشی در عنوان: ' . implode( '، ', $promo ), true, array( 'words' => $promo ) );
		}
		$contact = ContentSanitizer::remove_contact( $t );
		if ( $contact['found'] ) {
			$issues[] = $this->issue( 'title.contact', Issue::ERROR, 'title_fa', 'اطلاعات تماس یا آدرس در عنوان مجاز نیست.', true, array( 'found' => $contact['found'] ) );
		}
		if ( ContentSanitizer::remove_prices( $t )['found'] ) {
			$issues[] = $this->issue( 'title.price', Issue::ERROR, 'title_fa', 'ذکر قیمت در عنوان مجاز نیست.', true );
		}
		if ( ContentSanitizer::remove_warranty( $t )['found'] ) {
			$issues[] = $this->issue( 'title.warranty', Issue::ERROR, 'title_fa', 'گارانتی هنگام ساخت تنوع انتخاب می‌شود و نباید در عنوان بیاید.', true );
		}
		if ( ContentSanitizer::has_title_symbols( $t ) ) {
			$issues[] = $this->issue( 'title.symbols', Issue::ERROR, 'title_fa', 'نماد، ایموجی یا علامت غیرمجاز در عنوان وجود دارد.', true );
		}

		$strip_colors = 'colored' === $p->category_theme;
		$strip_sizes  = 'sized' === $p->category_theme;
		if ( $strip_colors || $strip_sizes || $p->variant_values ) {
			$res = ContentSanitizer::remove_variant_values( $t, $p->variant_values, $strip_colors, $strip_sizes );
			if ( $res['found'] ) {
				$issues[] = $this->issue( 'title.variant_attr', Issue::WARNING, 'title_fa', 'رنگ یا سایز جزو تنوع کالاست و نباید در عنوان بیاید: ' . implode( '، ', array_unique( $res['found'] ) ), true );
			}
		}

		if ( $p->fake && ( $p->brand_fa || $p->brand_en ) ) {
			$found = ContentSanitizer::find_words( $t, array_filter( array( $p->brand_fa, $p->brand_en ) ) );
			if ( $found && ! $p->brand_misc ) {
				$issues[] = $this->issue( 'title.brand_when_fake', Issue::ERROR, 'title_fa', 'کالای غیراصل نباید نام برند را در عنوان داشته باشد.', true );
			}
		}
		$fold = Normalizer::fold( $t );
		if ( ! $p->fake && ! $p->brand_misc && '' !== $p->brand_fa && false === mb_strpos( $fold, Normalizer::fold( $p->brand_fa ) ) && ( '' === $p->brand_en || false === mb_strpos( $fold, Normalizer::fold( $p->brand_en ) ) ) ) {
			$issues[] = $this->issue( 'title.missing_brand', Issue::WARNING, 'title_fa', 'نام برند در عنوان نیامده است.', true );
		}
		if ( '' !== $p->model && false === mb_strpos( $fold, Normalizer::fold( $p->model ) ) ) {
			$issues[] = $this->issue( 'title.missing_model', Issue::WARNING, 'title_fa', 'مدل کالا در عنوان نیامده است (الگو: «… مدل X»).', true );
		} elseif ( '' !== $p->model && ! preg_match( '/مدل\s+' . preg_quote( $p->model, '/' ) . '/iu', $t ) ) {
			$issues[] = $this->issue( 'title.model_prefix', Issue::INFO, 'title_fa', 'بهتر است پیش از مدل کالا کلمهٔ «مدل» بیاید.', true );
		}
		if ( Normalizer::dedupe_words( $t ) !== $t ) {
			$issues[] = $this->issue( 'title.duplicate_words', Issue::WARNING, 'title_fa', 'کلمهٔ تکراری در عنوان وجود دارد.', true );
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
		$before = $p->title_fa;
		if ( '' === trim( $p->title_fa ) || ! Normalizer::has_persian( $p->title_fa ) ) {
			$p->title_fa = TitleBuilder::build(
				array(
					'nature'  => $p->nature,
					'brand'   => $p->brand_fa,
					'model'   => $p->model,
					'fake'    => $p->fake,
					'formula' => $p->title_formula,
				)
			);
		}
		$res         = TitleBuilder::clean(
			$p->title_fa,
			array(
				'brand'          => $p->brand_fa,
				'brand_en'       => $p->brand_en,
				'model'          => $p->model,
				'fake'           => $p->fake && ! $p->brand_misc,
				'variant_values' => $p->variant_values,
				'strip_colors'   => 'colored' === $p->category_theme,
				'strip_sizes'    => 'sized' === $p->category_theme,
				'forbidden'      => $this->forbidden(),
				'max'            => 0,
			)
		);
		$p->title_fa = TitleBuilder::complete( $res['title'], $p->nature, $p->brand_misc ? '' : $p->brand_fa, $p->model, $p->fake );

		// Too short after cleaning → rebuild from parts and append the remainder.
		if ( Normalizer::length( $p->title_fa ) < (int) $this->cfg( 'title_min', 15 ) && '' !== $p->nature && 0 !== mb_strpos( Normalizer::fold( $p->title_fa ), Normalizer::fold( $p->nature ) ) ) {
			$p->title_fa = Normalizer::normalize( Normalizer::dedupe_words( $p->nature . ' ' . $p->title_fa ) );
		}
		$max = (int) $this->cfg( 'title_max', 120 );
		if ( Normalizer::length( $p->title_fa ) > $max ) {
			$p->title_fa = Normalizer::truncate_words( $p->title_fa, $max );
		}
		return $before !== $p->title_fa ? array_merge( $res['changes'], array( sprintf( 'عنوان: «%s» ← «%s»', $before, $p->title_fa ) ) ) : array();
	}
}
