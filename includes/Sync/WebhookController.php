<?php
/**
 * Receives Digikala webhooks.
 *
 * @package DKBridge
 */

namespace DKBridge\Sync;

use DKBridge\Data\ItemRepository;
use DKBridge\Settings;
use DKBridge\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Webhook endpoint: POST /wp-json/dk-bridge/v1/webhook/{secret}.
 *
 * The exact payload shape is not documented, so handling is defensive: we look
 * for the ids we need anywhere in the payload and fall back to polling.
 */
class WebhookController {

	/** @var Settings */
	private $settings;
	/** @var ItemRepository */
	private $items;
	/** @var StatusPoller */
	private $poller;
	/** @var OrderSync */
	private $orders;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings       $settings Settings.
	 * @param ItemRepository $items    Items.
	 * @param StatusPoller   $poller   Poller.
	 * @param OrderSync      $orders   Orders.
	 * @param Logger         $logger   Logger.
	 */
	public function __construct( Settings $settings, ItemRepository $items, StatusPoller $poller, OrderSync $orders, Logger $logger ) {
		$this->settings = $settings;
		$this->items    = $items;
		$this->poller   = $poller;
		$this->orders   = $orders;
		$this->logger   = $logger;
	}

	/**
	 * Register the route.
	 */
	public function register_routes() {
		register_rest_route(
			'dk-bridge/v1',
			'/webhook/(?P<secret>[A-Za-z0-9]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);
	}

	/**
	 * Public webhook URL.
	 *
	 * @param Settings $settings Settings.
	 * @return string
	 */
	public static function url( Settings $settings ) {
		return rest_url( 'dk-bridge/v1/webhook/' . $settings->get( 'webhook_secret' ) );
	}

	/**
	 * Constant-time secret check.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	public function authorize( $request ) {
		$secret = (string) $this->settings->get( 'webhook_secret' );
		return '' !== $secret && hash_equals( $secret, (string) $request['secret'] );
	}

	/**
	 * Handle a delivery.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( $request ) {
		$payload = $request->get_json_params();
		if ( ! is_array( $payload ) ) {
			$payload = $request->get_body_params();
		}
		$payload = is_array( $payload ) ? $payload : array();
		$event   = self::find( $payload, array( 'event_type', 'event', 'type', 'topic' ) );
		$this->logger->info( 'webhook', sprintf( 'رویداد دریافت شد: %s', $event ? $event : 'unknown' ), array( 'payload' => $payload ) );

		/**
		 * Fires for every received webhook.
		 *
		 * @param string $event   Event type.
		 * @param array  $payload Payload.
		 */
		do_action( 'dkb_webhook_received', (string) $event, $payload );

		switch ( $event ) {
			case 'product_moderation':
				$product_id = (int) self::find( $payload, array( 'product_id', 'productId', 'dkp' ) );
				$status     = (string) self::find( $payload, array( 'moderation_status', 'status', 'new_status' ) );
				$item       = $product_id ? $this->items->find_by_dk_product( $product_id ) : null;
				if ( $item && '' !== $status && ! is_array( $status ) ) {
					$this->poller->apply_status( $item, $status );
				} elseif ( $item ) {
					$this->poller->refresh( $item );
				} else {
					Queue::push( 'dkb_poll_moderation', array(), 5 );
				}
				break;
			case 'order_processed':
			case 'order_shipment':
				Queue::push( 'dkb_pull_orders', array(), 5 );
				break;
			case 'order_item_cancellation':
			case 'order_return':
				$order_id   = (int) self::find( $payload, array( 'order_id', 'orderId' ) );
				$variant_id = (int) self::find( $payload, array( 'product_variant_id', 'variant_id', 'variantId' ) );
				if ( $order_id && $variant_id ) {
					$this->orders->restore( $order_id, $variant_id );
				}
				break;
			case 'product_variant_status_change':
				$variant_id = (int) self::find( $payload, array( 'product_variant_id', 'variant_id', 'variantId' ) );
				$item       = $variant_id ? $this->items->find_by_variant( $variant_id ) : null;
				if ( $item ) {
					$this->items->update( $item['id'], array( 'dirty_stock' => 1 ) );
				}
				break;
		}
		return new \WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}

	/**
	 * Find the first value for any of the keys, searching nested arrays.
	 *
	 * @param array    $data Data.
	 * @param string[] $keys Keys.
	 * @return mixed|null
	 */
	public static function find( array $data, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $data[ $key ] ) && ! is_array( $data[ $key ] ) ) {
				return $data[ $key ];
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = self::find( $value, $keys );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}
}
