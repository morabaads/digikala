<?php
/**
 * Converts WooCommerce prices into Digikala selling prices (Rial).
 *
 * @package DKBridge
 */

namespace DKBridge\Pricing;

/**
 * Price calculator (pure).
 */
class PriceCalculator {

	/**
	 * Calculate the Digikala price.
	 *
	 * @param float|int $woo_price Price as stored in WooCommerce.
	 * @param array     $o {
	 *     currency: toman|rial, markup_percent, markup_fixed (Rial), commission (0..1),
	 *     compensate (bool), step (Rial), mode (nearest|up|down|psychological),
	 *     min (Rial|null), max (Rial|null), clamp (bool), min_profit_percent,
	 *     price_type (recommended|printed|regulated), reference_price (Rial|null)
	 * }.
	 * @return array{price:int,base:int,steps:array,warnings:string[],errors:string[]}
	 */
	public static function calculate( $woo_price, array $o = array() ) {
		$o = array_merge(
			array(
				'currency'           => 'toman',
				'markup_percent'     => 0,
				'markup_fixed'       => 0,
				'commission'         => 0,
				'compensate'         => false,
				'step'               => 10000,
				'mode'               => 'nearest',
				'min'                => null,
				'max'                => null,
				'clamp'              => true,
				'min_profit_percent' => 0,
				'price_type'         => 'recommended',
				'reference_price'    => null,
			),
			$o
		);

		$steps    = array();
		$warnings = array();
		$errors   = array();

		$base = (float) $woo_price * ( 'toman' === $o['currency'] ? 10 : 1 );
		$steps['base_rial'] = (int) round( $base );

		if ( $base <= 0 ) {
			$errors[] = 'price.missing';
			return array(
				'price'    => 0,
				'base'     => 0,
				'steps'    => $steps,
				'warnings' => $warnings,
				'errors'   => $errors,
			);
		}

		// Fixed-price products (printed / regulated) must use the reference price.
		if ( in_array( $o['price_type'], array( 'printed', 'regulated' ), true ) && $o['reference_price'] ) {
			$steps['fixed_reference'] = (int) $o['reference_price'];
			$warnings[]               = 'price.fixed_type';
			return array(
				'price'    => (int) $o['reference_price'],
				'base'     => (int) round( $base ),
				'steps'    => $steps,
				'warnings' => $warnings,
				'errors'   => $errors,
			);
		}

		$price                = $base * ( 1 + (float) $o['markup_percent'] / 100 ) + (float) $o['markup_fixed'];
		$steps['with_markup'] = (int) round( $price );

		$commission = max( 0.0, min( 0.9, (float) $o['commission'] ) );
		if ( $o['compensate'] && $commission > 0 ) {
			$price                       = $price / ( 1 - $commission );
			$steps['commission_adjusted'] = (int) round( $price );
		}

		$price            = self::round_price( $price, (int) $o['step'], (string) $o['mode'] );
		$steps['rounded'] = $price;

		$min = null !== $o['min'] ? (int) $o['min'] : null;
		$max = null !== $o['max'] ? (int) $o['max'] : null;
		if ( null !== $min && $min > 0 && $price < $min ) {
			if ( $o['clamp'] ) {
				$price      = self::round_price( $min, (int) $o['step'], 'up' );
				$warnings[] = 'price.clamped_min';
			} else {
				$errors[] = 'price.boundary';
			}
		}
		if ( null !== $max && $max > 0 && $price > $max ) {
			if ( $o['clamp'] ) {
				$price      = self::round_price( $max, (int) $o['step'], 'down' );
				$warnings[] = 'price.clamped_max';
			} else {
				$errors[] = 'price.boundary';
			}
		}
		$steps['final'] = $price;

		// Profitability check: what the seller receives after commission.
		$net    = $price * ( 1 - $commission );
		$target = $base * ( 1 + (float) $o['min_profit_percent'] / 100 );
		if ( $commission > 0 && $net + 1 < $target ) {
			$warnings[] = 'price.below_cost';
		}
		$steps['net_after_commission'] = (int) round( $net );

		return array(
			'price'    => (int) $price,
			'base'     => (int) round( $base ),
			'steps'    => $steps,
			'warnings' => $warnings,
			'errors'   => $errors,
		);
	}

	/**
	 * Round a price.
	 *
	 * @param float  $price Price (Rial).
	 * @param int    $step  Step (Rial).
	 * @param string $mode  nearest|up|down|psychological.
	 * @return int
	 */
	public static function round_price( $price, $step, $mode = 'nearest' ) {
		$step = max( 1, (int) $step );
		switch ( $mode ) {
			case 'up':
				return (int) ( ceil( $price / $step ) * $step );
			case 'down':
				return (int) max( $step, floor( $price / $step ) * $step );
			case 'psychological':
				// Round up to the step, then step back by a tenth of it: 12,400,000 → 12,390,000.
				$up  = ceil( $price / $step ) * $step;
				$cut = max( 1, (int) round( $step / 10 ) );
				return (int) max( $cut, $up - $cut );
			case 'nearest':
			default:
				return (int) max( $step, round( $price / $step ) * $step );
		}
	}

	/**
	 * Convert a Rial amount back to the shop currency for display.
	 *
	 * @param int    $rial     Rial.
	 * @param string $currency toman|rial.
	 * @return float
	 */
	public static function to_shop_currency( $rial, $currency ) {
		return 'toman' === $currency ? $rial / 10 : (float) $rial;
	}
}
