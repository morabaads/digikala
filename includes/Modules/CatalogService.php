<?php
/**
 * Product management: Digikala catalog snapshot, linking existing listings,
 * activation, archiving, bulk edits and content scores.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Data\ItemRepository;
use DKBridge\Data\VariantRepository;
use DKBridge\Settings;
use DKBridge\Support\Logger;
use DKBridge\Sync\OrderSync;
use DKBridge\Sync\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Catalog service.
 */
class CatalogService {

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var VariantRepository */
	private $variants;
	/** @var ItemRepository */
	private $items;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Digikala          $api      API.
	 * @param VariantRepository $variants Variants.
	 * @param ItemRepository    $items    Items.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, VariantRepository $variants, ItemRepository $items, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->variants = $variants;
		$this->items    = $items;
		$this->logger   = $logger;
	}

	/**
	 * Hooks.
	 */
	public function register() {
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 20, 3 );
	}

	/**
	 * Pull every seller variant from Digikala into the snapshot and auto-link.
	 *
	 * @param int $max_pages Pages of 100.
	 * @return array{synced:int,linked:int}
	 */
	public function sync( $max_pages = 50 ) {
		if ( ! Queue::lock( 'catalog_sync', 3000 ) ) {
			return array(
				'synced' => 0,
				'linked' => 0,
			);
		}
		$synced = 0;
		$linked = 0;
		try {
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$data = $this->api->variants( array(), $page, 100 );
				$rows = isset( $data['items'] ) ? (array) $data['items'] : array();
				foreach ( $rows as $row ) {
					$this->variants->upsert_api( $row );
					++$synced;
					if ( $this->settings->get( 'catalog_auto_link' ) && $this->auto_link( $row ) ) {
						++$linked;
					}
				}
				$pages = isset( $data['pager']['total_pages'] ) ? (int) $data['pager']['total_pages'] : 1;
				if ( $page >= $pages || ! $rows ) {
					break;
				}
			}
			$this->mark_dead_stock();
			update_option( 'dkb_catalog_synced_at', time(), false );
		} catch ( ApiException $e ) {
			$this->logger->error( 'catalog', 'همگام‌سازی کاتالوگ ناموفق: ' . $e->full_message() );
		} finally {
			Queue::unlock( 'catalog_sync' );
		}
		if ( $synced ) {
			$this->logger->info( 'catalog', sprintf( '%d تنوع از دیجی‌کالا به‌روزرسانی شد؛ %d اتصال جدید.', $synced, $linked ) );
		}
		return array(
			'synced' => $synced,
			'linked' => $linked,
		);
	}

	/**
	 * Flag variants with dead stock (aged inventory in Digikala warehouses).
	 */
	private function mark_dead_stock() {
		global $wpdb;
		try {
			$data = $this->api->inventories( array( 'product_filter' => 'has_dead_stock' ), 1, 100 );
		} catch ( ApiException $e ) {
			return; // Needs the inventory scope; optional.
		}
		$wpdb->query( 'UPDATE ' . VariantRepository::table() . ' SET dead_stock = 0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( isset( $data['items'] ) ? (array) $data['items'] : array() as $row ) {
			if ( ! empty( $row['product_variant_id'] ) ) {
				$this->variants->update( (int) $row['product_variant_id'], array( 'dead_stock' => 1 ) );
			}
		}
	}

	/**
	 * Link a Digikala variant to WooCommerce by existing pipeline row or supplier code.
	 *
	 * @param array $row API row.
	 * @return bool Newly linked.
	 */
	private function auto_link( array $row ) {
		$variant_id = (int) $row['id'];
		$snapshot   = $this->variants->get( $variant_id );
		if ( $snapshot && $snapshot['wc_product_id'] ) {
			return false;
		}
		$item = $this->items->find_by_variant( $variant_id );
		if ( $item ) {
			$this->variants->link( $variant_id, $item['wc_product_id'], $item['wc_variation_id'], 'pipeline' );
			return true;
		}
		$code = isset( $row['supplier_code'] ) ? trim( (string) $row['supplier_code'] ) : '';
		if ( '' === $code ) {
			return false;
		}
		$product = OrderSync::product_by_supplier_code( $code );
		if ( ! $product ) {
			return false;
		}
		$this->connect( $variant_id, $product, 0 === strpos( $code, 'wc-' ) ? 'code' : 'sku', isset( $row['product_id'] ) ? (int) $row['product_id'] : 0 );
		return true;
	}

	/**
	 * Connect a Digikala variant with a WooCommerce product (also enables stock/price sync).
	 *
	 * @param int         $variant_id    Variant.
	 * @param \WC_Product $product       Product or variation.
	 * @param string      $method        Link method.
	 * @param int         $dk_product_id DKP.
	 */
	public function connect( $variant_id, $product, $method = 'manual', $dk_product_id = 0 ) {
		$parent    = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
		$variation = $product->get_parent_id() ? (int) $product->get_id() : 0;
		if ( ! $dk_product_id ) {
			$snap          = $this->variants->get( $variant_id );
			$dk_product_id = $snap ? (int) $snap['dk_product_id'] : 0;
		}
		$this->variants->link( $variant_id, $parent, $variation, $method );
		$this->items->upsert(
			$parent,
			$variation,
			array(
				'dk_variant_id' => (int) $variant_id,
				'dk_product_id' => $dk_product_id ? $dk_product_id : null,
				'state'         => 'live',
				'dirty_stock'   => 1,
			)
		);
		if ( $variation ) {
			$parent_item = $this->items->get( $parent );
			if ( ! $parent_item || ! $parent_item['dk_product_id'] ) {
				$this->items->upsert(
					$parent,
					0,
					array(
						'dk_product_id'     => $dk_product_id ? $dk_product_id : null,
						'state'             => 'live',
						'moderation_status' => 'approved',
					)
				);
			}
		}
		$this->logger->info( 'catalog', sprintf( 'تنوع %d به محصول ووکامرس #%d متصل شد (%s).', $variant_id, $product->get_id(), $method ), array(), $parent );
	}

	/**
	 * Remove a link.
	 *
	 * @param int $variant_id Variant.
	 */
	public function disconnect( $variant_id ) {
		global $wpdb;
		$this->variants->link( $variant_id, 0, 0, '' );
		$wpdb->update( ItemRepository::table(), array( 'dk_variant_id' => null, 'state' => 'local' ), array( 'dk_variant_id' => (int) $variant_id ) );
	}

	/**
	 * Activate / deactivate variants (Batch API).
	 *
	 * @param int[] $ids    Variants.
	 * @param bool  $active Active.
	 * @return array
	 */
	public function set_active( array $ids, $active ) {
		$items = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$items[] = array(
				'variant_id' => $id,
				'payload'    => array( 'activation' => (bool) $active ),
			);
		}
		$out = array();
		foreach ( array_chunk( $items, 100 ) as $chunk ) {
			$out[] = $this->api->batch_activation( $chunk );
			foreach ( $chunk as $row ) {
				$this->variants->update( $row['variant_id'], array( 'active' => $active ? 1 : 0 ) );
			}
		}
		return $out;
	}

	/**
	 * Archive / unarchive.
	 *
	 * @param int[] $ids     Variants.
	 * @param bool  $archive Archive.
	 * @return array{ok:int,errors:string[]}
	 */
	public function set_archive( array $ids, $archive ) {
		$ok     = 0;
		$errors = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			try {
				$this->api->set_archive( $id, $archive );
				$this->variants->update( $id, array( 'archived' => $archive ? 1 : 0 ) );
				++$ok;
			} catch ( ApiException $e ) {
				$errors[] = $id . ': ' . $e->full_message();
			}
		}
		return array(
			'ok'     => $ok,
			'errors' => $errors,
		);
	}

	/**
	 * Bulk update variant fields (max per order, lead time, shipping type, price, stock).
	 *
	 * @param int[] $ids     Variants.
	 * @param array $payload Payload of PUT /variants (subset).
	 * @return array
	 */
	public function bulk_update( array $ids, array $payload ) {
		$allowed = array( 'maximum_per_order', 'lead_time', 'seller_lead_time', 'shipping_type', 'selling_price', 'seller_stock' );
		$payload = array_intersect_key( $payload, array_flip( $allowed ) );
		foreach ( $payload as $k => $v ) {
			$payload[ $k ] = 'shipping_type' === $k ? ( 'seller' === $v ? 'seller' : 'digikala' ) : max( 0, (int) $v );
		}
		if ( ! $payload ) {
			throw new \InvalidArgumentException( __( 'هیچ فیلد معتبری برای ویرایش ارسال نشد.', 'dk-bridge' ) );
		}
		$items = array();
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$items[] = array(
				'variant_id' => $id,
				'payload'    => $payload,
			);
		}
		$out = array();
		foreach ( array_chunk( $items, 100 ) as $chunk ) {
			$out[] = $this->api->batch_update( $chunk );
		}
		return $out;
	}

	/**
	 * Deactivate Digikala variants when a WooCommerce product leaves "publish", and back.
	 *
	 * @param string   $new  New status.
	 * @param string   $old  Old status.
	 * @param \WP_Post $post Post.
	 */
	public function on_status_change( $new, $old, $post ) {
		if ( $new === $old || ! in_array( $post->post_type, array( 'product', 'product_variation' ), true ) || ! $this->settings->get( 'deactivate_unpublished' ) ) {
			return;
		}
		$product_id = 'product_variation' === $post->post_type ? (int) $post->post_parent : (int) $post->ID;
		$ids        = array();
		foreach ( $this->variants->for_wc_product( $product_id ) as $v ) {
			if ( 'product_variation' === $post->post_type && (int) $v['wc_variation_id'] !== (int) $post->ID ) {
				continue;
			}
			$ids[] = (int) $v['dk_variant_id'];
		}
		if ( ! $ids ) {
			return;
		}
		$active = 'publish' === $new;
		if ( ! $active && 'publish' !== $old ) {
			return;
		}
		try {
			$this->set_active( $ids, $active );
			$this->logger->info( 'catalog', sprintf( 'وضعیت محصول در ووکامرس «%s» شد؛ %d تنوع دیجی‌کالا %s شد.', $new, count( $ids ), $active ? 'فعال' : 'غیرفعال' ), array(), $product_id );
		} catch ( ApiException $e ) {
			$this->logger->error( 'catalog', $e->full_message(), array(), $product_id );
		}
	}

	/**
	 * Content score of a Digikala product.
	 *
	 * @param int $dk_product_id DKP.
	 * @return array{percent:int,sections:array}
	 */
	public function content_score( $dk_product_id ) {
		$data    = $this->api->product_score( $dk_product_id );
		$details = isset( $data['scores']['score_details'] ) ? (array) $data['scores']['score_details'] : array();
		$percent = isset( $data['scores']['percent'] ) ? (int) $data['scores']['percent'] : 0;
		if ( ! $percent && ! empty( $data['score']['max'] ) ) {
			$percent = (int) round( 100 * $data['score']['current'] / $data['score']['max'] );
		}
		$weak = array();
		foreach ( $details as $key => $s ) {
			if ( isset( $s['max'], $s['current'] ) && $s['current'] < $s['max'] ) {
				$weak[ $key ] = $s;
			}
		}
		return array(
			'percent'  => $percent,
			'sections' => $details,
			'weak'     => $weak,
		);
	}
}
