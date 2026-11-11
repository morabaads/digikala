<?php
/**
 * Typed facade over the Digikala endpoints used by the plugin (with caching).
 *
 * @package DKBridge
 */

namespace DKBridge\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint facade.
 */
class Digikala {

	/**
	 * Client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Client $client Client.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Raw client.
	 *
	 * @return Client
	 */
	public function client() {
		return $this->client;
	}

	/**
	 * Cached GET.
	 *
	 * @param string $path  Path.
	 * @param array  $query Query.
	 * @param int    $ttl   Seconds.
	 * @return array
	 */
	private function cached( $path, array $query, $ttl ) {
		$key    = 'dkb_c_' . md5( $path . '|' . wp_json_encode( $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$data = $this->client->get( $path, $query );
		set_transient( $key, $data, $ttl );
		return $data;
	}

	/**
	 * Clear all cached API responses.
	 */
	public static function flush_cache() {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_dkb\_c\_%' OR option_name LIKE '\_transient\_timeout\_dkb\_c\_%'" );
	}

	// ----------------------------------------------------------------- Service.

	/**
	 * Health check (GET /).
	 *
	 * @return array
	 */
	public function health() {
		return $this->client->get( '/' );
	}

	/**
	 * Seller profile (scope: profile).
	 *
	 * @return array
	 */
	public function profile() {
		return $this->client->get( '/profile' );
	}

	// -------------------------------------------------------------- Categories.

	/**
	 * Category tree children.
	 *
	 * @param int|null $parent_id Parent.
	 * @return array
	 */
	public function category_children( $parent_id = null ) {
		$query = $parent_id ? array( 'search' => array( 'parent_id' => (int) $parent_id ) ) : array();
		return $this->cached( '/categories/tree', $query, WEEK_IN_SECONDS );
	}

	/**
	 * Search categories by keyword or id.
	 *
	 * @param string $keyword Keyword.
	 * @return array Items [{id,text,leaf}].
	 */
	public function search_categories( $keyword ) {
		$data = $this->cached( '/product-creation/search/category/v2/' . rawurlencode( (string) $keyword ), array(), DAY_IN_SECONDS );
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Suggest categories for a product keyword.
	 *
	 * @param string $keyword Keyword.
	 * @return array
	 */
	public function suggest( $keyword ) {
		$data = $this->cached( '/product-creation/search/suggestion/v2', array( 'search' => array( 'keyword' => (string) $keyword ) ), DAY_IN_SECONDS );
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Validate a category and get binding data (brands, themes, dimension config…).
	 *
	 * @param int $category_id Leaf category.
	 * @return array
	 */
	public function category_validation( $category_id ) {
		return $this->cached( '/product-creation/category/' . (int) $category_id . '/validation', array(), 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Category attributes.
	 *
	 * @param int $category_id Category.
	 * @return array
	 */
	public function category_attributes( $category_id ) {
		return $this->cached( '/product-creation/attributes/' . (int) $category_id, array(), DAY_IN_SECONDS );
	}

	// ---------------------------------------------------------------- Products.

	/**
	 * Search existing Digikala products.
	 *
	 * @param string $keyword Keyword.
	 * @param array  $filters Extra search filters (categories, brands…).
	 * @return array
	 */
	public function search_products( $keyword, array $filters = array() ) {
		$data = $this->client->get( '/product-creation/search/v2', array( 'search' => array_merge( array( 'keyword' => (string) $keyword ), $filters ) ) );
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Can the seller sell this product?
	 *
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function be_seller( $product_id ) {
		return $this->cached( '/product-creation/be-seller/' . (int) $product_id, array(), HOUR_IN_SECONDS );
	}

	/**
	 * Search brands.
	 *
	 * @param string $title Title.
	 * @param string $lang  fa|en.
	 * @return array
	 */
	public function search_brands( $title, $lang = 'fa' ) {
		$field = 'en' === $lang ? 'title_en' : 'title_fa';
		$data  = $this->cached( '/product-creation/brand', array( 'search' => array( $field => (string) $title ) ), DAY_IN_SECONDS );
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Submit basic details; creates/updates a draft.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function validate_details( array $payload ) {
		return $this->client->post( '/product-creation/product/detail/validation', $payload );
	}

	/**
	 * Get a draft.
	 *
	 * @param int $draft_id Draft.
	 * @return array
	 */
	public function draft( $draft_id ) {
		return $this->client->get( '/product-creation/draft-product/' . (int) $draft_id );
	}

	/**
	 * Submit attributes for a draft.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function save_attributes( array $payload ) {
		return $this->client->post( '/product-creation/attributes', $payload );
	}

	/**
	 * Suggested title for a draft.
	 *
	 * @param int $draft_id Draft.
	 * @return array
	 */
	public function auto_title( $draft_id ) {
		return $this->client->get( '/product-creation/' . (int) $draft_id . '/auto-title' );
	}

	/**
	 * Save / validate title and description.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function save_title( array $payload ) {
		return $this->client->post( '/product-creation/auto-title/save', $payload );
	}

	/**
	 * Upload a product image to temp storage.
	 *
	 * @param string $file Local path.
	 * @return array {id,url,tempFile}
	 */
	public function upload_image( $file ) {
		$data = $this->client->upload( '/product-creation/images/upload', $file );
		if ( isset( $data['data']['id'] ) ) {
			return $data['data'];
		}
		return $data;
	}

	/**
	 * AI image check.
	 *
	 * @param string $image_id Uploaded image id.
	 * @param bool   $is_main  Main image.
	 * @return array {isValid,data:[hints]}
	 */
	public function image_ai( $image_id, $is_main ) {
		return $this->client->post(
			'/product-creation/images/ai',
			array(
				'image_id' => (string) $image_id,
				'is_main'  => (bool) $is_main,
			)
		);
	}

	/**
	 * Final save.
	 *
	 * @param array $payload Payload.
	 * @return array
	 */
	public function save_product( array $payload ) {
		return $this->client->post( '/product-creation/save', $payload );
	}

	/**
	 * Assign an existing product to the seller.
	 *
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function assign( $product_id ) {
		return $this->client->post( '/product-creation/assign', array( 'productId' => (int) $product_id ) );
	}

	/**
	 * Seller products list.
	 *
	 * @param array $search Filters.
	 * @param int   $page   Page.
	 * @param int   $size   Size.
	 * @return array
	 */
	public function seller_products( array $search = array(), $page = 1, $size = 50 ) {
		return $this->client->get(
			'/products/seller',
			array(
				'page'   => (int) $page,
				'size'   => (int) $size,
				'search' => $search,
			)
		);
	}

	/**
	 * Product edit view.
	 *
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function product_edit_view( $product_id ) {
		return $this->client->get( '/product-edit/' . (int) $product_id );
	}

	/**
	 * Update product.
	 *
	 * @param int   $product_id DKP.
	 * @param array $payload    Payload.
	 * @return array
	 */
	public function product_edit( $product_id, array $payload ) {
		return $this->client->put( '/product-edit/' . (int) $product_id, $payload );
	}

	/**
	 * Publish after edit.
	 *
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function product_publish( $product_id ) {
		return $this->client->post( '/product-edit/' . (int) $product_id . '/publish', array() );
	}

	/**
	 * Product content score.
	 *
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function product_score( $product_id ) {
		return $this->client->get( '/products/' . (int) $product_id . '/score' );
	}

	// ---------------------------------------------------------------- Variants.

	/**
	 * Variant creation configuration for a product.
	 *
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function variant_config( $product_id ) {
		return $this->client->get( '/variants/creation/' . (int) $product_id );
	}

	/**
	 * Theme values (colors / sizes).
	 *
	 * @param int $theme_id   Theme.
	 * @param int $product_id DKP.
	 * @return array
	 */
	public function theme_values( $theme_id, $product_id ) {
		$items = array();
		$page  = 1;
		do {
			$data  = $this->cached(
				'/variants/creation/themes/' . (int) $theme_id . '/theme-values',
				array(
					'product_id' => (int) $product_id,
					'page'       => $page,
					'size'       => 100,
				),
				DAY_IN_SECONDS
			);
			$items = array_merge( $items, isset( $data['items'] ) ? (array) $data['items'] : array() );
			$pages = isset( $data['pager']['total_pages'] ) ? (int) $data['pager']['total_pages'] : 1;
			++$page;
		} while ( $page <= $pages && $page <= 20 );
		return $items;
	}

	/**
	 * Create a variant.
	 *
	 * @param int   $product_id DKP.
	 * @param array $payload    Payload.
	 * @return array
	 */
	public function create_variant( $product_id, array $payload ) {
		return $this->client->post( '/variants/creation/' . (int) $product_id, $payload );
	}

	/**
	 * Get variant.
	 *
	 * @param int $variant_id Variant.
	 * @return array
	 */
	public function variant( $variant_id ) {
		return $this->client->get( '/variants/' . (int) $variant_id );
	}

	/**
	 * Seller variants list.
	 *
	 * @param array $search Filters.
	 * @param int   $page   Page.
	 * @param int   $size   Size.
	 * @return array
	 */
	public function variants( array $search = array(), $page = 1, $size = 100 ) {
		return $this->client->get(
			'/variants',
			array(
				'page'   => (int) $page,
				'size'   => (int) $size,
				'search' => $search,
			)
		);
	}

	/**
	 * Update a variant.
	 *
	 * @param int   $variant_id Variant.
	 * @param array $payload    Payload.
	 * @return array
	 */
	public function update_variant( $variant_id, array $payload ) {
		return $this->client->put( '/variants/' . (int) $variant_id, $payload );
	}

	/**
	 * Absolute seller stock.
	 *
	 * @param int $variant_id Variant.
	 * @param int $stock      Stock.
	 * @return array
	 */
	public function set_stock( $variant_id, $stock ) {
		return $this->client->patch( '/variants/' . (int) $variant_id . '/seller-stock', array( 'seller_stock' => max( 0, (int) $stock ) ) );
	}

	/**
	 * Allowed price boundary.
	 *
	 * @param int $variant_id Variant.
	 * @return array {default_price_boundary:{min_allowed_price,max_allowed_price}}
	 */
	public function price_boundary( $variant_id ) {
		return $this->cached( '/pricing/price-stats/' . (int) $variant_id . '/boundary', array(), HOUR_IN_SECONDS );
	}

	/**
	 * Buy box winning price.
	 *
	 * @param int $variant_id Variant.
	 * @return int|null
	 */
	public function winning_price( $variant_id ) {
		$data = $this->client->get( '/pricing/buybox/price-suggestion/winning-price', array( 'product_variant_id' => (int) $variant_id ) );
		return isset( $data['suggested_price'] ) ? (int) $data['suggested_price'] : null;
	}

	// ------------------------------------------------------------------- Batch.

	/**
	 * Batch variant update.
	 *
	 * @param array $items [{variant_id,payload}].
	 * @param int   $deadline Seconds.
	 * @return array {batch_id,status}
	 */
	public function batch_update( array $items, $deadline = 300 ) {
		return $this->client->post(
			'/batch/variant/update',
			array(
				'deadline' => (int) $deadline,
				'items'    => array_values( $items ),
			)
		);
	}

	/**
	 * Batch stock update.
	 *
	 * @param array $items [{variant_id,payload:{seller_stock}}].
	 * @param int   $deadline Seconds.
	 * @return array
	 */
	public function batch_stock( array $items, $deadline = 300 ) {
		return $this->client->post(
			'/batch/variant/seller-stock/update',
			array(
				'deadline' => (int) $deadline,
				'items'    => array_values( $items ),
			)
		);
	}

	/**
	 * Batch activation update.
	 *
	 * @param array $items [{variant_id,payload:{activation}}].
	 * @return array
	 */
	public function batch_activation( array $items ) {
		return $this->client->post(
			'/batch/variant/activation/update',
			array(
				'deadline' => 300,
				'items'    => array_values( $items ),
			)
		);
	}

	/**
	 * Batch status.
	 *
	 * @param int $batch_id Batch.
	 * @return array
	 */
	public function batch_inquiry( $batch_id ) {
		return $this->client->post( '/batch/inquiry', array( 'batch_id' => (int) $batch_id ) );
	}

	// ------------------------------------------------------------------ Orders.

	/**
	 * Active order items.
	 *
	 * @param int  $page  Page.
	 * @param bool $today Only today.
	 * @return array
	 */
	public function orders( $page = 1, $today = false ) {
		$query = array(
			'page'  => (int) $page,
			'size'  => 100,
			'sort'  => 'order_created_at',
			'order' => 'desc',
		);
		if ( $today ) {
			$query['search'] = array( 'created_today' => true );
		}
		return $this->client->get( '/orders', $query );
	}

	// ---------------------------------------------------------------- Webhooks.

	/**
	 * Webhook event types.
	 *
	 * @return array
	 */
	public function webhook_events() {
		return $this->client->get( '/webhook/event-types' );
	}

	/**
	 * Subscribe events.
	 *
	 * @param string[] $events Events.
	 * @return array
	 */
	public function webhook_subscribe( array $events ) {
		return $this->client->post( '/webhook/subscription', array( 'event_types' => array_values( $events ) ) );
	}

	/**
	 * Toggle subscription activation.
	 *
	 * @param bool $active Active.
	 * @return array
	 */
	public function webhook_activation( $active ) {
		return $this->client->post( '/webhook/subscription/change-activation', array( 'activation' => (bool) $active ) );
	}

	// --------------------------------------------------------- Variant states.

	/**
	 * Activate / deactivate one variant.
	 *
	 * @param int  $variant_id Variant.
	 * @param bool $active     Active.
	 * @return array
	 */
	public function set_activation( $variant_id, $active ) {
		return $this->client->put( '/variants/' . (int) $variant_id . '/activation', array( 'activation' => (bool) $active ) );
	}

	/**
	 * Archive / unarchive a variant.
	 *
	 * @param int  $variant_id Variant.
	 * @param bool $archive    Archive.
	 * @return array
	 */
	public function set_archive( $variant_id, $archive ) {
		return $this->client->put( '/variants/' . (int) $variant_id . '/archive', array( 'archive' => (bool) $archive ) );
	}

	/**
	 * n-th rank buy box price.
	 *
	 * @param int $variant_id Variant.
	 * @param int $rank       Rank.
	 * @return int|null
	 */
	public function rank_price( $variant_id, $rank ) {
		$data = $this->client->get(
			'/pricing/buybox/price-suggestion/nth-rank-winning-price',
			array(
				'product_variant_id' => (int) $variant_id,
				'target_rank'        => max( 1, (int) $rank ),
			)
		);
		return isset( $data['suggested_price'] ) ? (int) $data['suggested_price'] : null;
	}

	// --------------------------------------------------------------- Inventory.

	/**
	 * Inventory list (Digikala warehouses + seller stock).
	 *
	 * @param array $search Filters (stock_status, product_filter, warehouse_filter…).
	 * @param int   $page   Page.
	 * @param int   $size   Size.
	 * @return array
	 */
	public function inventories( array $search = array(), $page = 1, $size = 100 ) {
		return $this->client->get(
			'/inventories',
			array(
				'page'   => (int) $page,
				'size'   => (int) $size,
				'search' => $search,
			)
		);
	}

	/**
	 * Serial-level stock age of a variant (dead stock).
	 *
	 * @param int $variant_id Variant.
	 * @return array
	 */
	public function inventory_detail( $variant_id ) {
		return $this->client->get( '/inventories/' . (int) $variant_id );
	}

	// --------------------------------------------------------------- Logistics.

	/**
	 * Variants that can be sent to Digikala warehouses (consignment).
	 *
	 * @param array $search Filters.
	 * @param int   $page   Page.
	 * @return array
	 */
	public function consignment_variants( array $search = array(), $page = 1 ) {
		return $this->client->get(
			'/variants/packages/consignment',
			array(
				'page'   => (int) $page,
				'size'   => 100,
				'search' => $search,
			)
		);
	}

	/**
	 * Warehouses for a delivery type.
	 *
	 * @param string $delivery_type digikala|seller|mobile_hub|post.
	 * @param int    $nature        Shipping nature id.
	 * @return array
	 */
	public function warehouses( $delivery_type = 'digikala', $nature = 0 ) {
		$query = array( 'delivery_type' => (string) $delivery_type );
		if ( $nature ) {
			$query['shipping_nature_id'] = (int) $nature;
		}
		return $this->cached( '/packages/warehouses', $query, 6 * HOUR_IN_SECONDS );
	}

	/**
	 * Warehouse capacity for a package.
	 *
	 * @param int    $warehouse_id  Warehouse.
	 * @param string $delivery_type Delivery type.
	 * @param string $date          ISO date.
	 * @param string $package_type  consignment|order_fulfilment.
	 * @param int[]  $variants      Variant ids.
	 * @param int[]  $counts        Counts.
	 * @return array
	 */
	public function warehouse_capacity( $warehouse_id, $delivery_type, $date, $package_type, array $variants, array $counts ) {
		return $this->client->post(
			'/packages/warehouses/' . (int) $warehouse_id . '/capacities',
			array(
				'delivery_type' => (string) $delivery_type,
				'date'          => (string) $date,
				'package_type'  => (string) $package_type,
				'variants'      => array_values( array_map( 'intval', $variants ) ),
				'counts'        => array_values( array_map( 'intval', $counts ) ),
			)
		);
	}

	/**
	 * Create packages.
	 *
	 * @param string $type     consignment|order_fulfilment.
	 * @param array  $packages Packages keyed 1..n: {items:[{variant_id,count}], shipping_date, delivery_type}.
	 * @return array
	 */
	public function create_packages( $type, array $packages ) {
		return $this->client->post(
			'/packages',
			array(
				'package_type' => (string) $type,
				'packages'     => (object) $packages,
			)
		);
	}

	/**
	 * Packages list.
	 *
	 * @param array $search Filters.
	 * @param int   $page   Page.
	 * @return array
	 */
	public function packages( array $search = array(), $page = 1 ) {
		return $this->client->get(
			'/packages',
			array(
				'page'   => (int) $page,
				'size'   => 50,
				'sort'   => 'created_at',
				'order'  => 'desc',
				'search' => $search,
			)
		);
	}

	/**
	 * Delete a package.
	 *
	 * @param int $package_id Package.
	 * @return array
	 */
	public function delete_package( $package_id ) {
		return $this->client->request( 'DELETE', '/packages/' . (int) $package_id );
	}

	/**
	 * Order items that need a fulfilment package (commitments).
	 *
	 * @param int $commitment_type 1 today, 2 tomorrow, 3 all.
	 * @return array
	 */
	public function fulfilment_variants( $commitment_type = 3 ) {
		return $this->client->get(
			'/packages/order-fulfilment/variants',
			array(
				'size'                    => 100,
				'[order_commitment_type]' => (int) $commitment_type,
			)
		);
	}

	/**
	 * Commitments (what must be delivered to warehouses, with penalties).
	 *
	 * @param array $search Filters.
	 * @param int   $page   Page.
	 * @return array
	 */
	public function commitments( array $search = array(), $page = 1 ) {
		return $this->client->get(
			'/commitments',
			array(
				'page'   => (int) $page,
				'size'   => 100,
				'search' => $search,
			)
		);
	}

	/**
	 * Commitments metadata (summary, penalties, package deadlines).
	 *
	 * @return array
	 */
	public function commitments_meta() {
		return $this->cached( '/commitments/metadata', array(), 30 * MINUTE_IN_SECONDS );
	}

	/**
	 * Ship-by-seller orders.
	 *
	 * @param array $search Filters (status, search_term, critical_only…).
	 * @param int   $page   Page.
	 * @return array
	 */
	public function sbs_orders( array $search = array(), $page = 1 ) {
		return $this->client->get(
			'/ship-by-seller-orders',
			array(
				'page'   => (int) $page,
				'size'   => 50,
				'sort'   => 'promise_date',
				'order'  => 'asc',
				'search' => $search,
			)
		);
	}

	/**
	 * Update an SBS shipment status.
	 *
	 * @param int    $shipment_id Shipment.
	 * @param string $status      processing|processed|full_delivered_to_customer.
	 * @param string $code        Customer verification code (for delivered).
	 * @return array
	 */
	public function sbs_update_status( $shipment_id, $status, $code = '' ) {
		$body = array(
			'order_shipment_id' => (int) $shipment_id,
			'new_status'        => (string) $status,
		);
		if ( '' !== (string) $code ) {
			$body['verification_code'] = (int) $code;
		}
		return $this->client->put( '/ship-by-seller-orders/update-status', $body );
	}

	/**
	 * Add a post tracking code to an SBS shipment.
	 *
	 * @param int    $shipment_id Shipment.
	 * @param string $code        Tracking code.
	 * @param string $infra       post|others.
	 * @param string $service     Courier name for "others".
	 * @return array
	 */
	public function sbs_tracking_code( $shipment_id, $code, $infra = 'post', $service = '' ) {
		$body = array(
			'order_shipment_id' => (int) $shipment_id,
			'tracking_codes'    => array( array( 'tracking_code' => (string) $code ) ),
			'infra_type'        => (string) $infra,
		);
		if ( '' !== $service ) {
			$body['service_name'] = (string) $service;
		}
		return $this->client->post( '/ship-by-seller-orders/tracking-code', $body );
	}

	// -------------------------------------------------------------- Promotions.

	/**
	 * Promotions the seller can join.
	 *
	 * @param array $search Filters (nature, excludeJoined…).
	 * @return array
	 */
	public function eligible_promotions( array $search = array() ) {
		return $this->cached(
			'/pricing/promotions/eligible',
			array(
				'size'   => 50,
				'sort'   => 'join_deadline',
				'order'  => 'asc',
				'search' => $search,
			),
			15 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Promotions the seller registered in.
	 *
	 * @param array $search Filters.
	 * @return array
	 */
	public function my_promotions( array $search = array() ) {
		return $this->client->get(
			'/pricing/promotions',
			array(
				'size'   => 50,
				'sort'   => 'promotion_id',
				'order'  => 'desc',
				'search' => $search,
			)
		);
	}

	/**
	 * Promotion details.
	 *
	 * @param int $promotion_id Promotion.
	 * @return array
	 */
	public function promotion( $promotion_id ) {
		return $this->cached( '/pricing/promotions/' . (int) $promotion_id, array(), 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Variants eligible for a promotion.
	 *
	 * @param int   $promotion_id Promotion.
	 * @param int   $page         Page.
	 * @param array $search       Filters.
	 * @return array
	 */
	public function promotion_eligible_variants( $promotion_id, $page = 1, array $search = array() ) {
		return $this->client->get(
			'/pricing/promotions/' . (int) $promotion_id . '/variants/eligible_v2',
			array(
				'page'   => (int) $page,
				'size'   => 100,
				'search' => $search,
			)
		);
	}

	/**
	 * Variants joined to a promotion.
	 *
	 * @param int $promotion_id Promotion.
	 * @param int $page         Page.
	 * @return array
	 */
	public function promotion_variants( $promotion_id, $page = 1 ) {
		return $this->client->get(
			'/pricing/promotions/' . (int) $promotion_id . '/variants',
			array(
				'page' => (int) $page,
				'size' => 100,
			)
		);
	}

	/**
	 * Join variants to a promotion.
	 *
	 * @param int   $promotion_id Promotion.
	 * @param array $rows         [{variant_id, promotion_price, order_limit, price_limit}].
	 * @return array
	 */
	public function join_promotion( $promotion_id, array $rows ) {
		return $this->client->post( '/pricing/promotions/' . (int) $promotion_id . '/variants', array( 'data' => array_values( $rows ) ) );
	}

	/**
	 * Remove promotion variants.
	 *
	 * @param int   $promotion_id Promotion.
	 * @param int[] $ids          Promotion variant ids.
	 * @return array
	 */
	public function leave_promotion( $promotion_id, array $ids ) {
		return $this->client->request( 'DELETE', '/pricing/promotions/' . (int) $promotion_id . '/variants', array( 'json' => array( 'ids' => array_values( array_map( 'intval', $ids ) ) ) ) );
	}

	/**
	 * Rejection reasons of a promotion variant.
	 *
	 * @param int $promotion_variant_id Id.
	 * @return array
	 */
	public function promotion_rejection( $promotion_variant_id ) {
		return $this->client->get( '/pricing/promotions/variants/rejection-reason/' . (int) $promotion_variant_id );
	}

	/**
	 * Variants eligible for smart (periodic) discounts.
	 *
	 * @param int $page Page.
	 * @return array
	 */
	public function smart_discount_eligible( $page = 1 ) {
		return $this->client->get(
			'/pricing/smart-discount/variants/eligible',
			array(
				'page' => (int) $page,
				'size' => 100,
			)
		);
	}

	/**
	 * Variants with smart discounts.
	 *
	 * @param string $status active|inactive.
	 * @return array
	 */
	public function smart_discounts( $status = 'active' ) {
		return $this->client->get( '/pricing/smart-discount/variants/' . ( 'inactive' === $status ? 'inactive' : 'active' ), array( 'size' => 100 ) );
	}

	/**
	 * Create smart discounts.
	 *
	 * @param array $rows [{variantId, promotionPrice, limit, orderLimit, startAt, endAt}].
	 * @return array
	 */
	public function create_smart_discounts( array $rows ) {
		return $this->client->post( '/pricing/smart-discount/variants/batch', array( 'data' => array_values( $rows ) ) );
	}

	/**
	 * Delete smart discounts.
	 *
	 * @param int[] $variant_ids Variants.
	 * @return array
	 */
	public function delete_smart_discounts( array $variant_ids ) {
		return $this->client->request( 'DELETE', '/pricing/smart-discount/variants', array( 'json' => array( 'variant_ids' => array_values( array_map( 'intval', $variant_ids ) ) ) ) );
	}

	// --------------------------------------------------------------- Analytics.

	/**
	 * Insight overview.
	 *
	 * @param string $range last_7_days|last_14_days|last_30_days.
	 * @return array
	 */
	public function insight_overview( $range = 'last_30_days' ) {
		return $this->cached( '/insight/overview', array( 'range' => (string) $range ), HOUR_IN_SECONDS );
	}

	/**
	 * Sales trend.
	 *
	 * @param string $range Range.
	 * @return array
	 */
	public function insight_trend( $range = 'last_30_days' ) {
		return $this->cached( '/insight/trend-sales-reports', array( 'range' => (string) $range ), HOUR_IN_SECONDS );
	}

	/**
	 * Per product sales report.
	 *
	 * @param string $range Range.
	 * @return array
	 */
	public function insight_sales( $range = 'last_30_days' ) {
		return $this->cached( '/insight/sales-reports', array( 'range' => (string) $range ), HOUR_IN_SECONDS );
	}

	/**
	 * Out of stock products with missed revenue.
	 *
	 * @return array
	 */
	public function insight_top_deactivated() {
		return $this->cached( '/insight/top-deactivated', array(), HOUR_IN_SECONDS );
	}

	/**
	 * Seller performance KPIs.
	 *
	 * @return array
	 */
	public function performance() {
		return $this->cached( '/profile/performance', array(), HOUR_IN_SECONDS );
	}

	/**
	 * Commission table.
	 *
	 * @param array $search Filters.
	 * @return array
	 */
	public function commissions( array $search = array( 'my_commissions' => true ) ) {
		return $this->cached(
			'/commissions/',
			array(
				'size'   => 100,
				'search' => $search,
			),
			DAY_IN_SECONDS
		);
	}
}
