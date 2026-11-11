<?php
/**
 * Smart logistics: replenishment planning for Digikala warehouses (consignment),
 * order-fulfilment packages, commitments and ship-by-seller orders.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Data\VariantRepository;
use DKBridge\Intelligence\StockPlanner;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Logistics service.
 */
class LogisticsService {

	const URGENCY = array(
		'critical' => 'بحرانی',
		'high'     => 'زیاد',
		'normal'   => 'عادی',
		'none'     => '—',
	);

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var VariantRepository */
	private $variants;
	/** @var ProductMapper */
	private $mapper;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Digikala          $api      API.
	 * @param VariantRepository $variants Variants.
	 * @param ProductMapper     $mapper   Mapper.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, VariantRepository $variants, ProductMapper $mapper, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->variants = $variants;
		$this->mapper   = $mapper;
		$this->logger   = $logger;
	}

	/**
	 * Replenishment suggestions for variants sold from Digikala warehouses.
	 *
	 * @param int $max_pages Pages of 100.
	 * @return array[]
	 */
	public function replenishment_plan( $max_pages = 10 ) {
		$days   = max( 7, (int) $this->settings->get( 'velocity_days', 30 ) );
		$policy = array(
			'cover_days'   => (int) $this->settings->get( 'cover_days', 21 ),
			'safety_days'  => (int) $this->settings->get( 'safety_days', 4 ),
			'inbound_days' => (int) $this->settings->get( 'inbound_days', 3 ),
			'min_qty'      => (int) $this->settings->get( 'replenish_min', 2 ),
		);
		$rows = array();
		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$data = $this->api->consignment_variants( array(), $page );
			foreach ( isset( $data['items'] ) ? (array) $data['items'] : array() as $r ) {
				$rows[ (int) $r['product_variant_id'] ] = $r;
			}
			$pages = isset( $data['pager']['total_pages'] ) ? (int) $data['pager']['total_pages'] : 1;
			if ( $page >= $pages ) {
				break;
			}
		}
		$sales = $this->variants->sales_map( $days, array_keys( $rows ) );
		$plan  = array();
		foreach ( $rows as $id => $r ) {
			$snap      = $this->variants->get( $id );
			$available = null;
			if ( $snap && $snap['wc_product_id'] ) {
				$product = wc_get_product( $snap['wc_variation_id'] ? (int) $snap['wc_variation_id'] : (int) $snap['wc_product_id'] );
				if ( $product && $product->managing_stock() ) {
					$available = max( 0, (int) $product->get_stock_quantity() );
				}
			}
			$velocity = StockPlanner::velocity( isset( $sales[ $id ] ) ? $sales[ $id ] : array(), $days );
			$dk_stock = isset( $r['dk_inventory'] ) ? (int) $r['dk_inventory'] : 0;
			$suggest  = StockPlanner::replenish(
				array(
					'velocity'         => $velocity,
					'warehouse_stock'  => $snap ? (int) $snap['warehouse_stock'] : $dk_stock,
					'on_the_way'       => isset( $r['on_the_way_stock'] ) ? (int) $r['on_the_way_stock'] : 0,
					'allowed'          => isset( $r['allowed_count_consignment'] ) ? (int) $r['allowed_count_consignment'] : null,
					'seller_available' => $available,
				),
				$policy
			);
			$plan[] = array(
				'variant_id' => $id,
				'title'      => isset( $r['product_variant_title'] ) ? $r['product_variant_title'] : '',
				'supplier'   => isset( $r['supplier_code'] ) ? $r['supplier_code'] : '',
				'nature'     => isset( $r['shipping_nature']['title'] ) ? $r['shipping_nature']['title'] : '',
				'dk_stock'   => $dk_stock,
				'on_the_way' => isset( $r['on_the_way_stock'] ) ? (int) $r['on_the_way_stock'] : 0,
				'allowed'    => isset( $r['allowed_count_consignment'] ) ? (int) $r['allowed_count_consignment'] : null,
				'available'  => $available,
				'velocity'   => $velocity,
			) + $suggest;
		}
		$rank = array(
			'critical' => 0,
			'high'     => 1,
			'normal'   => 2,
			'none'     => 3,
		);
		usort(
			$plan,
			static function ( $a, $b ) use ( $rank ) {
				$r = $rank[ $a['urgency'] ] - $rank[ $b['urgency'] ];
				return 0 !== $r ? $r : ( $b['qty'] <=> $a['qty'] );
			}
		);
		return $plan;
	}

	/**
	 * Create a package (consignment or order fulfilment).
	 *
	 * @param string $type          consignment|order_fulfilment.
	 * @param array  $items         [{variant_id, count}].
	 * @param string $date          Delivery date (Y-m-d).
	 * @param string $delivery_type digikala|seller|mobile_hub.
	 * @return array
	 */
	public function create_package( $type, array $items, $date, $delivery_type = 'digikala' ) {
		$clean = array();
		foreach ( $items as $row ) {
			$count = (int) $row['count'];
			if ( $count > 0 && ! empty( $row['variant_id'] ) ) {
				$clean[] = array(
					'variant_id' => (string) (int) $row['variant_id'],
					'count'      => (string) $count,
				);
			}
		}
		if ( ! $clean ) {
			throw new \InvalidArgumentException( __( 'هیچ کالایی برای بسته انتخاب نشده است.', 'dk-bridge' ) );
		}
		$ts = strtotime( (string) $date . ' 23:59:59' );
		if ( ! $ts || $ts < time() ) {
			throw new \InvalidArgumentException( __( 'تاریخ تحویل باید در آینده باشد.', 'dk-bridge' ) );
		}
		$res = $this->api->create_packages(
			'order_fulfilment' === $type ? 'order_fulfilment' : 'consignment',
			array(
				'1' => array(
					'items'         => $clean,
					'shipping_date' => gmdate( 'Y-m-d\TH:i:s.000\Z', $ts ),
					'delivery_type' => in_array( $delivery_type, array( 'digikala', 'seller', 'mobile_hub' ), true ) ? $delivery_type : 'digikala',
				),
			)
		);
		$this->logger->info( 'logistics', sprintf( 'بستهٔ %s با %d قلم کالا ساخته شد.', $type, count( $clean ) ), array( 'response' => $res ) );
		return $res;
	}

	/**
	 * Order items that must be sent for commitments, grouped by shipping nature.
	 *
	 * @param int $type 1 today, 2 tomorrow, 3 all.
	 * @return array
	 */
	public function fulfilment( $type = 3 ) {
		$data   = $this->api->fulfilment_variants( $type );
		$groups = array();
		foreach ( isset( $data['items'] ) ? (array) $data['items'] : array() as $group ) {
			foreach ( (array) $group as $nature ) {
				if ( ! is_array( $nature ) || empty( $nature['variants'] ) ) {
					continue;
				}
				$groups[] = array(
					'nature'   => isset( $nature['shipping_nature']['title'] ) ? $nature['shipping_nature']['title'] : '',
					'variants' => (array) $nature['variants'],
				);
			}
		}
		return $groups;
	}

	/**
	 * Commitments overview.
	 *
	 * @return array{items:array,meta:array}
	 */
	public function commitments() {
		$items = array();
		$meta  = array();
		try {
			$data  = $this->api->commitments( array( 'is_effective' => true ) );
			$inner = isset( $data['items'] ) ? (array) $data['items'] : array();
			// The list is nested ({filters, items, meta_data}) in the published schema.
			if ( isset( $inner['items'] ) ) {
				$items = (array) $inner['items'];
			} elseif ( isset( $inner[0]['items'] ) ) {
				$items = (array) $inner[0]['items'];
			} else {
				$items = $inner;
			}
		} catch ( ApiException $e ) {
			$meta['error'] = $e->full_message();
		}
		try {
			$meta = array_merge( $meta, $this->api->commitments_meta() );
		} catch ( ApiException $e ) {
			$meta['meta_error'] = $e->full_message();
		}
		usort(
			$items,
			static function ( $a, $b ) {
				$da = isset( $a['commitment']['delayed'] ) ? (int) $a['commitment']['delayed'] : 0;
				$db = isset( $b['commitment']['delayed'] ) ? (int) $b['commitment']['delayed'] : 0;
				if ( $da !== $db ) {
					return $db - $da;
				}
				$ta = isset( $a['commitment']['today'] ) ? (int) $a['commitment']['today'] : 0;
				$tb = isset( $b['commitment']['today'] ) ? (int) $b['commitment']['today'] : 0;
				return $tb - $ta;
			}
		);
		return array(
			'items' => $items,
			'meta'  => $meta,
		);
	}

	/**
	 * Packages list.
	 *
	 * @param array $search Filters.
	 * @return array
	 */
	public function packages( array $search = array() ) {
		$data = $this->api->packages( $search );
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Ship-by-seller orders (customer data is displayed, never stored).
	 *
	 * @param array $search Filters.
	 * @return array
	 */
	public function sbs_orders( array $search = array() ) {
		$data = $this->api->sbs_orders( $search );
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}
}
