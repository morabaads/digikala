<?php
/**
 * Sales velocity, stock health and replenishment suggestions.
 *
 * @package DKBridge
 */

namespace DKBridge\Intelligence;

/**
 * Pure inventory/logistics math.
 */
class StockPlanner {

	/**
	 * Units sold per day, blending the whole window with the last 7 days so trends show up quickly.
	 *
	 * @param array $sales [date (Y-m-d) => qty].
	 * @param int   $days  Window length.
	 * @param int   $now   Timestamp.
	 * @return float
	 */
	public static function velocity( array $sales, $days = 30, $now = 0 ) {
		$now   = $now ? $now : time();
		$days  = max( 1, (int) $days );
		$total = 0;
		$week  = 0;
		foreach ( $sales as $date => $qty ) {
			$ts = strtotime( $date . ' 12:00:00 UTC' );
			if ( false === $ts ) {
				continue;
			}
			$age = ( $now - $ts ) / 86400;
			if ( $age < 0 || $age > $days ) {
				continue;
			}
			$total += (int) $qty;
			if ( $age <= 7 ) {
				$week += (int) $qty;
			}
		}
		$avg    = $total / $days;
		$recent = $week / min( 7, $days );
		return round( 0.5 * $avg + 0.5 * $recent, 3 );
	}

	/**
	 * Days the given stock lasts.
	 *
	 * @param int   $stock    Stock.
	 * @param float $velocity Units/day.
	 * @return float|null Null when nothing sells.
	 */
	public static function days_of_cover( $stock, $velocity ) {
		if ( $velocity <= 0 ) {
			return null;
		}
		return round( max( 0, (int) $stock ) / $velocity, 1 );
	}

	/**
	 * Health class.
	 *
	 * @param int   $stock    Stock.
	 * @param float $velocity Velocity.
	 * @param int   $low_days Low-stock threshold in days of cover.
	 * @return string out|low|ok|overstock|no_sales
	 */
	public static function health( $stock, $velocity, $low_days = 7 ) {
		if ( (int) $stock <= 0 ) {
			return 'out';
		}
		$cover = self::days_of_cover( $stock, $velocity );
		if ( null === $cover ) {
			return 'no_sales';
		}
		if ( $cover < $low_days ) {
			return 'low';
		}
		if ( $cover > 120 ) {
			return 'overstock';
		}
		return 'ok';
	}

	/**
	 * Suggest a consignment quantity to send to a Digikala warehouse.
	 *
	 * @param array $v      {velocity, warehouse_stock, on_the_way, allowed, seller_available}.
	 * @param array $policy {cover_days, safety_days, inbound_days, min_qty}.
	 * @return array{qty:int,need:int,cover:float|null,urgency:string,reason:string}
	 */
	public static function replenish( array $v, array $policy ) {
		$v      = array_merge(
			array(
				'velocity'         => 0.0,
				'warehouse_stock'  => 0,
				'on_the_way'       => 0,
				'allowed'          => null,
				'seller_available' => null,
			),
			$v
		);
		$policy = array_merge(
			array(
				'cover_days'   => 21,
				'safety_days'  => 4,
				'inbound_days' => 3,
				'min_qty'      => 2,
			),
			$policy
		);
		$pipeline = (int) $v['warehouse_stock'] + (int) $v['on_the_way'];
		$cover    = self::days_of_cover( $pipeline, (float) $v['velocity'] );
		$horizon  = (int) $policy['cover_days'] + (int) $policy['safety_days'] + (int) $policy['inbound_days'];
		$need     = (int) max( 0, ceil( (float) $v['velocity'] * $horizon ) - $pipeline );
		$qty      = $need;
		$reason   = '';
		if ( null !== $v['allowed'] && $qty > (int) $v['allowed'] ) {
			$qty    = max( 0, (int) $v['allowed'] );
			$reason = 'محدود به سقف مجاز ارسال دیجی‌کالا';
		}
		if ( null !== $v['seller_available'] && $qty > (int) $v['seller_available'] ) {
			$qty    = max( 0, (int) $v['seller_available'] );
			$reason = 'محدود به موجودی انبار شما';
		}
		if ( $qty < (int) $policy['min_qty'] ) {
			$qty = 0;
		}
		if ( (float) $v['velocity'] <= 0 ) {
			$urgency = 'none';
			$reason  = 'فروشی در دورهٔ اخیر نداشته';
		} elseif ( null !== $cover && $cover <= (int) $policy['inbound_days'] ) {
			$urgency = 'critical';
		} elseif ( null !== $cover && $cover < (int) $policy['inbound_days'] + (int) $policy['safety_days'] ) {
			$urgency = 'high';
		} elseif ( $qty > 0 ) {
			$urgency = 'normal';
		} else {
			$urgency = 'none';
		}
		if ( '' === $reason && $qty > 0 ) {
			$reason = sprintf( 'پوشش %d روز فروش', $horizon );
		}
		return array(
			'qty'     => (int) $qty,
			'need'    => $need,
			'cover'   => $cover,
			'urgency' => $urgency,
			'reason'  => $reason,
		);
	}
}
