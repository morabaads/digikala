<?php
/**
 * Category, brand, originality, model, product type, division and MEFA rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;
use DKBridge\Text\Normalizer;

/**
 * Basic-info sections.
 */
class CatalogRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'catalog';
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();

		// Category.
		if ( ! $p->category_id ) {
			$issues[] = $this->issue( 'cat.unmapped', Issue::ERROR, 'category', 'گروه کالایی دیجی‌کالا برای این محصول مشخص نشده است. دستهٔ ووکامرس را در «نگاشت دسته‌ها» به یک گروه برگ دیجی‌کالا وصل کنید.' );
		} elseif ( false === $p->category_valid ) {
			$msg      = $p->category_errors ? implode( ' ', $p->category_errors ) : 'فروش در این گروه برای شما محدود است.';
			$issues[] = $this->issue( 'cat.restricted', Issue::ERROR, 'category', 'گروه کالایی مجاز نیست: ' . self::strip( $msg ) );
		}

		// Brand.
		if ( ! $p->brand_id ) {
			$issues[] = $this->issue( 'brand.unresolved', $p->has_category_data ? Issue::ERROR : Issue::WARNING, 'brand', '' !== $p->brand_source ? sprintf( 'برند «%s» در دیجی‌کالا پیدا نشد؛ برند را نگاشت کنید، «متفرقه» انتخاب کنید یا درخواست ثبت برند بدهید.', $p->brand_source ) : 'برند کالا مشخص نیست.', $p->has_category_data );
		}

		// Originality.
		if ( $p->fake && false === $p->allow_fake ) {
			$issues[] = $this->issue( 'fake.not_allowed', Issue::ERROR, 'originality', 'در این گروه کالایی، درج کالای غیراصل مجاز نیست.' );
		}
		if ( $p->fake && '' === trim( $p->brand_source ) ) {
			// Digikala guideline: an item that carries no brand at all is listed as original + «متفرقه»;
			// the fake badge is for copies of another brand (which then also use «متفرقه»).
			$issues[] = $this->issue( 'fake.no_brand', Issue::INFO, 'originality', 'اگر روی کالا هیچ برندی نیست، نشان غیراصل لازم نیست؛ با برند «متفرقه» و به‌عنوان کالای اصل درج کنید.' );
		}

		// Model.
		if ( '' === trim( $p->model ) ) {
			$issues[] = $this->issue( 'model.missing', Issue::ERROR, 'model', 'مدل کالا خالی است. اگر کالا مدل رسمی ندارد، یک کد مدل یکتا (مثلاً از SKU) بنویسید.' );
		} elseif ( Normalizer::length( $p->model ) > 60 ) {
			$issues[] = $this->issue( 'model.length', Issue::WARNING, 'model', 'مدل کالا بیش از حد طولانی است.', true );
		} elseif ( preg_match( '/[!@#$%^*=<>{}\[\]|~]/u', $p->model ) ) {
			$issues[] = $this->issue( 'model.symbols', Issue::WARNING, 'model', 'نماد غیرمجاز در مدل کالا.', true );
		}

		// Product type.
		if ( $p->available_product_types && ! $p->product_type_ids ) {
			$issues[] = $this->issue( 'type.missing', Issue::WARNING, 'type', 'نوع کالا (Product Type) انتخاب نشده است.', 1 === count( $p->available_product_types ) );
		}
		// Division.
		if ( $p->available_divisions && ! $p->division_id ) {
			$issues[] = $this->issue( 'division.missing', Issue::INFO, 'division', 'زیرگروه (Division) انتخاب نشده است.' );
		}
		// MEFA.
		if ( in_array( $p->mefa_type, array( 'general', 'exclusive' ), true ) ) {
			$value = 'exclusive' === $p->mefa_type ? $p->exclusive_mefa_id : $p->general_mefa_id;
			if ( '' === trim( (string) $value ) ) {
				$issues[] = $this->issue( 'mefa.required', Issue::ERROR, 'mefa', 'این گروه کالایی «شناسهٔ کالا» (شناسهٔ عمومی/اختصاصی سامانهٔ جامع تجارت) می‌خواهد.' );
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
		foreach ( $issues as $issue ) {
			switch ( $issue->code ) {
				case 'model.length':
					$p->model  = Normalizer::truncate_words( $p->model, 60 );
					$changes[] = 'کوتاه‌سازی مدل';
					break;
				case 'model.symbols':
					$p->model  = trim( preg_replace( '/[!@#$%^*=<>{}\[\]|~]+/u', ' ', $p->model ) );
					$changes[] = 'حذف نماد از مدل';
					break;
				case 'type.missing':
					$keys = array_keys( $p->available_product_types );
					if ( 1 === count( $keys ) ) {
						$p->product_type_ids = array( (int) $keys[0] );
						$changes[]           = 'انتخاب تنها نوع کالای موجود';
					}
					break;
				case 'brand.unresolved':
					$other = ! empty( $this->config['brand_other_id'] ) ? (int) $this->config['brand_other_id'] : (int) $p->brand_other_id;
					if ( $other ) {
						// A brand name in the title would contradict the «متفرقه» brand.
						$old = array_filter( array( $p->brand_source, $p->brand_fa, $p->brand_en ) );
						if ( $old ) {
							$p->title_fa = \DKBridge\Text\ContentSanitizer::remove_words( $p->title_fa, $old )['text'];
							$p->title_en = \DKBridge\Text\ContentSanitizer::remove_words( $p->title_en, $old )['text'];
						}
						$p->brand_id   = $other;
						$p->brand_misc = true;
						$p->brand_fa   = 'متفرقه';
						$p->brand_en   = 'Miscellaneous';
						$changes[]     = 'برند پیدا نشد؛ «متفرقه» انتخاب شد.';
					}
					break;
			}
		}
		$p->model = Normalizer::normalize( $p->model );
		return $changes;
	}

	/**
	 * Strip tags without depending on WordPress.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function strip( $text ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( strip_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}
}
