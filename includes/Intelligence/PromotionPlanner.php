<?php
/**
 * Decides whether (and how) a variant should join a promotion.
 *
 * @package DKBridge
 */

namespace DKBridge\Intelligence;

/**
 * Pure promotion planner.
 */
class PromotionPlanner {

	const NATURES = array(
		'incredible_offer' => 'پیشنهاد شگفت‌انگیز',
		'deal_of_the_day'  => 'پیشنهاد روز',
		'mega_promotion'   => 'مگا کمپین',
	);

	/**
	 * Plan a promotion entry.
	 *
	 * @param array $v      Variant {selling_price, base, commission, stock, min_price_in_promotion, max_allowable_price, already_in_same}.
	 * @param array $promo  Promotion {min_discount, max_discount}.
	 * @param array $policy {max_discount, min_profit_percent, stock_share, order_limit, desired_discount}.
	 * @return array{join:bool,reason:string,promotion_price:int,discount:float,price_limit:int,order_limit:int,net:int,floor:int}
	 */
	public static function plan( array $v, array $promo, array $policy ) {
		$v      = array_merge(
			array(
				'selling_price'          => 0,
				'base'                   => 0,
				'commission'             => 0,
				'stock'                  => 0,
				'min_price_in_promotion' => 0,
				'max_allowable_price'    => 0,
				'already_in_same'        => false,
				'fake'                   => false,
			),
			$v
		);
		$promo  = array_merge(
			array(
				'min_discount' => 0,
				'max_discount' => 0,
				'nature'       => '',
			),
			$promo
		);
		$policy = array_merge(
			array(
				'max_discount'       => 15,
				'min_profit_percent' => 0,
				'stock_share'        => 50,
				'order_limit'        => 2,
				'desired_discount'   => 0,
			),
			$policy
		);

		$out = array(
			'join'            => false,
			'reason'          => '',
			'promotion_price' => 0,
			'discount'        => 0.0,
			'price_limit'     => 0,
			'order_limit'     => max( 1, (int) $policy['order_limit'] ),
			'net'             => 0,
			'floor'           => RepricingEngine::floor_price( $v['base'], $v['commission'], $policy['min_profit_percent'] ),
		);
		$price = (int) $v['selling_price'];
		if ( $price <= 0 ) {
			$out['reason'] = 'قیمت فروش نامشخص است';
			return $out;
		}
		if ( $v['fake'] && 'incredible_offer' === $promo['nature'] ) {
			$out['reason'] = 'کالای غیراصل نمی‌تواند در پیشنهاد شگفت‌انگیز شرکت کند';
			return $out;
		}
		if ( $v['already_in_same'] ) {
			$out['reason'] = 'این تنوع در پروموشن هم‌نوع دیگری حضور دارد';
			return $out;
		}
		$stock = (int) $v['stock'];
		if ( $stock <= 0 ) {
			$out['reason'] = 'موجودی ندارد';
			return $out;
		}

		$min_discount = max( 0.0, (float) $promo['min_discount'] );
		$discount     = max( $min_discount, (float) $policy['desired_discount'] );
		if ( (float) $promo['max_discount'] > 0 ) {
			$discount = min( $discount, (float) $promo['max_discount'] );
		}
		if ( $discount > (float) $policy['max_discount'] ) {
			$out['reason'] = sprintf( 'حداقل تخفیف پروموشن (%s٪) بیشتر از سقف تخفیف شما (%s٪) است', self::num( $min_discount ), self::num( $policy['max_discount'] ) );
			return $out;
		}

		// Digikala: promotion prices must end with two zeros.
		$promo_price = (int) ( floor( $price * ( 1 - $discount / 100 ) / 100 ) * 100 );
		if ( (int) $v['min_price_in_promotion'] > 0 && $promo_price < (int) $v['min_price_in_promotion'] ) {
			$promo_price = (int) ( ceil( (int) $v['min_price_in_promotion'] / 100 ) * 100 );
			if ( $promo_price > $price * ( 1 - $min_discount / 100 ) ) {
				$out['reason'] = 'کمترین قیمت مجاز پروموشن با حداقل تخفیف سازگار نیست';
				return $out;
			}
		}
		if ( (int) $v['max_allowable_price'] > 0 && $promo_price > (int) $v['max_allowable_price'] ) {
			$promo_price = (int) ( floor( (int) $v['max_allowable_price'] / 100 ) * 100 );
		}
		if ( $promo_price < 1000 ) {
			$out['reason'] = 'قیمت پروموشن بسیار پایین است';
			return $out;
		}

		$net        = (int) round( $promo_price * ( 1 - max( 0.0, min( 0.9, (float) $v['commission'] ) ) ) );
		$out['net'] = $net;
		if ( $v['base'] > 0 && $promo_price < $out['floor'] ) {
			$out['reason'] = sprintf( 'زیان‌ده: با این تخفیف، قیمت (%s) از کف سود (%s) کمتر می‌شود', number_format( $promo_price ), number_format( $out['floor'] ) );
			$out['promotion_price'] = $promo_price;
			$out['discount']        = round( 100 * ( 1 - $promo_price / $price ), 1 );
			return $out;
		}

		$out['join']            = true;
		$out['reason']          = 'سودآور و مطابق شرایط پروموشن';
		$out['promotion_price'] = $promo_price;
		$out['discount']        = round( 100 * ( 1 - $promo_price / $price ), 1 );
		$out['price_limit']     = max( 1, (int) floor( $stock * max( 1, min( 100, (int) $policy['stock_share'] ) ) / 100 ) );
		$out['order_limit']     = min( $out['order_limit'], $out['price_limit'] );
		return $out;
	}

	/**
	 * Format number.
	 *
	 * @param float $n Number.
	 * @return string
	 */
	private static function num( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 1, '.', '' ), '0' ), '.' );
	}
}
