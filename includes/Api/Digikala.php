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
}
