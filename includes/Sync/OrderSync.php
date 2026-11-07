<?php
/**
 * Pull Digikala orders and decrease WooCommerce stock (oversell protection).
 *
 * @package DKBridge
 */

namespace DKBridge\Sync;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Data\ItemRepository;
use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Order sync.
 */
class OrderSync {

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var ItemRepository */
	private $items;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param Digikala       $api      API.
	 * @param ItemRepository $items    Items.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, ItemRepository $items, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->items    = $items;
		$this->logger   = $logger;
	}

	/**
	 * Pull recent order items.
	 *
	 * @param int $max_pages Pages to read.
	 * @return int New order items applied.
	 */
	public function pull( $max_pages = 5 ) {
		if ( ! $this->settings->get( 'pull_orders' ) || ! Queue::lock( 'pull_orders', 280 ) ) {
			return 0;
		}
		$applied = 0;
		try {
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$data  = $this->api->orders( $page );
				$rows  = isset( $data['items'] ) ? (array) $data['items'] : array();
				$fresh = 0;
				foreach ( $rows as $row ) {
					if ( $this->apply( $row ) ) {
						++$applied;
						++$fresh;
					}
				}
				$pages = isset( $data['pager']['total_pages'] ) ? (int) $data['pager']['total_pages'] : 1;
				// Sorted newest first: stop once a page brings nothing new.
				if ( ! $fresh || $page >= $pages ) {
					break;
				}
			}
		} catch ( ApiException $e ) {
			$this->logger->error( 'orders', 'دریافت سفارش‌ها ناموفق: ' . $e->full_message() );
		} finally {
			Queue::unlock( 'pull_orders' );
		}
		if ( $applied ) {
			$this->logger->info( 'orders', sprintf( '%d قلم سفارش جدید از دیجی‌کالا ثبت شد.', $applied ) );
		}
		update_option( 'dkb_last_order_pull', time(), false );
		return $applied;
	}

	/**
	 * Apply one order item (idempotent).
	 *
	 * @param array $row Order item.
	 * @return bool Applied now.
	 */
	public function apply( array $row ) {
		global $wpdb;
		$order_id   = isset( $row['order_id'] ) ? (int) $row['order_id'] : 0;
		$variant_id = isset( $row['product_variant_id'] ) ? (int) $row['product_variant_id'] : 0;
		$qty        = isset( $row['quantity'] ) ? (int) $row['quantity'] : 0;
		if ( ! $order_id || ! $variant_id || $qty <= 0 ) {
			return false;
		}
		$item    = $this->items->find_by_variant( $variant_id );
		$product = null;
		if ( $item ) {
			$product = wc_get_product( $item['wc_variation_id'] ? $item['wc_variation_id'] : $item['wc_product_id'] );
		} elseif ( ! empty( $row['supplier_code'] ) ) {
			$product = self::product_by_supplier_code( (string) $row['supplier_code'] );
		}

		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}dkb_orders (dk_order_id, dk_variant_id, quantity, selling_price, wc_product_id, status, order_created_at, processed_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %s)",
				$order_id,
				$variant_id,
				$qty,
				isset( $row['selling_price'] ) ? (int) $row['selling_price'] : 0,
				$product ? $product->get_id() : 0,
				$product ? 'processed' : 'unmatched',
				isset( $row['order_created_at'] ) ? gmdate( 'Y-m-d H:i:s', strtotime( (string) $row['order_created_at'] ) ) : null,
				current_time( 'mysql', true )
			)
		);
		if ( ! $inserted ) {
			return false; // Already processed.
		}
		if ( $product && $this->settings->get( 'reduce_wc_stock' ) && $product->managing_stock() ) {
			InventorySync::$suppress = true;
			try {
				$new = wc_update_product_stock( $product, $qty, 'decrease' );
			} finally {
				InventorySync::$suppress = false;
			}
			$this->logger->info( 'orders', sprintf( 'سفارش دیجی‌کالا %d: %d عدد از موجودی کم شد (موجودی جدید: %s).', $order_id, $qty, is_numeric( $new ) ? $new : '—' ), array(), $product->get_id() );
		}
		/**
		 * Fires when a new Digikala order item is recorded.
		 *
		 * @param array            $row     Order item.
		 * @param \WC_Product|null $product Product.
		 */
		do_action( 'dkb_order_item_received', $row, $product );
		return true;
	}

	/**
	 * Restore stock for a cancelled / returned order item.
	 *
	 * @param int $order_id   DK order.
	 * @param int $variant_id DK variant.
	 */
	public function restore( $order_id, $variant_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'dkb_orders';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE dk_order_id = %d AND dk_variant_id = %d AND status = 'processed'", $order_id, $variant_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row || ! $row['wc_product_id'] ) {
			return;
		}
		$product = wc_get_product( (int) $row['wc_product_id'] );
		if ( $product && $product->managing_stock() && $this->settings->get( 'reduce_wc_stock' ) ) {
			InventorySync::$suppress = true;
			try {
				wc_update_product_stock( $product, (int) $row['quantity'], 'increase' );
			} finally {
				InventorySync::$suppress = false;
			}
		}
		$wpdb->update( $table, array( 'status' => 'restored' ), array( 'id' => (int) $row['id'] ) );
		$this->logger->info( 'orders', sprintf( 'سفارش %d لغو/مرجوع شد؛ موجودی بازگردانده شد.', $order_id ), array(), (int) $row['wc_product_id'] );
	}

	/**
	 * Find a product by supplier code (SKU or wc-ID).
	 *
	 * @param string $code Code.
	 * @return \WC_Product|null
	 */
	public static function product_by_supplier_code( $code ) {
		if ( preg_match( '/^wc-(\d+)$/', $code, $m ) ) {
			$product = wc_get_product( (int) $m[1] );
			return $product ? $product : null;
		}
		$id = wc_get_product_id_by_sku( $code );
		return $id ? wc_get_product( $id ) : null;
	}
}
