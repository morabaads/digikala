<?php
/**
 * Stock & price synchronisation WooCommerce → Digikala (Batch API).
 *
 * @package DKBridge
 */

namespace DKBridge\Sync;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Api\DryRunException;
use DKBridge\Data\ItemRepository;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Inventory sync.
 */
class InventorySync {

	const BATCH_SIZE      = 100;
	const PENDING_OPTION  = 'dkb_pending_batches';

	/**
	 * Suppress dirty marking (while we apply Digikala orders to WooCommerce).
	 *
	 * @var bool
	 */
	public static $suppress = false;

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var ItemRepository */
	private $items;
	/** @var ProductMapper */
	private $mapper;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param Digikala       $api      API.
	 * @param ItemRepository $items    Items.
	 * @param ProductMapper  $mapper   Mapper.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, ItemRepository $items, ProductMapper $mapper, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->items    = $items;
		$this->mapper   = $mapper;
		$this->logger   = $logger;
	}

	/**
	 * Hook WooCommerce events.
	 */
	public function register() {
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock' ) );
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( $this, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_update' ), 20, 2 );
		add_action( 'woocommerce_update_product_variation', array( $this, 'on_product_update' ), 20, 2 );
	}

	/**
	 * Stock changed.
	 *
	 * @param \WC_Product $product Product.
	 */
	public function on_stock( $product ) {
		if ( self::$suppress || ! $this->settings->get( 'sync_stock' ) ) {
			return;
		}
		$this->mark( $product, 'stock' );
	}

	/**
	 * Stock status changed.
	 *
	 * @param int         $id      Id.
	 * @param string      $status  Status.
	 * @param \WC_Product $product Product.
	 */
	public function on_stock_status( $id, $status, $product = null ) {
		if ( self::$suppress || ! $this->settings->get( 'sync_stock' ) ) {
			return;
		}
		$product = $product ? $product : wc_get_product( $id );
		if ( $product ) {
			$this->mark( $product, 'stock' );
		}
	}

	/**
	 * Product saved (price may have changed).
	 *
	 * @param int         $id      Id.
	 * @param \WC_Product $product Product.
	 */
	public function on_product_update( $id, $product = null ) {
		if ( self::$suppress ) {
			return;
		}
		$product = $product ? $product : wc_get_product( $id );
		if ( ! $product ) {
			return;
		}
		$what = array();
		if ( $this->settings->get( 'sync_price' ) ) {
			$what[] = 'price';
		}
		if ( $this->settings->get( 'sync_stock' ) ) {
			$what[] = 'stock';
		}
		if ( $what ) {
			$this->mark( $product, 2 === count( $what ) ? 'both' : $what[0] );
		}
	}

	/**
	 * Mark product rows dirty.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $what    stock|price|both.
	 */
	private function mark( $product, $what ) {
		if ( $product->get_parent_id() ) {
			$this->items->mark_dirty( $product->get_parent_id(), $product->get_id(), $what );
		} else {
			$this->items->mark_dirty( $product->get_id(), $product->is_type( 'variable' ) ? -1 : 0, $what );
		}
	}

	/**
	 * WooCommerce product backing an item row.
	 *
	 * @param array $row Row.
	 * @return \WC_Product|null
	 */
	private function product_for( array $row ) {
		$product = wc_get_product( $row['wc_variation_id'] ? $row['wc_variation_id'] : $row['wc_product_id'] );
		return $product ? $product : null;
	}

	/**
	 * Push dirty stock.
	 *
	 * @return int Rows submitted.
	 */
	public function flush_stock() {
		if ( ! $this->settings->get( 'sync_stock' ) || ! Queue::lock( 'flush_stock', 280 ) ) {
			return 0;
		}
		$count = 0;
		try {
			$rows = $this->items->dirty( 'stock', 1000 );
			foreach ( array_chunk( $rows, self::BATCH_SIZE ) as $chunk ) {
				$items = array();
				$ids   = array();
				foreach ( $chunk as $row ) {
					$product = $this->product_for( $row );
					$ids[]   = $row['id'];
					if ( ! $product || 'yes' === get_post_meta( $row['wc_product_id'], '_dkb_exclude', true ) ) {
						continue;
					}
					$items[] = array(
						'variant_id' => (int) $row['dk_variant_id'],
						'payload'    => array( 'seller_stock' => $this->mapper->stock( $product ) ),
					);
				}
				if ( $items ) {
					$res = $this->api->batch_stock( $items );
					$this->remember_batch( $res, 'stock', $items );
					$count += count( $items );
				}
				$this->items->clear_dirty( $ids, 'stock' );
			}
		} catch ( DryRunException $e ) {
			$this->logger->info( 'sync', 'Dry run: stock batch not sent.' );
		} catch ( ApiException $e ) {
			$this->logger->error( 'sync', 'همگام‌سازی موجودی ناموفق: ' . $e->full_message() );
		} finally {
			Queue::unlock( 'flush_stock' );
		}
		if ( $count ) {
			$this->logger->info( 'sync', sprintf( 'موجودی %d تنوع به دیجی‌کالا ارسال شد.', $count ) );
		}
		return $count;
	}

	/**
	 * Push dirty prices (clamped into Digikala's allowed boundary).
	 *
	 * @return int
	 */
	public function flush_prices() {
		if ( ! $this->settings->get( 'sync_price' ) || ! Queue::lock( 'flush_prices', 880 ) ) {
			return 0;
		}
		$count = 0;
		try {
			$rows = $this->items->dirty( 'price', 500 );
			foreach ( array_chunk( $rows, self::BATCH_SIZE ) as $chunk ) {
				$items = array();
				$ids   = array();
				foreach ( $chunk as $row ) {
					$product = $this->product_for( $row );
					$ids[]   = $row['id'];
					if ( ! $product || 'yes' === get_post_meta( $row['wc_product_id'], '_dkb_exclude', true ) ) {
						continue;
					}
					$extra = $this->boundary_options( (int) $row['dk_variant_id'] );
					$calc  = $this->mapper->price( $product, $extra );
					if ( $calc['errors'] || $calc['price'] <= 0 ) {
						$this->logger->warning( 'sync', sprintf( 'قیمت تنوع %d ارسال نشد: %s', $row['dk_variant_id'], implode( '، ', $calc['errors'] ) ), array( 'steps' => $calc['steps'] ), $row['wc_product_id'] );
						continue;
					}
					$items[] = array(
						'variant_id' => (int) $row['dk_variant_id'],
						'payload'    => array(
							'selling_price'     => (int) $calc['price'],
							'maximum_per_order' => max( 1, (int) $this->settings->get( 'max_per_order' ) ),
						),
					);
				}
				if ( $items ) {
					$res = $this->api->batch_update( $items );
					$this->remember_batch( $res, 'price', $items );
					$count += count( $items );
				}
				$this->items->clear_dirty( $ids, 'price' );
			}
		} catch ( DryRunException $e ) {
			$this->logger->info( 'sync', 'Dry run: price batch not sent.' );
		} catch ( ApiException $e ) {
			$this->logger->error( 'sync', 'همگام‌سازی قیمت ناموفق: ' . $e->full_message() );
		} finally {
			Queue::unlock( 'flush_prices' );
		}
		if ( $count ) {
			$this->logger->info( 'sync', sprintf( 'قیمت %d تنوع به دیجی‌کالا ارسال شد.', $count ) );
		}
		return $count;
	}

	/**
	 * Price boundary → calculator options.
	 *
	 * @param int $variant_id Variant.
	 * @return array
	 */
	private function boundary_options( $variant_id ) {
		try {
			$b = $this->api->price_boundary( $variant_id );
		} catch ( ApiException $e ) {
			return array();
		}
		$out = array();
		if ( isset( $b['default_price_boundary']['min_allowed_price'] ) ) {
			$out['min'] = (int) $b['default_price_boundary']['min_allowed_price'];
		}
		if ( isset( $b['default_price_boundary']['max_allowed_price'] ) ) {
			$out['max'] = (int) $b['default_price_boundary']['max_allowed_price'];
		}
		return $out;
	}

	/**
	 * Remember a submitted batch for later inquiry.
	 *
	 * @param array  $res   Response.
	 * @param string $type  Type.
	 * @param array  $items Items.
	 */
	private function remember_batch( array $res, $type, array $items ) {
		if ( empty( $res['batch_id'] ) ) {
			return;
		}
		$pending                              = (array) get_option( self::PENDING_OPTION, array() );
		$pending[ (string) $res['batch_id'] ] = array(
			'type'     => $type,
			'count'    => count( $items ),
			'variants' => wp_list_pluck( $items, 'variant_id' ),
			'at'       => time(),
		);
		update_option( self::PENDING_OPTION, array_slice( $pending, -200, null, true ), false );
	}

	/**
	 * Check pending batches and re-mark failed items as dirty.
	 */
	public function check_batches() {
		$pending = (array) get_option( self::PENDING_OPTION, array() );
		if ( ! $pending ) {
			return;
		}
		foreach ( $pending as $batch_id => $info ) {
			try {
				$res = $this->api->batch_inquiry( (int) $batch_id );
			} catch ( ApiException $e ) {
				if ( time() - (int) $info['at'] > DAY_IN_SECONDS ) {
					unset( $pending[ $batch_id ] );
				}
				continue;
			}
			if ( isset( $res['status'] ) && 'completed' !== $res['status'] ) {
				continue;
			}
			$failed = 0;
			foreach ( isset( $res['items'] ) ? (array) $res['items'] : array() as $row ) {
				if ( isset( $row['status'] ) && 'error' === $row['status'] ) {
					++$failed;
					$item = $this->items->find_by_variant( (int) $row['key'] );
					if ( $item ) {
						$this->logger->warning( 'sync', sprintf( 'به‌روزرسانی تنوع %d در Batch ناموفق بود.', $row['key'] ), array( 'errors' => isset( $row['errors'] ) ? $row['errors'] : null ), $item['wc_product_id'] );
						$this->items->update( $item['id'], array( 'last_error' => wp_json_encode( isset( $row['errors'] ) ? $row['errors'] : array(), JSON_UNESCAPED_UNICODE ) ) );
					}
				}
			}
			if ( $failed ) {
				$this->logger->warning( 'sync', sprintf( 'Batch %s: %d مورد ناموفق.', $batch_id, $failed ) );
			}
			unset( $pending[ $batch_id ] );
		}
		update_option( self::PENDING_OPTION, $pending, false );
	}
}
