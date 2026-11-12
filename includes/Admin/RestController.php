<?php
/**
 * REST endpoints used by the admin UI.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Mapping\AttributeMapper;
use DKBridge\Mapping\BrandResolver;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Plugin;
use DKBridge\Rules\Issue;

defined( 'ABSPATH' ) || exit;

/**
 * Namespace dk-bridge/v1/admin.
 */
class RestController {

	const NS = 'dk-bridge/v1';

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
	 * Capability check.
	 *
	 * @return bool
	 */
	public function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$routes = array(
			array( '/admin/connection/test', 'POST', 'connection_test' ),
			array( '/admin/categories/search', 'GET', 'categories_search' ),
			array( '/admin/categories/suggest', 'GET', 'categories_suggest' ),
			array( '/admin/categories/children', 'GET', 'categories_children' ),
			array( '/admin/category-map', 'POST', 'category_map_save' ),
			array( '/admin/category-map/(?P<term>\d+)', 'DELETE', 'category_map_delete' ),
			array( '/admin/attributes/(?P<category>\d+)', 'GET', 'attributes_get' ),
			array( '/admin/attributes/(?P<category>\d+)', 'POST', 'attributes_save' ),
			array( '/admin/brands/search', 'GET', 'brands_search' ),
			array( '/admin/products/(?P<id>\d+)/validate', 'POST', 'product_validate' ),
			array( '/admin/products/(?P<id>\d+)/autofix', 'POST', 'product_autofix' ),
			array( '/admin/products/(?P<id>\d+)/push', 'POST', 'product_push' ),
			array( '/admin/products/(?P<id>\d+)/push-now', 'POST', 'product_push_now' ),
			array( '/admin/products/(?P<id>\d+)/preview', 'GET', 'product_preview' ),
			array( '/admin/products/(?P<id>\d+)/match', 'POST', 'product_match' ),
			array( '/admin/products/(?P<id>\d+)/status', 'GET', 'product_status' ),
			array( '/admin/products/(?P<id>\d+)/reset', 'POST', 'product_reset' ),
			array( '/admin/bulk', 'POST', 'bulk' ),
			array( '/admin/sync/(?P<what>stock|prices|orders|moderation|batches)', 'POST', 'sync_now' ),
			array( '/admin/cache/flush', 'POST', 'cache_flush' ),
			array( '/admin/webhook/subscribe', 'POST', 'webhook_subscribe' ),
			array( '/admin/variants/config/(?P<dkp>\d+)', 'GET', 'variant_config' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( $this, 'dispatch' ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => array(),
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
			return new \WP_REST_Response(
				array(
					'message' => $e->full_message(),
					'status'  => $e->http_status(),
				),
				$e->http_status() >= 400 && $e->http_status() < 600 ? $e->http_status() : 502
			);
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response( array( 'message' => $e->getMessage() ), 500 );
		}
	}

	/**
	 * Product or error.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WC_Product
	 * @throws \RuntimeException When missing.
	 */
	private function product( $request ) {
		$product = wc_get_product( (int) $request['id'] );
		if ( ! $product ) {
			throw new \RuntimeException( __( 'محصول پیدا نشد.', 'dk-bridge' ) );
		}
		return $product;
	}

	/**
	 * Test the connection.
	 *
	 * @return array
	 */
	private function connection_test() {
		$settings = $this->plugin->settings();
		if ( '' === trim( (string) $settings->get( 'seller_code' ) ) ) {
			$found = \DKBridge\Api\TokenStore::seller_code_from_token( $this->plugin->tokens()->access_token() );
			if ( '' !== $found ) {
				$settings->update( array( 'seller_code' => $found ) );
				$this->plugin->logger()->info( 'auth', 'کد فروشنده از توکن خوانده شد: ' . $found );
			} elseif ( 'self_token' === $settings->get( 'auth_mode' ) ) {
				throw new \RuntimeException( __( 'کد فروشنده وارد نشده است. دیجی‌کالا برای توکن اختصاصی هدر DigikalaTrustedBot/SelfToken/{کد فروشنده} می‌خواهد. کد فروشنده را از پنل فروشندگان (کنار نام فروشگاه یا منوی پروفایل) بردارید و در تنظیمات ← اتصال وارد کنید.', 'dk-bridge' ) );
			}
		}
		$api    = $this->plugin->api();
		$health = $api->health();
		$out    = array(
			'ok'     => true,
			'health' => $health,
		);
		try {
			$profile        = $api->profile();
			$out['profile'] = array(
				'name' => isset( $profile['store']['name'] ) ? $profile['store']['name'] : ( isset( $profile['business_name'] ) ? $profile['business_name'] : '' ),
				'raw'  => array_slice( $profile, 0, 8, true ),
			);
		} catch ( ApiException $e ) {
			$out['profile_error'] = $e->full_message();
		}
		try {
			$api->category_children();
			$out['product_scope'] = true;
		} catch ( ApiException $e ) {
			$out['product_scope'] = false;
			$out['scope_error']   = $e->full_message();
		}
		update_option( 'dkb_last_connection', array_merge( $out, array( 'at' => time() ) ), false );
		return $out;
	}

	/**
	 * Category search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function categories_search( $request ) {
		$q = trim( (string) $request['q'] );
		return '' === $q ? array() : $this->plugin->api()->search_categories( $q );
	}

	/**
	 * Suggestions for a keyword (or for a WooCommerce term's products).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function categories_suggest( $request ) {
		$q = trim( (string) $request['q'] );
		if ( '' === $q && $request['term'] ) {
			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'product_cat',
							'terms'    => (int) $request['term'],
						),
					),
				)
			);
			$q = $ids ? get_the_title( $ids[0] ) : '';
			if ( '' === $q ) {
				$term = get_term( (int) $request['term'], 'product_cat' );
				$q    = $term && ! is_wp_error( $term ) ? $term->name : '';
			}
		}
		if ( '' === $q ) {
			return array();
		}
		$out = array();
		foreach ( $this->plugin->api()->suggest( $q ) as $row ) {
			foreach ( isset( $row['categories'] ) ? (array) $row['categories'] : array() as $c ) {
				if ( isset( $c['category']['id'] ) ) {
					$out[ $c['category']['id'] ] = array(
						'id'      => (int) $c['category']['id'],
						'text'    => isset( $c['category']['title_fa'] ) ? $c['category']['title_fa'] : '',
						'keyword' => isset( $c['keyword'] ) ? $c['keyword'] : '',
						'leaf'    => true,
					);
				}
			}
		}
		return array(
			'keyword' => $q,
			'items'   => array_values( $out ),
		);
	}

	/**
	 * Tree children.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function categories_children( $request ) {
		$data = $this->plugin->api()->category_children( $request['parent'] ? (int) $request['parent'] : null );
		return isset( $data['items'] ) ? $data['items'] : array();
	}

	/**
	 * Save a category mapping (validates the category first).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function category_map_save( $request ) {
		$term  = (int) $request['term_id'];
		$dk    = (int) $request['dk_category_id'];
		$title = sanitize_text_field( (string) $request['title'] );
		if ( ! $term || ! $dk ) {
			throw new \RuntimeException( __( 'دسته یا گروه نامعتبر است.', 'dk-bridge' ) );
		}
		$theme  = '';
		$valid  = null;
		$errors = array();
		try {
			$v      = $this->plugin->api()->category_validation( $dk );
			$valid  = ! empty( $v['isValid'] );
			$errors = isset( $v['errors'] ) ? array_map( 'wp_strip_all_tags', (array) $v['errors'] ) : array();
			if ( isset( $v['bind']['category_data']['categoryTheme'] ) ) {
				$theme = (string) $v['bind']['category_data']['categoryTheme'];
			}
			if ( '' === $title && isset( $v['bind']['category_data']['categoryTitle'] ) ) {
				$title = (string) $v['bind']['category_data']['categoryTitle'];
			}
		} catch ( ApiException $e ) {
			$errors[] = $e->full_message();
		}
		$this->plugin->categories()->set( $term, $dk, $title, $theme );
		return array(
			'saved'  => true,
			'valid'  => $valid,
			'errors' => $errors,
			'theme'  => $theme,
			'title'  => $title,
		);
	}

	/**
	 * Delete mapping.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function category_map_delete( $request ) {
		$this->plugin->categories()->delete( (int) $request['term'] );
		return array( 'deleted' => true );
	}

	/**
	 * Attributes of a DK category + current mapping + WooCommerce attribute choices.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function attributes_get( $request ) {
		$cat  = (int) $request['category'];
		$flat = AttributeMapper::flatten( $this->plugin->api()->category_attributes( $cat ) );
		$woo  = array();
		foreach ( wc_get_attribute_taxonomies() as $tax ) {
			$woo[] = array(
				'key'   => wc_attribute_taxonomy_name( $tax->attribute_name ),
				'label' => $tax->attribute_label,
			);
		}
		return array(
			'attributes' => array_values( $flat['defs'] ),
			'meta'       => $flat['meta'],
			'mapping'    => $this->plugin->attributes()->for_category( $cat ),
			'woo'        => $woo,
		);
	}

	/**
	 * Save attribute mappings.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function attributes_save( $request ) {
		$cat   = (int) $request['category'];
		$items = (array) $request['items'];
		foreach ( $items as $row ) {
			$value_map = array();
			if ( ! empty( $row['value_map'] ) && is_array( $row['value_map'] ) ) {
				foreach ( $row['value_map'] as $k => $v ) {
					if ( '' !== (string) $v ) {
						$value_map[ sanitize_text_field( (string) $k ) ] = sanitize_text_field( (string) $v );
					}
				}
			}
			$this->plugin->attributes()->save( $cat, (int) $row['attribute_id'], sanitize_key( (string) $row['source_type'] ), (string) $row['source_key'], $value_map );
		}
		return array( 'saved' => count( $items ) );
	}

	/**
	 * Brand search.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function brands_search( $request ) {
		$q     = trim( (string) $request['q'] );
		$items = $this->plugin->api()->search_brands( $q, preg_match( '/[\x{0600}-\x{06FF}]/u', $q ) ? 'fa' : 'en' );
		$out   = array();
		foreach ( $items as $name => $b ) {
			if ( is_array( $b ) && ! empty( $b['id'] ) ) {
				$out[] = array(
					'id'      => (int) $b['id'],
					'name_fa' => isset( $b['name_fa'] ) ? $b['name_fa'] : (string) $name,
					'name_en' => isset( $b['name_en'] ) ? $b['name_en'] : '',
					'logo'    => isset( $b['logo'] ) ? $b['logo'] : '',
				);
			}
		}
		if ( $request['remember'] && $request['text'] && $out ) {
			BrandResolver::remember( (string) $request['text'], array( 'id' => $out[0]['id'], 'fa' => $out[0]['name_fa'], 'en' => $out[0]['name_en'], 'misc' => false ) );
		}
		return $out;
	}

	/**
	 * Format an evaluation for the UI.
	 *
	 * @param array $eval Evaluation.
	 * @return array
	 */
	private static function format( array $eval ) {
		$d = $eval['data'];
		return array(
			'score'    => $eval['result']['score'],
			'blocking' => $eval['result']['blocking'],
			'changes'  => $eval['result']['changes'],
			'issues'   => array_map(
				static function ( Issue $i ) {
					return $i->to_array();
				},
				$eval['result']['issues']
			),
			'data'     => array(
				'title_fa'       => $d->title_fa,
				'title_en'       => $d->title_en,
				'model'          => $d->model,
				'brand'          => array( 'id' => $d->brand_id, 'fa' => $d->brand_fa, 'en' => $d->brand_en, 'misc' => $d->brand_misc, 'source' => $d->brand_source ),
				'category'       => array( 'id' => $d->category_id, 'title' => $d->category_title, 'theme' => $d->category_theme, 'valid' => $d->category_valid ),
				'description'    => $d->description,
				'advantages'     => $d->advantages,
				'disadvantages'  => $d->disadvantages,
				'price'          => $d->price,
				'price_info'     => $d->price_info,
				'stock'          => $d->stock,
				'package'        => $d->package,
				'images'         => count( $d->images ),
				'variations'     => count( $d->variations ),
				'product_types'  => $d->available_product_types,
				'fake'           => $d->fake,
			),
		);
	}

	/**
	 * Validate.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_validate( $request ) {
		$eval = $this->plugin->publisher()->evaluate( $this->product( $request ), false );
		return self::format( $eval );
	}

	/**
	 * Auto-fix and persist the fixed content as overrides.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_autofix( $request ) {
		$product = $this->product( $request );
		$eval    = $this->plugin->publisher()->evaluate( $product, true );
		ProductMapper::persist_overrides( $product, $eval['data'] );
		// Re-evaluate from the stored values so the score reflects what will be sent.
		$after = $this->plugin->publisher()->evaluate( wc_get_product( $product->get_id() ), false );
		$out   = self::format( $after );
		$out['changes'] = $eval['result']['changes'];
		return $out;
	}

	/**
	 * Queue a push.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_push( $request ) {
		$product = $this->product( $request );
		$this->plugin->publisher()->enqueue( $product->get_id() );
		return array( 'queued' => true );
	}

	/**
	 * Push synchronously (single product, from the meta box).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_push_now( $request ) {
		$product = $this->product( $request );
		$item    = $this->plugin->publisher()->run( $product->get_id() );
		return self::item_view( $item );
	}

	/**
	 * Preview payloads.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_preview( $request ) {
		return $this->plugin->publisher()->preview( $this->product( $request ) );
	}

	/**
	 * Decide a match.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_match( $request ) {
		$product  = $this->product( $request );
		$decision = (string) $request['decision'];
		$this->plugin->publisher()->decide_match( $product->get_id(), 'new' === $decision ? 'new' : (int) $decision );
		return array( 'queued' => true );
	}

	/**
	 * Item status.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_status( $request ) {
		$product = $this->product( $request );
		$item    = $this->plugin->items()->get( $product->get_id() );
		if ( $item && 'in_review' === $item['state'] && $request['refresh'] ) {
			$this->plugin->poller()->refresh( $item );
			$item = $this->plugin->items()->get( $product->get_id() );
		}
		return self::item_view( $item );
	}

	/**
	 * Forget the Digikala link of a product.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function product_reset( $request ) {
		$product = $this->product( $request );
		$this->plugin->items()->delete_product( $product->get_id() );
		return array( 'reset' => true );
	}

	/**
	 * Public item view.
	 *
	 * @param array|null $item Item.
	 * @return array
	 */
	public static function item_view( $item ) {
		if ( ! $item ) {
			return array( 'state' => 'local' );
		}
		$labels = \DKBridge\Data\ItemRepository::STATES;
		$ctx    = (array) $item['context'];
		return array(
			'state'             => $item['state'],
			'state_label'       => isset( $labels[ $item['state'] ] ) ? $labels[ $item['state'] ] : $item['state'],
			'dk_product_id'     => $item['dk_product_id'],
			'dk_variant_id'     => $item['dk_variant_id'],
			'dk_draft_id'       => $item['dk_draft_id'],
			'moderation_status' => $item['moderation_status'],
			'score'             => null === $item['score'] ? null : (int) $item['score'],
			'last_error'        => $item['last_error'],
			'match_candidates'  => isset( $ctx['match_candidates'] ) ? $ctx['match_candidates'] : array(),
			'ai_hints'          => isset( $ctx['ai_hints'] ) ? $ctx['ai_hints'] : array(),
			'dry_run'           => isset( $ctx['dry_run'] ) ? $ctx['dry_run'] : null,
			'title_hint'        => isset( $ctx['title_hint'] ) ? $ctx['title_hint'] : '',
			'updated_at'        => $item['updated_at'],
		);
	}

	/**
	 * Bulk actions.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function bulk( $request ) {
		$action = sanitize_key( (string) $request['action'] );
		$ids    = array_slice( array_filter( array_map( 'intval', (array) $request['ids'] ) ), 0, 500 );
		$done   = 0;
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			switch ( $action ) {
				case 'validate':
					$this->plugin->publisher()->evaluate( $product, false );
					break;
				case 'autofix':
					$eval = $this->plugin->publisher()->evaluate( $product, true );
					ProductMapper::persist_overrides( $product, $eval['data'] );
					$this->plugin->publisher()->evaluate( wc_get_product( $id ), false );
					break;
				case 'push':
					$this->plugin->publisher()->enqueue( $id );
					break;
				case 'sync':
					$this->plugin->items()->mark_dirty( $id, -1, 'both' );
					break;
				case 'exclude':
					update_post_meta( $id, '_dkb_exclude', 'yes' );
					break;
				case 'include':
					delete_post_meta( $id, '_dkb_exclude' );
					break;
				case 'reset':
					$this->plugin->items()->delete_product( $id );
					break;
				default:
					throw new \RuntimeException( 'Unknown action' );
			}
			++$done;
		}
		return array( 'done' => $done );
	}

	/**
	 * Run a sync job now.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function sync_now( $request ) {
		switch ( $request['what'] ) {
			case 'stock':
				return array( 'count' => $this->plugin->inventory()->flush_stock() );
			case 'prices':
				return array( 'count' => $this->plugin->inventory()->flush_prices() );
			case 'orders':
				return array( 'count' => $this->plugin->orders()->pull() );
			case 'batches':
				$this->plugin->inventory()->check_batches();
				return array( 'count' => 0 );
			default:
				return array( 'count' => $this->plugin->poller()->poll() );
		}
	}

	/**
	 * Flush API cache.
	 *
	 * @return array
	 */
	private function cache_flush() {
		Digikala::flush_cache();
		delete_option( BrandResolver::OPTION );
		return array( 'flushed' => true );
	}

	/**
	 * Subscribe webhooks.
	 *
	 * @return array
	 */
	private function webhook_subscribe() {
		$events = (array) $this->plugin->settings()->get( 'webhook_events', array() );
		$api    = $this->plugin->api();
		$res    = $api->webhook_subscribe( $events );
		$api->webhook_activation( true );
		return array(
			'result' => $res,
			'events' => $api->webhook_events(),
		);
	}

	/**
	 * Variant config (e.g. warranty options) for a product.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return array
	 */
	private function variant_config( $request ) {
		return $this->plugin->api()->variant_config( (int) $request['dkp'] );
	}
}
