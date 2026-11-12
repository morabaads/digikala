<?php
/**
 * Plugin settings (single option array with typed defaults).
 *
 * @package DKBridge
 */

namespace DKBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Typed settings store.
 */
class Settings {

	const OPTION = 'dkb_settings';

	/**
	 * Cached values.
	 *
	 * @var array|null
	 */
	private $values = null;

	/**
	 * Default values and their types.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Connection.
			'auth_mode'                 => 'self_token', // self_token | oauth.
			'seller_code'               => '',
			'client_code'               => '',
			'base_url'                  => 'https://seller.digikala.com',
			'dry_run'                   => false,
			'rate_limit'                => 300,
			'rate_window'               => 60,
			'timeout'                   => 30,

			// Pricing.
			'currency'                  => 'auto', // auto | toman | rial.
			'markup_percent'            => 0.0,
			'markup_fixed'              => 0, // Rial.
			'commission_compensation'   => false,
			'default_commission'        => 0.0, // Percent, used when DK commission is unknown.
			'rounding_step'             => 10000, // Rial.
			'rounding_mode'             => 'nearest', // nearest | up | down | psychological.
			'clamp_to_boundary'         => true,
			'min_profit_percent'        => 0.0,
			'price_source'              => 'active', // active | regular.

			// Stock.
			'sync_stock'                => true,
			'sync_price'                => true,
			'pull_orders'               => true,
			'reduce_wc_stock'           => true,
			'stock_buffer'              => 0,
			'stock_max'                 => 0,
			'unmanaged_stock'           => 10,
			'max_per_order'             => 5,

			// Fulfilment.
			'shipping_type'             => 'digikala', // digikala | seller.
			'lead_time'                 => 2,
			'seller_lead_time'          => 24,
			'warranty_id'               => 0,
			'site'                      => 'digikala',

			// Content & rules.
			'min_score'                 => 60,
			'title_min'                 => 15,
			'title_max'                 => 120,
			'desc_min'                  => 150,
			'desc_max'                  => 5000,
			'auto_fix_on_push'          => true,
			'extra_forbidden_words'     => '',
			'brand_source'              => 'auto', // auto | taxonomy:<tax> | attribute:<name> | meta:<key>.
			'model_source'              => 'sku', // sku | meta:<key> | attribute:<name>.
			'default_is_iranian'        => false,
			'default_disadvantage'      => '',
			'smart_match'               => true,
			'match_threshold'           => 0.85,
			'auto_use_matches'          => false,
			'dimension_unit_override'   => '', // '' = WooCommerce setting.

			// Images.
			'img_min'                   => 600,
			'img_target'                => 1200,
			'img_max'                   => 2500,
			'img_quality'               => 90,
			'img_max_bytes'             => 5242880,
			'img_fill'                  => 0.88,
			'img_max_count'             => 10,
			'use_ai_check'              => true,

			// Webhook.
			'webhook_secret'            => '',
			'webhook_events'            => array( 'product_moderation', 'product_variant_status_change', 'order_processed', 'order_item_cancellation', 'order_return' ),

			// Catalog management.
			'catalog_sync'              => true,
			'catalog_auto_link'         => true,
			'deactivate_unpublished'    => true,

			// Inventory management.
			'velocity_days'             => 30,
			'low_stock_days'            => 7,
			'alert_email'               => '',
			'auto_deactivate_oos'       => false,

			// Smart logistics.
			'cover_days'                => 21,
			'safety_days'               => 4,
			'inbound_days'              => 3,
			'replenish_min'             => 2,

			// Smart pricing (repricer).
			'repricer_enabled'          => false,
			'repricer_strategy'         => 'fixed', // fixed | buybox | rank | undercut.
			'repricer_rank'             => 2,
			'repricer_step'             => 10000, // Rial.
			'repricer_min_change'       => 10000, // Rial.
			'repricer_max_markup'       => 40.0, // Percent over base price (ceiling).
			'repricer_raise_when_alone' => true,
			'repricer_max_weekly_change' => 10.0, // Percent; protects promotion eligibility.

			// Promotions.
			'promo_auto_join'           => false,
			'promo_max_discount'        => 15.0,
			'promo_natures'             => array( 'incredible_offer', 'deal_of_the_day', 'mega_promotion' ),
			'promo_stock_share'         => 50, // Percent of stock offered in a promotion.
			'promo_order_limit'         => 2,

			// Notifications & advanced.
			'notify_email'              => '',
			'log_level'                 => 'info', // debug | info | warning | error.
			'log_retention_days'        => 30,
			'delete_data_on_uninstall'  => false,
			'onboarded'                 => false,
		);
	}

	/**
	 * Get all values merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function all() {
		if ( null === $this->values ) {
			$stored       = get_option( self::OPTION, array() );
			$this->values = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
		}
		return $this->values;
	}

	/**
	 * Get a single value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Update a set of values (sanitized according to default types).
	 *
	 * @param array<string,mixed> $values Values.
	 */
	public function update( array $values ) {
		$current  = $this->all();
		$defaults = self::defaults();
		foreach ( $values as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue;
			}
			$current[ $key ] = self::cast( $defaults[ $key ], $value );
		}
		update_option( self::OPTION, $current, false );
		$this->values = $current;
	}

	/**
	 * Resolve the source currency of WooCommerce prices.
	 *
	 * @return string toman|rial
	 */
	public function currency() {
		$currency = $this->get( 'currency' );
		if ( 'auto' !== $currency ) {
			return $currency;
		}
		$wc = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'IRT';
		return in_array( $wc, array( 'IRR' ), true ) ? 'rial' : 'toman';
	}

	/**
	 * Cast a raw value to the type of its default.
	 *
	 * @param mixed $default Default (type reference).
	 * @param mixed $value   Raw.
	 * @return mixed
	 */
	public static function cast( $default, $value ) {
		if ( is_bool( $default ) ) {
			return in_array( $value, array( true, 1, '1', 'yes', 'on', 'true' ), true );
		}
		if ( is_int( $default ) ) {
			return (int) $value;
		}
		if ( is_float( $default ) ) {
			return (float) str_replace( ',', '.', (string) $value );
		}
		if ( is_array( $default ) ) {
			if ( is_string( $value ) ) {
				$value = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			}
			return array_values( array_map( 'sanitize_key', (array) $value ) );
		}
		if ( is_string( $value ) && false !== strpos( $value, "\n" ) ) {
			return sanitize_textarea_field( $value );
		}
		return sanitize_text_field( (string) $value );
	}
}
