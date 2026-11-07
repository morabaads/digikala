<?php
/**
 * Service container & bootstrap.
 *
 * @package DKBridge
 */

namespace DKBridge;

use DKBridge\Api\Client;
use DKBridge\Api\Digikala;
use DKBridge\Api\TokenStore;
use DKBridge\Data\ItemRepository;
use DKBridge\Mapping\AttributeMapper;
use DKBridge\Mapping\CategoryMapper;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Support\Logger;
use DKBridge\Sync\InventorySync;
use DKBridge\Sync\OrderSync;
use DKBridge\Sync\Publisher;
use DKBridge\Sync\Queue;
use DKBridge\Sync\StatusPoller;
use DKBridge\Sync\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Lazy container.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Services.
	 *
	 * @var array
	 */
	private $services = array();

	/**
	 * Singleton.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string   $id      Id.
	 * @param callable $factory Factory.
	 * @return mixed
	 */
	private function service( $id, $factory ) {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		return $this->services[ $id ];
	}

	/** @return Settings */
	public function settings() {
		return $this->service(
			'settings',
			static function () {
				return new Settings();
			}
		);
	}

	/** @return Logger */
	public function logger() {
		$settings = $this->settings();
		return $this->service(
			'logger',
			static function () use ( $settings ) {
				return new Logger( (string) $settings->get( 'log_level', 'info' ) );
			}
		);
	}

	/** @return TokenStore */
	public function tokens() {
		return $this->service(
			'tokens',
			static function () {
				return new TokenStore();
			}
		);
	}

	/** @return Digikala */
	public function api() {
		$self = $this;
		return $this->service(
			'api',
			static function () use ( $self ) {
				return new Digikala( new Client( $self->settings(), $self->tokens(), $self->logger() ) );
			}
		);
	}

	/** @return ItemRepository */
	public function items() {
		return $this->service(
			'items',
			static function () {
				return new ItemRepository();
			}
		);
	}

	/** @return CategoryMapper */
	public function categories() {
		return $this->service(
			'categories',
			static function () {
				return new CategoryMapper();
			}
		);
	}

	/** @return AttributeMapper */
	public function attributes() {
		return $this->service(
			'attributes',
			static function () {
				return new AttributeMapper();
			}
		);
	}

	/** @return ProductMapper */
	public function mapper() {
		$self = $this;
		return $this->service(
			'mapper',
			static function () use ( $self ) {
				return new ProductMapper( $self->settings(), $self->is_connected() ? $self->api() : null, $self->categories(), $self->attributes() );
			}
		);
	}

	/** @return Publisher */
	public function publisher() {
		$self = $this;
		return $this->service(
			'publisher',
			static function () use ( $self ) {
				return new Publisher( $self->settings(), $self->api(), $self->mapper(), $self->items(), $self->logger() );
			}
		);
	}

	/** @return InventorySync */
	public function inventory() {
		$self = $this;
		return $this->service(
			'inventory',
			static function () use ( $self ) {
				return new InventorySync( $self->settings(), $self->api(), $self->items(), $self->mapper(), $self->logger() );
			}
		);
	}

	/** @return OrderSync */
	public function orders() {
		$self = $this;
		return $this->service(
			'orders',
			static function () use ( $self ) {
				return new OrderSync( $self->settings(), $self->api(), $self->items(), $self->logger() );
			}
		);
	}

	/** @return StatusPoller */
	public function poller() {
		$self = $this;
		return $this->service(
			'poller',
			static function () use ( $self ) {
				return new StatusPoller( $self->settings(), $self->api(), $self->items(), $self->logger() );
			}
		);
	}

	/**
	 * Whether a token is configured.
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->tokens()->has_token();
	}

	/**
	 * Wire hooks.
	 */
	public function boot() {
		Installer::maybe_upgrade();

		add_filter( 'cron_schedules', array( Queue::class, 'cron_schedules' ) );
		add_action( 'init', array( $this, 'on_init' ) );

		// Jobs.
		add_action( 'dkb_publish_item', array( $this, 'job_publish' ) );
		add_action( 'dkb_create_variants', array( $this, 'job_variants' ) );
		add_action( 'dkb_flush_stock', array( $this, 'job_flush_stock' ) );
		add_action( 'dkb_flush_prices', array( $this, 'job_flush_prices' ) );
		add_action( 'dkb_check_batches', array( $this, 'job_check_batches' ) );
		add_action( 'dkb_poll_moderation', array( $this, 'job_poll' ) );
		add_action( 'dkb_pull_orders', array( $this, 'job_orders' ) );
		add_action( 'dkb_reconcile', array( $this, 'job_reconcile' ) );
		add_action( 'dkb_cleanup', array( $this, 'job_cleanup' ) );

		$this->inventory()->register();

		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'before_delete_post', array( $this, 'on_delete_post' ) );

		if ( is_admin() ) {
			( new Admin\Admin( $this ) )->register();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'dkb', new Cli\Command( $this ) );
		}
	}

	/**
	 * Init.
	 */
	public function on_init() {
		if ( $this->is_connected() ) {
			Queue::ensure_recurring();
		}
	}

	/**
	 * REST routes.
	 */
	public function register_rest() {
		( new WebhookController( $this->settings(), $this->items(), $this->poller(), $this->orders(), $this->logger() ) )->register_routes();
		( new Admin\RestController( $this ) )->register_routes();
	}

	/**
	 * Clean up mapping rows when a product is deleted.
	 *
	 * @param int $post_id Post.
	 */
	public function on_delete_post( $post_id ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$this->items()->delete_product( $post_id );
		}
	}

	/**
	 * Job: publish.
	 *
	 * @param int $product_id Product.
	 */
	public function job_publish( $product_id ) {
		$this->publisher()->run( (int) $product_id );
	}

	/**
	 * Job: create variants.
	 *
	 * @param int $product_id Product.
	 */
	public function job_variants( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		$item    = $this->items()->get( (int) $product_id );
		if ( ! $product || ! $item || ! $item['dk_product_id'] ) {
			return;
		}
		try {
			$this->publisher()->create_variants( $product, $item );
		} catch ( Api\ApiException $e ) {
			$this->items()->update( $item['id'], array( 'last_error' => $e->full_message() ) );
			if ( $e->is_transient() ) {
				Queue::push( 'dkb_create_variants', array( (int) $product_id ), max( 300, $e->retry_after() ) );
			}
			$this->logger()->error( 'variants', $e->full_message(), array(), (int) $product_id );
		}
	}

	/** Job. */
	public function job_flush_stock() {
		if ( $this->is_connected() ) {
			$this->inventory()->flush_stock();
		}
	}

	/** Job. */
	public function job_flush_prices() {
		if ( $this->is_connected() ) {
			$this->inventory()->flush_prices();
		}
	}

	/** Job. */
	public function job_check_batches() {
		if ( $this->is_connected() ) {
			$this->inventory()->check_batches();
		}
	}

	/** Job. */
	public function job_poll() {
		if ( $this->is_connected() ) {
			$this->poller()->poll();
		}
	}

	/** Job. */
	public function job_orders() {
		if ( $this->is_connected() ) {
			$this->orders()->pull();
		}
	}

	/** Job. */
	public function job_reconcile() {
		if ( $this->is_connected() ) {
			$this->items()->mark_all_live_dirty();
		}
	}

	/** Job. */
	public function job_cleanup() {
		Support\Logger::cleanup( (int) $this->settings()->get( 'log_retention_days', 30 ) );
	}
}
