<?php
/**
 * Inventory management: stock health across WooCommerce and Digikala,
 * discrepancies, sales velocity, low-stock alerts.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Data\ItemRepository;
use DKBridge\Data\VariantRepository;
use DKBridge\Intelligence\StockPlanner;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Inventory service.
 */
class InventoryService {

	const HEALTH = array(
		'out'       => 'ناموجود',
		'low'       => 'رو به اتمام',
		'ok'        => 'مناسب',
		'overstock' => 'مازاد',
		'no_sales'  => 'بدون فروش',
	);

	/** @var Settings */
	private $settings;
	/** @var VariantRepository */
	private $variants;
	/** @var ItemRepository */
	private $items;
	/** @var ProductMapper */
	private $mapper;
	/** @var CatalogService */
	private $catalog;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param VariantRepository $variants Variants.
	 * @param ItemRepository    $items    Items.
	 * @param ProductMapper     $mapper   Mapper.
	 * @param CatalogService    $catalog  Catalog.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( Settings $settings, VariantRepository $variants, ItemRepository $items, ProductMapper $mapper, CatalogService $catalog, Logger $logger ) {
		$this->settings = $settings;
		$this->variants = $variants;
		$this->items    = $items;
		$this->mapper   = $mapper;
		$this->catalog  = $catalog;
		$this->logger   = $logger;
	}

	/**
	 * Rows for the inventory screen.
	 *
	 * @param array $filters Filters: health, search, discrepancy(bool).
	 * @param int   $page    Page.
	 * @return array{rows:array,total:int,summary:array}
	 */
	public function overview( array $filters = array(), $page = 1 ) {
		$days     = max( 7, (int) $this->settings->get( 'velocity_days', 30 ) );
		$low_days = (int) $this->settings->get( 'low_stock_days', 7 );
		$all      = $this->variants->search(
			array(
				'archived' => 0,
				'search'   => isset( $filters['search'] ) ? $filters['search'] : '',
			),
			1,
			5000
		);
		$sales    = $this->variants->sales_map( $days );
		$rows     = array();
		$summary  = array_fill_keys( array_keys( self::HEALTH ), 0 );
		$summary['discrepancy'] = 0;
		$summary['stock_value'] = 0;

		foreach ( $all['rows'] as $v ) {
			$id       = (int) $v['dk_variant_id'];
			$velocity = StockPlanner::velocity( isset( $sales[ $id ] ) ? $sales[ $id ] : array(), $days );
			$total    = (int) $v['selling_stock'];
			$health   = StockPlanner::health( $total, $velocity, $low_days );
			$woo      = null;
			$announce = null;
			if ( $v['wc_product_id'] ) {
				$product = wc_get_product( $v['wc_variation_id'] ? (int) $v['wc_variation_id'] : (int) $v['wc_product_id'] );
				if ( $product ) {
					$woo      = $product->managing_stock() ? (int) $product->get_stock_quantity() : null;
					$announce = $this->mapper->stock( $product );
				}
			}
			// What WooCommerce says should be announced vs. what Digikala holds as seller stock.
			$discrepancy = null !== $announce && $announce !== (int) $v['seller_stock'];
			++$summary[ $health ];
			if ( $discrepancy ) {
				++$summary['discrepancy'];
			}
			$summary['stock_value'] += $total * (int) $v['price_sale'];

			$row = array(
				'variant'       => $v,
				'velocity'      => $velocity,
				'sold'          => isset( $sales[ $id ] ) ? array_sum( $sales[ $id ] ) : 0,
				'cover'         => StockPlanner::days_of_cover( $total, $velocity ),
				'health'        => $health,
				'woo_stock'     => $woo,
				'announce'      => $announce,
				'discrepancy'   => $discrepancy,
			);
			if ( ! empty( $filters['health'] ) && $filters['health'] !== $health ) {
				continue;
			}
			if ( ! empty( $filters['discrepancy'] ) && ! $discrepancy ) {
				continue;
			}
			if ( ! empty( $filters['dead'] ) && ! (int) $v['dead_stock'] ) {
				continue;
			}
			$rows[] = $row;
		}
		// Most urgent first: out of stock with sales, then low cover.
		usort(
			$rows,
			static function ( $a, $b ) {
				$rank = array(
					'out'       => 0,
					'low'       => 1,
					'ok'        => 3,
					'overstock' => 2,
					'no_sales'  => 4,
				);
				$r = $rank[ $a['health'] ] - $rank[ $b['health'] ];
				return 0 !== $r ? $r : ( $b['velocity'] <=> $a['velocity'] );
			}
		);
		$per = 50;
		return array(
			'rows'    => array_slice( $rows, ( max( 1, (int) $page ) - 1 ) * $per, $per ),
			'total'   => count( $rows ),
			'summary' => $summary,
		);
	}

	/**
	 * Re-send WooCommerce stock for discrepant variants.
	 *
	 * @param int[] $variant_ids Variants (empty = all discrepancies).
	 * @return int Marked.
	 */
	public function fix_discrepancies( array $variant_ids = array() ) {
		global $wpdb;
		if ( ! $variant_ids ) {
			foreach ( $this->overview( array( 'discrepancy' => 1 ), 1 )['rows'] as $row ) {
				$variant_ids[] = (int) $row['variant']['dk_variant_id'];
			}
		}
		$variant_ids = array_filter( array_map( 'intval', $variant_ids ) );
		if ( ! $variant_ids ) {
			return 0;
		}
		return (int) $wpdb->query( 'UPDATE ' . ItemRepository::table() . ' SET dirty_stock = 1 WHERE dk_variant_id IN (' . implode( ',', $variant_ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Periodic alerts (low stock, out of stock with demand, discrepancies) + optional auto-deactivation.
	 *
	 * @return array Summary.
	 */
	public function alerts() {
		$data   = $this->overview( array(), 1 ); // First page is sorted by urgency.
		$urgent = array_filter(
			$data['rows'],
			static function ( $r ) {
				return ( 'out' === $r['health'] && $r['sold'] > 0 ) || 'low' === $r['health'];
			}
		);
		if ( $this->settings->get( 'auto_deactivate_oos' ) ) {
			$ids = array();
			foreach ( $data['rows'] as $r ) {
				if ( 'out' === $r['health'] && (int) $r['variant']['active'] && 0 === (int) $r['announce'] && null !== $r['announce'] ) {
					$ids[] = (int) $r['variant']['dk_variant_id'];
				}
			}
			if ( $ids ) {
				try {
					$this->catalog->set_active( $ids, false );
					$this->logger->info( 'inventory', sprintf( '%d تنوع ناموجود غیرفعال شد.', count( $ids ) ) );
				} catch ( ApiException $e ) {
					$this->logger->error( 'inventory', $e->full_message() );
				}
			}
		}
		$to = (string) $this->settings->get( 'alert_email' );
		if ( $urgent && '' !== $to && is_email( $to ) ) {
			$lines = array();
			foreach ( array_slice( $urgent, 0, 30 ) as $r ) {
				$lines[] = sprintf(
					'- %s (DKPC-%d): %s، موجودی قابل فروش %d، فروش روزانه %s، پوشش %s روز',
					$r['variant']['title'],
					$r['variant']['dk_variant_id'],
					self::HEALTH[ $r['health'] ],
					$r['variant']['selling_stock'],
					$r['velocity'],
					null === $r['cover'] ? '—' : $r['cover']
				);
			}
			wp_mail( $to, sprintf( '[DK Bridge] %d کالا نیاز به تأمین موجودی دارند', count( $urgent ) ), implode( "\n", $lines ) . "\n\n" . admin_url( 'admin.php?page=dk-bridge-inventory' ) );
		}
		return array(
			'urgent'      => count( $urgent ),
			'discrepancy' => $data['summary']['discrepancy'],
		);
	}
}
