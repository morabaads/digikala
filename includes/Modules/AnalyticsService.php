<?php
/**
 * Performance analytics: Digikala insight, seller KPIs, missed revenue,
 * channel comparison with WooCommerce.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Data\VariantRepository;
use DKBridge\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Analytics service.
 */
class AnalyticsService {

	const RANGES = array(
		'last_7_days'  => '۷ روز اخیر',
		'last_14_days' => '۱۴ روز اخیر',
		'last_30_days' => '۳۰ روز اخیر',
	);

	/**
	 * KPI thresholds for seller performance (percent).
	 */
	const PERFORMANCE_TARGETS = array(
		'delivered_on_time_percentage' => array( 'تحویل به‌موقع', 95 ),
		'not_canceled_percentage'      => array( 'بدون لغو', 97 ),
		'not_returned_percentage'      => array( 'بدون مرجوعی', 98 ),
	);

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var VariantRepository */
	private $variants;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Digikala          $api      API.
	 * @param VariantRepository $variants Variants.
	 */
	public function __construct( Settings $settings, Digikala $api, VariantRepository $variants ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->variants = $variants;
	}

	/**
	 * Run a callable and capture API errors.
	 *
	 * @param callable $fn Fn.
	 * @return array{data:mixed,error:string}
	 */
	private static function safe( $fn ) {
		try {
			return array(
				'data'  => $fn(),
				'error' => '',
			);
		} catch ( ApiException $e ) {
			return array(
				'data'  => null,
				'error' => $e->full_message(),
			);
		}
	}

	/**
	 * Everything for the analytics screen.
	 *
	 * @param string $range Range.
	 * @return array
	 */
	public function report( $range = 'last_30_days' ) {
		$range = isset( self::RANGES[ $range ] ) ? $range : 'last_30_days';
		$api   = $this->api;
		$days  = (int) preg_replace( '/\D/', '', $range );

		$overview = self::safe(
			static function () use ( $api, $range ) {
				$d = $api->insight_overview( $range );
				return isset( $d['items'][0] ) ? $d['items'][0] : ( isset( $d['items'] ) ? $d['items'] : array() );
			}
		);
		$sales    = self::safe(
			static function () use ( $api, $range ) {
				$d     = $api->insight_sales( $range );
				$items = isset( $d['items'] ) ? (array) $d['items'] : array();
				usort(
					$items,
					static function ( $a, $b ) {
						return (int) $b['net_sales_amount'] <=> (int) $a['net_sales_amount'];
					}
				);
				return array_slice( $items, 0, 15 );
			}
		);
		$missed   = self::safe(
			static function () use ( $api ) {
				$d = $api->insight_top_deactivated();
				return isset( $d['items'] ) ? array_slice( (array) $d['items'], 0, 10 ) : array();
			}
		);
		$perf     = self::safe(
			static function () use ( $api ) {
				return $api->performance();
			}
		);
		$comm     = self::safe(
			static function () use ( $api ) {
				$d = $api->commissions();
				return isset( $d['items'] ) ? array_slice( (array) $d['items'], 0, 30 ) : array();
			}
		);

		return array(
			'range'       => $range,
			'overview'    => $overview,
			'kpis'        => is_array( $overview['data'] ) ? self::kpis( $overview['data'] ) : array(),
			'top'         => $sales,
			'missed'      => $missed,
			'performance' => $perf,
			'alerts'      => is_array( $perf['data'] ) ? self::performance_alerts( $perf['data'] ) : array(),
			'commissions' => $comm,
			'channels'    => $this->channels( $days ),
			'catalog'     => $this->variants->summary(),
		);
	}

	/**
	 * KPI cards from an insight overview item.
	 *
	 * @param array $o Overview item {sum, old_sum, progress, charts}.
	 * @return array[]
	 */
	public static function kpis( array $o ) {
		$labels = array(
			'gross_sales_amount' => 'فروش ناخالص (ریال)',
			'gross_item_sold'    => 'تعداد فروش',
			'visit_count'        => 'بازدید',
			'conversion_rate'    => 'نرخ تبدیل',
			'count_live_variants' => 'تنوع‌های فعال',
			'count_deactive_variants' => 'تنوع‌های ناموجود',
		);
		$out = array();
		foreach ( $labels as $key => $label ) {
			$now  = isset( $o['sum'][ $key ] ) ? $o['sum'][ $key ] : null;
			$prev = isset( $o['old_sum'][ $key ] ) ? $o['old_sum'][ $key ] : null;
			if ( null === $now ) {
				continue;
			}
			$change = null;
			if ( isset( $o['progress'][ $key ] ) && is_numeric( $o['progress'][ $key ] ) ) {
				$change = (float) $o['progress'][ $key ];
			} elseif ( null !== $prev && (float) $prev > 0 ) {
				$change = round( 100 * ( (float) $now - (float) $prev ) / (float) $prev, 1 );
			}
			$series = array();
			foreach ( isset( $o['charts'] ) ? (array) $o['charts'] : array() as $point ) {
				if ( isset( $point[ $key ] ) ) {
					$series[ substr( (string) $point['date'], 0, 10 ) ] = (float) $point[ $key ];
				}
			}
			ksort( $series );
			$out[] = array(
				'key'    => $key,
				'label'  => $label,
				'value'  => $now,
				'prev'   => $prev,
				'change' => $change,
				// More out-of-stock variants is bad.
				'good'   => null === $change ? null : ( 'count_deactive_variants' === $key ? $change <= 0 : $change >= 0 ),
				'series' => $series,
			);
		}
		return $out;
	}

	/**
	 * Alerts for weak seller KPIs.
	 *
	 * @param array $p Performance data.
	 * @return string[]
	 */
	public static function performance_alerts( array $p ) {
		$alerts = array();
		foreach ( self::PERFORMANCE_TARGETS as $key => $meta ) {
			if ( isset( $p['summary'][ $key ] ) && (float) $p['summary'][ $key ] < $meta[1] ) {
				$alerts[] = sprintf( '%s %s٪ است (هدف: حداقل %d٪).', $meta[0], round( (float) $p['summary'][ $key ], 1 ), $meta[1] );
			}
		}
		if ( isset( $p['customer_statisfaction']['rate'] ) && (float) $p['customer_statisfaction']['rate'] < 80 ) {
			$alerts[] = sprintf( 'رضایت مشتری %s٪ است؛ کیفیت بسته‌بندی و تطابق کالا با توضیحات را بررسی کنید.', $p['customer_statisfaction']['rate'] );
		}
		return $alerts;
	}

	/**
	 * Daily revenue per channel: Digikala (imported orders) vs WooCommerce.
	 *
	 * @param int $days Days.
	 * @return array{dates:string[],digikala:int[],woocommerce:int[],totals:array}
	 */
	public function channels( $days = 30 ) {
		$days  = max( 7, min( 90, (int) $days ) );
		$dates = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$dates[] = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		}
		$dk  = $this->variants->daily_revenue( $days );
		$woo = array_fill_keys( $dates, 0 );
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders(
				array(
					'status'       => array( 'wc-processing', 'wc-completed', 'wc-on-hold' ),
					'date_created' => '>' . ( time() - $days * DAY_IN_SECONDS ),
					'limit'        => 3000,
					'return'       => 'objects',
				)
			);
			$factor = 'toman' === $this->settings->currency() ? 10 : 1;
			foreach ( $orders as $order ) {
				$created = $order->get_date_created();
				if ( ! $created ) {
					continue;
				}
				$d = gmdate( 'Y-m-d', $created->getTimestamp() );
				if ( isset( $woo[ $d ] ) ) {
					$woo[ $d ] += (int) round( (float) $order->get_total() * $factor );
				}
			}
		}
		$dk_series  = array();
		$woo_series = array();
		foreach ( $dates as $d ) {
			$dk_series[]  = isset( $dk[ $d ] ) ? (int) $dk[ $d ]['revenue'] : 0;
			$woo_series[] = (int) $woo[ $d ];
		}
		return array(
			'dates'       => $dates,
			'digikala'    => $dk_series,
			'woocommerce' => $woo_series,
			'totals'      => array(
				'digikala'    => array_sum( $dk_series ),
				'woocommerce' => array_sum( $woo_series ),
			),
		);
	}
}
