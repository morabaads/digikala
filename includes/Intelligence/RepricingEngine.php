<?php
/**
 * Buy-box aware repricing decisions.
 *
 * @package DKBridge
 */

namespace DKBridge\Intelligence;

use DKBridge\Pricing\PriceCalculator;

/**
 * Pure decision logic: given the current market state of one variant and a
 * pricing rule, decide the new selling price — never below the profit floor.
 */
class RepricingEngine {

	const STRATEGIES = array(
		'fixed'    => 'ثابت (بر اساس قیمت سایت)',
		'buybox'   => 'برنده شدن بای‌باکس',
		'rank'     => 'رتبهٔ مشخص در بای‌باکس',
		'undercut' => 'کمی ارزان‌تر از برندهٔ بای‌باکس',
	);

	/**
	 * Profit floor: the lowest price where the seller still keeps base × (1 + min profit) after commission.
	 *
	 * @param int   $base               Base price (Rial) from WooCommerce, before markup.
	 * @param float $commission         Commission ratio (0..1).
	 * @param float $min_profit_percent Required net profit percent.
	 * @return int
	 */
	public static function floor_price( $base, $commission, $min_profit_percent ) {
		$commission = max( 0.0, min( 0.9, (float) $commission ) );
		return (int) ceil( (float) $base * ( 1 + (float) $min_profit_percent / 100 ) / ( 1 - $commission ) );
	}

	/**
	 * Decide the price.
	 *
	 * @param array $s State {current, base, fixed_price, commission, buy_box_price, is_winner, in_challenge,
	 *                       suggested, rank_price, min_allowed, max_allowed}.
	 * @param array $r Rule {strategy, step, min_change, min_profit_percent, max_markup_percent, raise_when_alone,
	 *                       floor, ceiling, rounding_step}.
	 * @return array{action:string,price:int,reason:string,floor:int,ceiling:int,target:int}
	 */
	public static function decide( array $s, array $r ) {
		$s = array_merge(
			array(
				'current'       => 0,
				'base'          => 0,
				'fixed_price'   => 0,
				'commission'    => 0,
				'buy_box_price' => null,
				'is_winner'     => false,
				'in_challenge'  => false,
				'suggested'     => null,
				'rank_price'    => null,
				'min_allowed'   => null,
				'max_allowed'   => null,
				'week_ref'      => 0,
			),
			$s
		);
		$r = array_merge(
			array(
				'strategy'           => 'fixed',
				'step'               => 10000,
				'min_change'         => 10000,
				'min_profit_percent' => 0,
				'max_markup_percent' => 40,
				'raise_when_alone'   => true,
				'floor'              => 0,
				'ceiling'            => 0,
				'rounding_step'      => 1000,
				'max_weekly_change'  => 0,
			),
			$r
		);

		$current = (int) $s['current'];
		$floor   = $r['floor'] > 0 ? (int) $r['floor'] : self::floor_price( $s['base'], $s['commission'], $r['min_profit_percent'] );
		$ceiling = $r['ceiling'] > 0 ? (int) $r['ceiling'] : (int) floor( max( $s['base'], $s['fixed_price'] ) * ( 1 + (float) $r['max_markup_percent'] / 100 ) );
		if ( null !== $s['min_allowed'] && $s['min_allowed'] > 0 ) {
			$floor = max( $floor, (int) $s['min_allowed'] );
		}
		if ( null !== $s['max_allowed'] && $s['max_allowed'] > 0 ) {
			$ceiling = min( $ceiling, (int) $s['max_allowed'] );
		}
		if ( $ceiling < $floor ) {
			$ceiling = $floor;
		}
		// Price stability guard: large swings within 7 days block incredible offers and a rise
		// before a discount counts as a "false discount" (Digikala seller FAQ 590, 692).
		$stability = '';
		if ( (float) $r['max_weekly_change'] > 0 && (int) $s['week_ref'] > 0 ) {
			$band_lo = (int) ceil( $s['week_ref'] * ( 1 - (float) $r['max_weekly_change'] / 100 ) );
			$band_hi = (int) floor( $s['week_ref'] * ( 1 + (float) $r['max_weekly_change'] / 100 ) );
			if ( $band_hi < $ceiling ) {
				$ceiling   = max( $floor, $band_hi );
				$stability = 'hi';
			}
			if ( $band_lo > $floor && $band_lo <= $ceiling ) {
				$floor = $band_lo;
			}
		}

		$step   = max( 1, (int) $r['step'] );
		$bb     = null !== $s['buy_box_price'] ? (int) $s['buy_box_price'] : 0;
		$reason = '';
		switch ( $r['strategy'] ) {
			case 'buybox':
				if ( $s['suggested'] ) {
					$target = (int) $s['suggested'];
					$reason = 'قیمت پیشنهادی دیجی‌کالا برای برنده شدن بای‌باکس';
				} elseif ( ! $s['in_challenge'] && $r['raise_when_alone'] ) {
					$target = $ceiling;
					$reason = 'رقیبی نیست؛ قیمت تا سقف افزایش یافت';
				} elseif ( $s['is_winner'] ) {
					$target = $current;
					$reason = 'برندهٔ بای‌باکس هستید';
				} elseif ( $bb > 0 ) {
					$target = $bb - $step;
					$reason = 'یک گام ارزان‌تر از برندهٔ بای‌باکس';
				} else {
					$target = $current;
					$reason = 'اطلاعات بای‌باکس در دسترس نیست';
				}
				break;
			case 'rank':
				if ( $s['rank_price'] ) {
					$target = (int) $s['rank_price'];
					$reason = 'قیمت پیشنهادی دیجی‌کالا برای رتبهٔ هدف';
				} else {
					$target = $current;
					$reason = 'قیمت رتبه در دسترس نیست';
				}
				break;
			case 'undercut':
				if ( $s['is_winner'] && $s['in_challenge'] ) {
					$target = $current;
					$reason = 'برندهٔ بای‌باکس هستید';
				} elseif ( ! $s['in_challenge'] && $r['raise_when_alone'] ) {
					$target = $ceiling;
					$reason = 'رقیبی نیست؛ قیمت تا سقف افزایش یافت';
				} elseif ( $bb > 0 ) {
					$target = $bb - $step;
					$reason = 'یک گام ارزان‌تر از برندهٔ بای‌باکس';
				} else {
					$target = $current;
					$reason = 'اطلاعات بای‌باکس در دسترس نیست';
				}
				break;
			default:
				$target = (int) $s['fixed_price'];
				$reason = 'قیمت سایت با قوانین قیمت‌گذاری';
		}
		if ( $target <= 0 ) {
			$target = $current > 0 ? $current : (int) $s['fixed_price'];
		}

		$raw = $target;
		if ( $target < $floor ) {
			$target  = $floor;
			$reason .= ' — محدود به کف سود';
		} elseif ( $target > $ceiling ) {
			$target  = $ceiling;
			$reason .= 'hi' === $stability ? ' — محدود به سقف نوسان هفتگی' : ' — محدود به سقف قیمت';
		}
		if ( 'hi' === $stability && $target >= $ceiling && false === mb_strpos( $reason, 'نوسان' ) ) {
			$reason .= ' — محدود به سقف نوسان هفتگی';
		}
		// Round without crossing the floor (up) or the competitor target (down).
		$rs     = max( 1, (int) $r['rounding_step'] );
		$target = $target === $floor ? PriceCalculator::round_price( $target, $rs, 'up' ) : PriceCalculator::round_price( $target, $rs, 'down' );
		if ( $target < $floor ) {
			$target = PriceCalculator::round_price( $floor, $rs, 'up' );
		}
		if ( $target > $ceiling && PriceCalculator::round_price( $ceiling, $rs, 'down' ) >= $floor ) {
			$target = PriceCalculator::round_price( $ceiling, $rs, 'down' );
		}

		$action = ( $current > 0 && abs( $target - $current ) < (int) $r['min_change'] ) ? 'keep' : 'update';
		if ( $target === $current ) {
			$action = 'keep';
		}
		return array(
			'action'  => $action,
			'price'   => (int) $target,
			'reason'  => $reason,
			'floor'   => (int) $floor,
			'ceiling' => (int) $ceiling,
			'target'  => (int) $raw,
		);
	}
}
