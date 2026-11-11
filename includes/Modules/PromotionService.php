<?php
/**
 * Promotion management: eligible campaigns, profitable join plans, auto-join,
 * smart (periodic) discounts.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Api\DryRunException;
use DKBridge\Data\ItemRepository;
use DKBridge\Data\VariantRepository;
use DKBridge\Intelligence\PromotionPlanner;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Settings;
use DKBridge\Support\Logger;
use DKBridge\Sync\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Promotion service.
 */
class PromotionService {

	/** @var Settings */
	private $settings;
	/** @var Digikala */
	private $api;
	/** @var VariantRepository */
	private $variants;
	/** @var ItemRepository */
	private $items;
	/** @var ProductMapper */
	private $mapper;
	/** @var Logger */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param Digikala          $api      API.
	 * @param VariantRepository $variants Variants.
	 * @param ItemRepository    $items    Items.
	 * @param ProductMapper     $mapper   Mapper.
	 * @param Logger            $logger   Logger.
	 */
	public function __construct( Settings $settings, Digikala $api, VariantRepository $variants, ItemRepository $items, ProductMapper $mapper, Logger $logger ) {
		$this->settings = $settings;
		$this->api      = $api;
		$this->variants = $variants;
		$this->items    = $items;
		$this->mapper   = $mapper;
		$this->logger   = $logger;
	}

	/**
	 * Eligible promotions (filtered to the configured natures).
	 *
	 * @param bool $exclude_joined Hide promotions already joined.
	 * @return array[]
	 */
	public function eligible( $exclude_joined = false ) {
		$natures = (array) $this->settings->get( 'promo_natures', array() );
		$search  = $exclude_joined ? array( 'excludeJoined' => true ) : array();
		if ( $natures ) {
			$search['nature'] = array_values( $natures );
		}
		$data = $this->api->eligible_promotions( $search );
		$rows = isset( $data['items'] ) ? (array) $data['items'] : array();
		return array_values(
			array_filter(
				$rows,
				static function ( $p ) {
					return empty( $p['join_deadline'] ) || strtotime( $p['join_deadline'] ) > time();
				}
			)
		);
	}

	/**
	 * Promotions the seller registered in.
	 *
	 * @return array[]
	 */
	public function mine() {
		$data = $this->api->my_promotions();
		return isset( $data['items'] ) ? (array) $data['items'] : array();
	}

	/**
	 * Commercial context of a Digikala variant (base cost and commission from WooCommerce).
	 *
	 * @param int $variant_id Variant.
	 * @return array{base:int,commission:float,stock:int,linked:bool}
	 */
	private function context( $variant_id ) {
		$snap = $this->variants->get( $variant_id );
		$out  = array(
			'base'       => 0,
			'commission' => (float) $this->settings->get( 'default_commission' ) / 100,
			'stock'      => $snap ? (int) $snap['selling_stock'] : 0,
			'linked'     => false,
			'fake'       => false,
		);
		if ( ! $snap || ! $snap['wc_product_id'] ) {
			return $out;
		}
		$out['fake'] = 'yes' === get_post_meta( (int) $snap['wc_product_id'], '_dkb_fake', true );
		$item        = $this->items->get( (int) $snap['wc_product_id'] );
		if ( $item && isset( $item['context']['commission'] ) ) {
			$out['commission'] = (float) $item['context']['commission'];
		}
		$product = wc_get_product( $snap['wc_variation_id'] ? (int) $snap['wc_variation_id'] : (int) $snap['wc_product_id'] );
		if ( $product ) {
			$calc          = $this->mapper->price( $product, array( 'commission' => $out['commission'] ) );
			$out['base']   = (int) $calc['base'];
			$out['linked'] = true;
		}
		return $out;
	}

	/**
	 * Build a join plan for a promotion.
	 *
	 * @param int   $promotion_id Promotion.
	 * @param float $desired      Desired discount (0 = minimum required).
	 * @return array{promotion:array,rows:array}
	 */
	public function plan( $promotion_id, $desired = 0.0 ) {
		$promo    = $this->api->promotion( $promotion_id );
		$schema   = isset( $promo['condition_schema'] ) ? (array) $promo['condition_schema'] : array();
		$min      = isset( $schema['min_discount_percent'] ) ? (float) $schema['min_discount_percent'] : 0.0;
		$max      = isset( $schema['max_discount_percent'] ) ? (float) $schema['max_discount_percent'] : 0.0;
		$policy   = array(
			'max_discount'       => (float) $this->settings->get( 'promo_max_discount', 15 ),
			'min_profit_percent' => (float) $this->settings->get( 'min_profit_percent' ),
			'stock_share'        => (int) $this->settings->get( 'promo_stock_share', 50 ),
			'order_limit'        => (int) $this->settings->get( 'promo_order_limit', 2 ),
			'desired_discount'   => (float) $desired,
		);
		$rows = array();
		for ( $page = 1; $page <= 5; $page++ ) {
			$data = $this->api->promotion_eligible_variants( $promotion_id, $page );
			foreach ( isset( $data['items'] ) ? (array) $data['items'] : array() as $v ) {
				$vid = (int) $v['product_variant_id'];
				$ctx = $this->context( $vid );
				$p   = PromotionPlanner::plan(
					array(
						'selling_price'   => isset( $v['selling_price'] ) ? (int) $v['selling_price'] : 0,
						'base'            => $ctx['base'],
						'commission'      => $ctx['commission'],
						'stock'           => $ctx['stock'],
						'already_in_same' => ! empty( $v['already_exist_in_same_promotion'] ),
						'fake'            => $ctx['fake'],
					),
					array(
						'min_discount' => $min,
						'max_discount' => $max,
						'nature'       => isset( $promo['nature'] ) ? (string) $promo['nature'] : '',
					),
					$policy
				);
				if ( ! $ctx['linked'] && $p['join'] ) {
					$p['reason'] .= ' (بدون اتصال به ووکامرس؛ کف سود بررسی نشد)';
				}
				$rows[] = array_merge(
					$p,
					array(
						'variant_id'    => $vid,
						'title'         => isset( $v['product_variant_title'] ) ? $v['product_variant_title'] : '',
						'selling_price' => isset( $v['selling_price'] ) ? (int) $v['selling_price'] : 0,
						'buy_box'       => isset( $v['buy_box_winner_price'] ) ? $v['buy_box_winner_price'] : null,
						'views'         => isset( $v['last_week_product_view_count'] ) ? (int) $v['last_week_product_view_count'] : 0,
						'sales'         => isset( $v['last_week_sales_count'] ) ? (int) $v['last_week_sales_count'] : 0,
						'hot'           => ! empty( $v['is_highly_demanded_product'] ),
						'linked'        => $ctx['linked'],
						'stock'         => $ctx['stock'],
					)
				);
			}
			$pages = isset( $data['pager']['total_pages'] ) ? (int) $data['pager']['total_pages'] : 1;
			if ( $page >= $pages ) {
				break;
			}
		}
		// Best candidates first: joinable, highly demanded, most sales.
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['join'] !== $b['join'] ) {
					return $a['join'] ? -1 : 1;
				}
				if ( $a['hot'] !== $b['hot'] ) {
					return $a['hot'] ? -1 : 1;
				}
				return $b['sales'] <=> $a['sales'];
			}
		);
		return array(
			'promotion' => $promo,
			'min'       => $min,
			'rows'      => $rows,
		);
	}

	/**
	 * Join selected rows.
	 *
	 * @param int   $promotion_id Promotion.
	 * @param array $rows         [{variant_id, promotion_price, order_limit, price_limit}].
	 * @return array{ok:int,errors:string[]}
	 */
	public function join( $promotion_id, array $rows ) {
		$data = array();
		foreach ( $rows as $r ) {
			$price = (int) ( floor( (int) $r['promotion_price'] / 100 ) * 100 );
			if ( $price < 1000 || empty( $r['variant_id'] ) ) {
				continue;
			}
			$data[] = array(
				'variant_id'      => (int) $r['variant_id'],
				'promotion_price' => $price,
				'order_limit'     => max( 1, (int) $r['order_limit'] ),
				'price_limit'     => max( 1, (int) $r['price_limit'] ),
			);
		}
		if ( ! $data ) {
			return array(
				'ok'     => 0,
				'errors' => array(),
			);
		}
		$ok     = 0;
		$errors = array();
		foreach ( array_chunk( $data, 50 ) as $chunk ) {
			$res = $this->api->join_promotion( $promotion_id, $chunk );
			// The API answers per variant; accept list or single object.
			$list = isset( $res[0] ) ? $res : ( isset( $res['items'] ) ? (array) $res['items'] : array( $res ) );
			foreach ( $list as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				if ( ! empty( $r['success'] ) || ( ! isset( $r['success'] ) && ! isset( $r['message'] ) ) ) {
					++$ok;
				} else {
					$errors[] = ( isset( $r['id'] ) ? $r['id'] . ': ' : '' ) . ( isset( $r['message'] ) ? $r['message'] : '?' );
				}
			}
		}
		$this->logger->info( 'promotions', sprintf( '%d تنوع به پروموشن %d پیوست.', $ok, $promotion_id ), array( 'errors' => $errors ) );
		return array(
			'ok'     => $ok,
			'errors' => $errors,
		);
	}

	/**
	 * Auto-join profitable variants to new eligible promotions.
	 *
	 * @return array{promotions:int,joined:int}
	 */
	public function auto_join() {
		if ( ! $this->settings->get( 'promo_auto_join' ) || ! Queue::lock( 'promo_auto', 3000 ) ) {
			return array(
				'promotions' => 0,
				'joined'     => 0,
			);
		}
		$promotions = 0;
		$joined     = 0;
		try {
			foreach ( array_slice( $this->eligible( true ), 0, 10 ) as $promo ) {
				$min = isset( $promo['promotion_min_discount'] ) ? (float) $promo['promotion_min_discount'] : 0;
				if ( $min > (float) $this->settings->get( 'promo_max_discount', 15 ) ) {
					continue;
				}
				$plan = $this->plan( (int) $promo['id'] );
				$rows = array_filter(
					$plan['rows'],
					static function ( $r ) {
						return $r['join'] && $r['linked'];
					}
				);
				if ( ! $rows ) {
					continue;
				}
				$res = $this->join( (int) $promo['id'], array_values( $rows ) );
				++$promotions;
				$joined += $res['ok'];
			}
		} catch ( DryRunException $e ) {
			$this->logger->info( 'promotions', 'Dry run: auto-join not sent.' );
		} catch ( ApiException $e ) {
			$this->logger->error( 'promotions', $e->full_message() );
		} finally {
			Queue::unlock( 'promo_auto' );
		}
		return array(
			'promotions' => $promotions,
			'joined'     => $joined,
		);
	}

	/**
	 * Create smart (periodic) discounts for variants.
	 *
	 * @param int[] $variant_ids Variants.
	 * @param float $discount    Percent.
	 * @param int   $hours       Duration.
	 * @return array{ok:int,skipped:array,response:array}
	 */
	public function smart_discount( array $variant_ids, $discount, $hours ) {
		$rows    = array();
		$skipped = array();
		$start   = time() + 10 * MINUTE_IN_SECONDS;
		$end     = $start + max( 1, (int) $hours ) * HOUR_IN_SECONDS;
		$policy  = array(
			'max_discount'       => 90,
			'min_profit_percent' => (float) $this->settings->get( 'min_profit_percent' ),
			'stock_share'        => (int) $this->settings->get( 'promo_stock_share', 50 ),
			'order_limit'        => (int) $this->settings->get( 'promo_order_limit', 2 ),
			'desired_discount'   => (float) $discount,
		);
		foreach ( array_unique( array_map( 'intval', $variant_ids ) ) as $vid ) {
			$snap = $this->variants->get( $vid );
			$ctx  = $this->context( $vid );
			$plan = PromotionPlanner::plan(
				array(
					'selling_price' => $snap ? (int) $snap['price_sale'] : 0,
					'base'          => $ctx['base'],
					'commission'    => $ctx['commission'],
					'stock'         => $ctx['stock'],
				),
				array( 'min_discount' => (float) $discount ),
				$policy
			);
			if ( ! $plan['join'] ) {
				$skipped[ $vid ] = $plan['reason'];
				continue;
			}
			$rows[] = array(
				'variantId'      => $vid,
				'promotionPrice' => $plan['promotion_price'],
				'limit'          => $plan['price_limit'],
				'orderLimit'     => $plan['order_limit'],
				'startAt'        => gmdate( 'Y-m-d\TH:i:s\Z', $start ),
				'endAt'          => gmdate( 'Y-m-d\TH:i:s\Z', $end ),
			);
		}
		$res = $rows ? $this->api->create_smart_discounts( $rows ) : array();
		return array(
			'ok'       => count( $rows ),
			'skipped'  => $skipped,
			'response' => $res,
		);
	}
}
