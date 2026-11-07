<?php
/**
 * English title rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;
use DKBridge\Text\Normalizer;
use DKBridge\Text\TitleBuilder;

/**
 * title_en section.
 */
class EnglishTitleRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'title_en';
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$t = trim( $p->title_en );
		if ( '' === $t ) {
			$can = ( '' !== $p->brand_en && ! $p->brand_misc ) || '' !== $p->model || '' !== $p->nature_en;
			return array( $this->issue( 'title_en.empty', Issue::WARNING, 'title_en', 'عنوان انگلیسی خالی است (۲ امتیاز محتوا).', $can ) );
		}
		$issues = array();
		if ( Normalizer::has_persian( $t ) ) {
			$issues[] = $this->issue( 'title_en.non_latin', Issue::ERROR, 'title_en', 'عنوان انگلیسی نباید حروف فارسی داشته باشد.', true );
		} elseif ( TitleBuilder::clean_en( $t ) !== $t ) {
			$issues[] = $this->issue( 'title_en.symbols', Issue::WARNING, 'title_en', 'نماد یا حروف غیرمجاز در عنوان انگلیسی.', true );
		}
		if ( $p->fake && $p->brand_en && ! $p->brand_misc && false !== stripos( $t, $p->brand_en ) ) {
			$issues[] = $this->issue( 'title_en.brand_when_fake', Issue::ERROR, 'title_en', 'کالای غیراصل نباید برند را در عنوان انگلیسی داشته باشد.', true );
		}
		if ( Normalizer::length( $t ) > 255 ) {
			$issues[] = $this->issue( 'title_en.length', Issue::ERROR, 'title_en', 'عنوان انگلیسی بیش از ۲۵۵ نویسه است.', true );
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
		$before = $p->title_en;
		$t      = TitleBuilder::clean_en( $p->title_en );
		if ( $p->fake && $p->brand_en && ! $p->brand_misc ) {
			$t = trim( preg_replace( '/\b' . preg_quote( $p->brand_en, '/' ) . '\b/i', '', $t ) );
		}
		if ( '' === $t || strlen( $t ) < 3 ) {
			$t = TitleBuilder::build_en( $p->nature_en, $p->brand_misc ? '' : $p->brand_en, $p->model, $p->fake );
		}
		$p->title_en = Normalizer::truncate_words( preg_replace( '/\s{2,}/', ' ', $t ), 255 );
		return $before !== $p->title_en ? array( sprintf( 'عنوان انگلیسی: «%s»', $p->title_en ) ) : array();
	}
}
