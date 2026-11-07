<?php
/**
 * Dimension & weight rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;

/**
 * Package dimensions are mandatory and must fall inside the category's dimension_config.
 */
class DimensionRule extends Rule {

	const LABELS = array(
		'length' => 'طول',
		'width'  => 'عرض',
		'height' => 'ارتفاع',
		'weight' => 'وزن',
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'dimensions';
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues  = array();
		$missing = array();
		foreach ( self::LABELS as $key => $label ) {
			$value = isset( $p->package[ $key ] ) ? (float) $p->package[ $key ] : 0.0;
			if ( $value <= 0 ) {
				$missing[] = $label;
				continue;
			}
			if ( isset( $p->dimension_config[ $key ] ) ) {
				$range = $p->dimension_config[ $key ];
				$min   = isset( $range['min'] ) ? (float) $range['min'] : null;
				$max   = isset( $range['max'] ) ? (float) $range['max'] : null;
				$unit  = 'weight' === $key ? 'گرم' : 'سانتی‌متر';
				if ( ( null !== $min && $value < $min ) || ( null !== $max && $max > 0 && $value > $max ) ) {
					$issues[] = $this->issue(
						'dim.range',
						Issue::ERROR,
						'dimensions',
						sprintf( '%s بسته‌بندی (%s %s) خارج از بازهٔ مجاز این گروه (%s تا %s) است. واحد اندازه‌گیری ووکامرس را بررسی کنید.', $label, self::num( $value ), $unit, self::num( $min ), self::num( $max ) ),
						true,
						array( 'field' => $key )
					);
				}
			}
		}
		if ( $missing ) {
			$issues[] = $this->issue( 'dim.missing', Issue::ERROR, 'dimensions', 'ابعاد/وزن بسته‌بندی وارد نشده: ' . implode( '، ', $missing ) . '. این اطلاعات برای محاسبهٔ هزینهٔ ارسال الزامی است.' );
		}
		return $issues;
	}

	/**
	 * Clamp values (only when within 10× of the boundary, i.e. likely rounding errors).
	 *
	 * @param ProductData $p      Product.
	 * @param Issue[]     $issues Issues.
	 * @return string[]
	 */
	public function fix( ProductData $p, array $issues ) {
		$changes = array();
		foreach ( $issues as $issue ) {
			if ( 'dim.range' !== $issue->code ) {
				continue;
			}
			$key   = $issue->data['field'];
			$range = $p->dimension_config[ $key ];
			$value = (float) $p->package[ $key ];
			$min   = isset( $range['min'] ) ? (float) $range['min'] : 0;
			$max   = isset( $range['max'] ) ? (float) $range['max'] : 0;
			if ( $min > 0 && $value < $min && $value * 10 >= $min ) {
				$p->package[ $key ] = $min;
				$changes[]          = sprintf( '%s بسته‌بندی به حداقل مجاز (%s) رسید.', self::LABELS[ $key ], self::num( $min ) );
			} elseif ( $max > 0 && $value > $max && $value <= $max * 1.1 ) {
				$p->package[ $key ] = $max;
				$changes[]          = sprintf( '%s بسته‌بندی به حداکثر مجاز (%s) رسید.', self::LABELS[ $key ], self::num( $max ) );
			}
		}
		return $changes;
	}

	/**
	 * Format number.
	 *
	 * @param float|null $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		if ( null === $n ) {
			return '—';
		}
		return rtrim( rtrim( number_format( (float) $n, 2, '.', '' ), '0' ), '.' );
	}
}
