<?php
/**
 * Digikala logistics rules encoded from the official seller FAQ.
 *
 * Sources (digikala.com/faq/question/{id}): 331 (shipping window), 365 (depot cap),
 * 510 (expiry dates), 319 (package discrepancy penalty), 215/298 (warehouses).
 *
 * @package DKBridge
 */

namespace DKBridge\Intelligence;

/**
 * Pure helpers.
 */
class LogisticsRules {

	/** Minimum normal (non-promotion) sales in the last 30 days to be allowed to depot (FAQ 365). */
	const DEPOT_MIN_SALES = 50;

	/** Depot allowance = 20% of the last 30 days' normal sales (FAQ 365). */
	const DEPOT_SHARE = 0.2;

	/** Penalty for every item missing from a package, in Toman (FAQ 319). */
	const DISCREPANCY_PENALTY_TOMAN = 9000;

	/** Orders placed at or after this hour do not count the current day (FAQ 331). */
	const CUTOFF_HOUR = 8;

	/**
	 * Warehouses by product size (FAQ 215, 298).
	 */
	const WAREHOUSES = array(
		'small' => array( 'دانش', 'فتح', 'اتحاد', 'شادآباد' ),
		'large' => array( 'بادامک', 'اتحاد', 'دانش (ساختمان کالای بزرگ)' ),
	);

	/**
	 * Depot (consignment) allowance.
	 *
	 * @param int $normal_sales_30d Sales in the last 30 days excluding promotions.
	 * @return array{eligible:bool,max:int,reason:string}
	 */
	public static function depot_allowance( $normal_sales_30d ) {
		$sales = max( 0, (int) $normal_sales_30d );
		if ( $sales < self::DEPOT_MIN_SALES ) {
			return array(
				'eligible' => false,
				'max'      => 0,
				'reason'   => sprintf( 'برای انبارش، حداقل %d فروش عادی (بدون پروموشن) در ۳۰ روز اخیر لازم است؛ فروش فعلی %d.', self::DEPOT_MIN_SALES, $sales ),
			);
		}
		$max = (int) floor( $sales * self::DEPOT_SHARE );
		return array(
			'eligible' => true,
			'max'      => $max,
			'reason'   => sprintf( 'سقف انبارش = ۲۰٪ فروش ۳۰ روز اخیر = %d عدد.', $max ),
		);
	}

	/**
	 * Shelf-life check at warehouse delivery (FAQ 510): at least two thirds of the life
	 * must remain; with only an expiry date, at least 6 months.
	 *
	 * @param string|null $production Production date (Y-m-d) or null.
	 * @param string      $expiry     Expiry date (Y-m-d).
	 * @param string      $delivery   Planned warehouse delivery date (Y-m-d).
	 * @return array{ok:bool,reason:string,last_delivery:string}
	 */
	public static function shelf_life( $production, $expiry, $delivery ) {
		$exp = strtotime( (string) $expiry . ' 00:00:00 UTC' );
		$del = strtotime( (string) $delivery . ' 00:00:00 UTC' );
		if ( ! $exp || ! $del ) {
			return array(
				'ok'            => false,
				'reason'        => 'تاریخ انقضا یا تاریخ تحویل نامعتبر است.',
				'last_delivery' => '',
			);
		}
		$prod = $production ? strtotime( (string) $production . ' 00:00:00 UTC' ) : false;
		if ( $prod && $prod < $exp ) {
			$last = $prod + (int) floor( ( $exp - $prod ) / 3 );
			$ok   = $del <= $last;
			return array(
				'ok'            => $ok,
				'reason'        => $ok ? 'بیش از دو سوم عمر کالا باقی است.' : 'هنگام تحویل به انبار باید دست‌کم دو سوم عمر کالا باقی مانده باشد.',
				'last_delivery' => gmdate( 'Y-m-d', $last ),
			);
		}
		$last = strtotime( '-6 months', $exp );
		$ok   = $del <= $last;
		return array(
			'ok'            => $ok,
			'reason'        => $ok ? 'بیش از ۶ ماه تا انقضا باقی است.' : 'کالایی که فقط تاریخ انقضا دارد باید هنگام تحویل دست‌کم ۶ ماه اعتبار داشته باشد.',
			'last_delivery' => gmdate( 'Y-m-d', $last ),
		);
	}

	/**
	 * Latest date to deliver an order to the warehouse (FAQ 331).
	 *
	 * Orders at/after 08:00 do not count the day of the order; orders before 08:00 do.
	 * Fridays and official holidays are not counted; an order on a holiday is treated as
	 * placed before 08:00 on the next working day.
	 *
	 * @param int      $order_ts  Order time (unix, will be read in Asia/Tehran).
	 * @param int      $lead_days Shipping window (days).
	 * @param string[] $holidays  Extra holidays (Y-m-d).
	 * @return string Deadline (Y-m-d).
	 */
	public static function shipping_deadline( $order_ts, $lead_days, array $holidays = array() ) {
		$tz       = new \DateTimeZone( 'Asia/Tehran' );
		$date     = ( new \DateTimeImmutable( '@' . (int) $order_ts ) )->setTimezone( $tz );
		$is_off   = static function ( \DateTimeImmutable $d ) use ( $holidays ) {
			return '5' === $d->format( 'w' ) || in_array( $d->format( 'Y-m-d' ), $holidays, true );
		};
		$counts   = (int) $date->format( 'G' ) < self::CUTOFF_HOUR;
		if ( $is_off( $date ) ) {
			do {
				$date = $date->modify( '+1 day' );
			} while ( $is_off( $date ) );
			$counts = true;
		}
		$remaining = max( 1, (int) $lead_days );
		$day       = $date;
		if ( $counts ) {
			--$remaining;
		}
		while ( $remaining > 0 ) {
			$day = $day->modify( '+1 day' );
			if ( ! $is_off( $day ) ) {
				--$remaining;
			}
		}
		return $day->format( 'Y-m-d' );
	}

	/**
	 * Estimated penalty for missing items in a package (Rial).
	 *
	 * @param int $missing Items declared but not delivered.
	 * @return int
	 */
	public static function discrepancy_penalty( $missing ) {
		return max( 0, (int) $missing ) * self::DISCREPANCY_PENALTY_TOMAN * 10;
	}
}
