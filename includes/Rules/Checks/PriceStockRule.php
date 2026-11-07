<?php
/**
 * Price & stock rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;

/**
 * Commercial checks (not part of the content score).
 */
class PriceStockRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'commerce';
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();
		$rows   = $p->variations ? $p->variations : array(
			array(
				'price' => $p->price,
				'stock' => $p->stock,
				'label' => '',
			),
		);
		foreach ( $rows as $row ) {
			$label = ! empty( $row['label'] ) ? ' (' . $row['label'] . ')' : '';
			if ( empty( $row['price'] ) || (int) $row['price'] <= 0 ) {
				$issues[] = $this->issue( 'price.missing', Issue::ERROR, 'commerce', 'قیمت فروش مشخص نیست' . $label . '.' );
			}
			if ( isset( $row['stock'] ) && (int) $row['stock'] < 0 ) {
				$issues[] = $this->issue( 'stock.negative', Issue::WARNING, 'commerce', 'موجودی منفی است و صفر ارسال می‌شود' . $label . '.' );
			}
			if ( ! empty( $row['price_warnings'] ) && in_array( 'price.below_cost', (array) $row['price_warnings'], true ) ) {
				$issues[] = $this->issue( 'price.below_cost', Issue::WARNING, 'commerce', 'با کسر کمیسیون، سود شما از حداقل تعیین‌شده کمتر می‌شود' . $label . '.' );
			}
		}
		if ( ! empty( $p->price_info['warnings'] ) && in_array( 'price.below_cost', (array) $p->price_info['warnings'], true ) && ! $p->variations ) {
			$issues[] = $this->issue( 'price.below_cost', Issue::WARNING, 'commerce', 'با کسر کمیسیون، سود شما از حداقل تعیین‌شده کمتر می‌شود.' );
		}
		if ( 'variable' === $p->wc_type && ! $p->variations ) {
			$issues[] = $this->issue( 'variants.none', Issue::ERROR, 'commerce', 'محصول متغیر هیچ تنوع فعالی ندارد.' );
		}
		if ( 'variable' === $p->wc_type && 'no_color_no_size' === $p->category_theme && count( $p->variations ) > 1 ) {
			$issues[] = $this->issue( 'variants.theme', Issue::WARNING, 'commerce', 'این گروه کالایی تنوع رنگ/سایز ندارد؛ فقط اولین تنوع ارسال می‌شود. برای مدل‌های متفاوت، کالاهای جداگانه بسازید.' );
		}
		return $issues;
	}
}
