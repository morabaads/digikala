<?php
/**
 * REST endpoints for catalog, inventory, logistics, pricing, promotions, analytics and the assistant.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Api\ApiException;
use DKBridge\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace dk-bridge/v1/admin/m/*.
 */
class ModulesRestController {

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$routes = array(
			// Catalog.
			array( '/catalog/sync', 'POST', 'catalog_sync' ),
			array( '/catalog/activate', 'POST', 'catalog_activate' ),
			array( '/catalog/archive', 'POST', 'catalog_archive' ),
			array( '/catalog/bulk', 'POST', 'catalog_bulk' ),
			array( '/catalog/link', 'POST', 'catalog_link' ),
			array( '/catalog/unlink', 'POST', 'catalog_unlink' ),
			array( '/catalog/score/(?P<dkp>\d+)', 'GET', 'catalog_score' ),
			// Inventory.
			array( '/inventory/fix', 'POST', 'inventory_fix' ),
			array( '/inventory/alerts', 'POST', 'inventory_alerts' ),
			// Logistics.
			array( '/logistics/package', 'POST', 'logistics_package' ),
			array( '/logistics/warehouses', 'GET', 'logistics_warehouses' ),
			array( '/logistics/sbs/status', 'POST', 'sbs_status' ),
			array( '/logistics/sbs/tracking', 'POST', 'sbs_tracking' ),
			// Pricing.
			array( '/pricing/run', 'POST', 'pricing_run' ),
			array( '/pricing/preview/(?P<variant>\d+)', 'GET', 'pricing_preview' ),
			array( '/pricing/history/(?P<variant>\d+)', 'GET', 'pricing_history' ),
			// Promotions.
			array( '/promotions/(?P<id>\d+)/plan', 'GET', 'promo_plan' ),
			array( '/promotions/(?P<id>\d+)/join', 'POST', 'promo_join' ),
			array( '/promotions/(?P<id>\d+)/leave', 'POST', 'promo_leave' ),
			array( '/promotions/auto', 'POST', 'promo_auto' ),
			array( '/promotions/smart-discount', 'POST', 'smart_discount' ),
			// Assistant.
			array( '/assistant/tasks', 'GET', 'assistant_tasks' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				'dk-bridge/v1',
				'/admin/m' . $r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( $this, 'dispatch' ),
					'permission_callback' => static function () {
						return current_user_can( 'manage_woocommerce' );
					},
					'dkb_handler'         => $r[2],
				)
			);
		}
	}

	/**
	 * Dispatch with uniform error handling.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function dispatch( $request ) {
		$attrs   = $request->get_attributes();
		$handler = isset( $attrs['dkb_handler'] ) ? $attrs['dkb_handler'] : '';
		if ( ! $handler || ! method_exists( $this, $handler ) ) {
			return new \WP_REST_Response( array( 'message' => 'Not found' ), 404 );
		}
		try {
			return rest_ensure_response( $this->$handler( $request ) );
		} catch ( ApiException $e ) {
			return new \WP_REST_Response( array( 'message' => $e->full_message() ), $e->http_status() >= 400 && $e->http_status() < 600 ? $e->http_status() : 502 );
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_REST_Response( array( 'message' => $e->getMessage() ), 400 );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * Integer ids from request.
	 *
	 * @param \WP_REST_Request $r Request.
	 * @return int[]
	 */
	private static function ids( $r ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $r['ids'] ) ) );
		if ( ! $ids ) {
			throw new \InvalidArgumentException( __( 'هیچ موردی انتخاب نشده است.', 'dk-bridge' ) );
		}
		return array_slice( $ids, 0, 1000 );
	}

	// Catalog ---------------------------------------------------------------.

	private function catalog_sync() {
		return $this->plugin->catalog()->sync();
	}

	private function catalog_activate( $r ) {
		$this->plugin->catalog()->set_active( self::ids( $r ), (bool) $r['active'] );
		return array( 'ok' => true );
	}

	private function catalog_archive( $r ) {
		return $this->plugin->catalog()->set_archive( self::ids( $r ), (bool) $r['archive'] );
	}

	private function catalog_bulk( $r ) {
		return $this->plugin->catalog()->bulk_update( self::ids( $r ), (array) $r['payload'] );
	}

	private function catalog_link( $r ) {
		$product = wc_get_product( (int) $r['wc_id'] );
		if ( ! $product ) {
			$id      = wc_get_product_id_by_sku( sanitize_text_field( (string) $r['wc_id'] ) );
			$product = $id ? wc_get_product( $id ) : null;
		}
		if ( ! $product ) {
			throw new \InvalidArgumentException( __( 'محصول ووکامرس با این شناسه یا SKU پیدا نشد.', 'dk-bridge' ) );
		}
		$this->plugin->catalog()->connect( (int) $r['variant_id'], $product, 'manual' );
		return array(
			'ok'      => true,
			'product' => $product->get_name(),
		);
	}

	private function catalog_unlink( $r ) {
		$this->plugin->catalog()->disconnect( (int) $r['variant_id'] );
		return array( 'ok' => true );
	}

	private function catalog_score( $r ) {
		return $this->plugin->catalog()->content_score( (int) $r['dkp'] );
	}

	// Inventory -------------------------------------------------------------.

	private function inventory_fix( $r ) {
		$ids = array_values( array_filter( array_map( 'intval', (array) $r['ids'] ) ) );
		return array( 'marked' => $this->plugin->inventory_service()->fix_discrepancies( $ids ) );
	}

	private function inventory_alerts() {
		return $this->plugin->inventory_service()->alerts();
	}

	// Logistics -------------------------------------------------------------.

	private function logistics_package( $r ) {
		$items = array();
		foreach ( (array) $r['items'] as $row ) {
			$items[] = array(
				'variant_id' => isset( $row['variant_id'] ) ? (int) $row['variant_id'] : 0,
				'count'      => isset( $row['count'] ) ? (int) $row['count'] : 0,
			);
		}
		return $this->plugin->logistics()->create_package( sanitize_key( (string) $r['type'] ), $items, sanitize_text_field( (string) $r['date'] ), sanitize_key( (string) $r['delivery_type'] ) );
	}

	private function logistics_warehouses( $r ) {
		return $this->plugin->api()->warehouses( $r['delivery_type'] ? sanitize_key( (string) $r['delivery_type'] ) : 'digikala' );
	}

	private function sbs_status( $r ) {
		$status = sanitize_key( (string) $r['status'] );
		if ( ! in_array( $status, array( 'processing', 'processed', 'full_delivered_to_customer' ), true ) ) {
			throw new \InvalidArgumentException( 'Invalid status' );
		}
		return $this->plugin->api()->sbs_update_status( (int) $r['shipment_id'], $status, sanitize_text_field( (string) $r['code'] ) );
	}

	private function sbs_tracking( $r ) {
		$code = sanitize_text_field( (string) $r['code'] );
		if ( '' === $code ) {
			throw new \InvalidArgumentException( __( 'کد رهگیری خالی است.', 'dk-bridge' ) );
		}
		return $this->plugin->api()->sbs_tracking_code( (int) $r['shipment_id'], $code, $r['service'] ? 'others' : 'post', sanitize_text_field( (string) $r['service'] ) );
	}

	// Pricing ---------------------------------------------------------------.

	private function pricing_run( $r ) {
		$res = $this->plugin->repricer()->run( (bool) $r['apply'] );
		return array(
			'checked'   => $res['checked'],
			'updated'   => $res['updated'],
			'decisions' => array_slice( $res['decisions'], 0, 200 ),
		);
	}

	private function pricing_preview( $r ) {
		$v = $this->plugin->variants()->get( (int) $r['variant'] );
		if ( ! $v ) {
			throw new \InvalidArgumentException( __( 'تنوع در فهرست همگام‌شده نیست؛ ابتدا کاتالوگ را همگام کنید.', 'dk-bridge' ) );
		}
		$strategy = $r['strategy'] ? sanitize_key( (string) $r['strategy'] ) : null;
		$d        = $this->plugin->repricer()->evaluate( $v, true, $strategy );
		if ( ! $d ) {
			throw new \InvalidArgumentException( __( 'این تنوع به محصول ووکامرس متصل نیست.', 'dk-bridge' ) );
		}
		return $d;
	}

	private function pricing_history( $r ) {
		return $this->plugin->variants()->price_history( (int) $r['variant'] );
	}

	// Promotions ------------------------------------------------------------.

	private function promo_plan( $r ) {
		return $this->plugin->promotions()->plan( (int) $r['id'], (float) $r['discount'] );
	}

	private function promo_join( $r ) {
		return $this->plugin->promotions()->join( (int) $r['id'], (array) $r['rows'] );
	}

	private function promo_leave( $r ) {
		return $this->plugin->api()->leave_promotion( (int) $r['id'], self::ids( $r ) );
	}

	private function promo_auto() {
		return $this->plugin->promotions()->auto_join();
	}

	private function smart_discount( $r ) {
		$discount = (float) $r['discount'];
		$hours    = (int) $r['hours'];
		if ( $discount <= 0 || $discount >= 90 || $hours <= 0 ) {
			throw new \InvalidArgumentException( __( 'درصد تخفیف یا مدت نامعتبر است.', 'dk-bridge' ) );
		}
		return $this->plugin->promotions()->smart_discount( self::ids( $r ), $discount, $hours );
	}

	// Assistant -------------------------------------------------------------.

	private function assistant_tasks( $r ) {
		return $this->plugin->assistant()->tasks( (bool) $r['refresh'] );
	}
}
