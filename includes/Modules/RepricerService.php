<?php
/**
 * Smart pricing: buy-box repricer with profit floors and price history.
 *
 * @package DKBridge
 */

namespace DKBridge\Modules;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Api\DryRunException;
use DKBridge\Data\ItemRepository;
use DKBridge\Data\VariantRepository;
use DKBridge\Intelligence\RepricingEngine;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Settings;
use DKBridge\Support\Logger;
use DKBridge\Sync\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Repricer.
 */
class RepricerService {

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
	 * Effective strategy of a WooCommerce product.
	 *
	 * @param Settings $settings   Settings.
	 * @param int      $product_id Parent product id.
	 * @return string
	 */
	public static function strategy_for( Settings $settings, $product_id ) {
		if ( ! $settings->get( 'repricer_enabled' ) ) {
			return 'fixed';
		}
		$own = (string) get_post_meta( (int) $product_id, '_dkb_price_strategy', true );
		if ( '' !== $own && isset( RepricingEngine::STRATEGIES[ $own ] ) ) {
			return $own;
		}
		$global = (string) $settings->get( 'repricer_strategy', 'fixed' );
		return isset( RepricingEngine::STRATEGIES[ $global ] ) ? $global : 'fixed';
	}

	/**
	 * Commission ratio for a product (stored when variants were created, else default).
	 *
	 * @param int $product_id Product.
	 * @return float
	 */
	private function commission( $product_id ) {
		$item = $this->items->get( (int) $product_id );
		if ( $item && isset( $item['context']['commission'] ) ) {
			return (float) $item['context']['commission'];
		}
		return (float) $this->settings->get( 'default_commission' ) / 100;
	}

	/**
	 * Compute a decision for one snapshot row.
	 *
	 * @param array $v        Snapshot row.
	 * @param bool  $live     Fetch live suggestions from Digikala.
	 * @param string|null $force_strategy Override strategy.
	 * @return array|null Decision + context, or null when not applicable.
	 */
	public function evaluate( array $v, $live = true, $force_strategy = null ) {
		if ( ! $v['wc_product_id'] ) {
			return null;
		}
		$product = wc_get_product( $v['wc_variation_id'] ? (int) $v['wc_variation_id'] : (int) $v['wc_product_id'] );
		if ( ! $product ) {
			return null;
		}
		$strategy   = $force_strategy ? $force_strategy : self::strategy_for( $this->settings, (int) $v['wc_product_id'] );
		$commission = $this->commission( (int) $v['wc_product_id'] );
		$calc       = $this->mapper->price( $product, array( 'commission' => $commission ) );
		$id         = (int) $v['dk_variant_id'];

		$state = array(
			'current'       => (int) $v['price_sale'],
			'base'          => (int) $calc['base'],
			'fixed_price'   => (int) $calc['price'],
			'commission'    => $commission,
			'buy_box_price' => null !== $v['buy_box_price'] ? (int) $v['buy_box_price'] : null,
			'is_winner'     => (bool) $v['is_buy_box_winner'],
			'in_challenge'  => (bool) $v['in_challenge'],
			'week_ref'      => (int) $v['price_sale'],
		);
		$week_ago = $this->variants->price_week_ago( (int) $v['dk_variant_id'] );
		if ( null !== $week_ago ) {
			$state['week_ref'] = $week_ago;
		}
		if ( $live ) {
			try {
				$b = $this->api->price_boundary( $id );
				if ( isset( $b['default_price_boundary'] ) ) {
					$state['min_allowed'] = (int) $b['default_price_boundary']['min_allowed_price'];
					$state['max_allowed'] = (int) $b['default_price_boundary']['max_allowed_price'];
				}
				if ( 'buybox' === $strategy && ( ! $state['is_winner'] || ! $state['in_challenge'] ) ) {
					$state['suggested'] = $this->api->winning_price( $id );
				} elseif ( 'rank' === $strategy ) {
					$state['rank_price'] = $this->api->rank_price( $id, (int) $this->settings->get( 'repricer_rank', 2 ) );
				}
			} catch ( ApiException $e ) {
				$this->logger->debug( 'repricer', $e->getMessage() );
			}
		}
		$parent = (int) $v['wc_product_id'];
		$rule   = array(
			'strategy'           => $strategy,
			'step'               => (int) $this->settings->get( 'repricer_step' ),
			'min_change'         => (int) $this->settings->get( 'repricer_min_change' ),
			'min_profit_percent' => (float) $this->settings->get( 'min_profit_percent' ),
			'max_markup_percent' => (float) $this->settings->get( 'repricer_max_markup' ),
			'raise_when_alone'   => (bool) $this->settings->get( 'repricer_raise_when_alone' ),
			'floor'              => (int) get_post_meta( $parent, '_dkb_price_floor', true ),
			'ceiling'            => (int) get_post_meta( $parent, '_dkb_price_ceiling', true ),
			'rounding_step'      => max( 100, min( 10000, (int) $this->settings->get( 'rounding_step' ) ) ),
			'max_weekly_change'  => (float) $this->settings->get( 'repricer_max_weekly_change', 10 ),
		);
		$decision = RepricingEngine::decide( $state, $rule );
		return array_merge(
			$decision,
			array(
				'variant_id' => $id,
				'strategy'   => $strategy,
				'current'    => $state['current'],
				'buy_box'    => $state['buy_box_price'],
				'is_winner'  => $state['is_winner'],
				'base'       => $state['base'],
			)
		);
	}

	/**
	 * Run the repricer for all linked variants not on the fixed strategy.
	 *
	 * @param bool $apply Send changes.
	 * @return array{checked:int,updated:int,decisions:array}
	 */
	public function run( $apply = true ) {
		if ( ! $this->settings->get( 'repricer_enabled' ) || ! Queue::lock( 'repricer', 1700 ) ) {
			return array(
				'checked'   => 0,
				'updated'   => 0,
				'decisions' => array(),
			);
		}
		$checked   = 0;
		$updates   = array();
		$decisions = array();
		try {
			foreach ( $this->variants->linked() as $v ) {
				if ( ! (int) $v['active'] || (int) $v['in_promotion'] || 'fixed' === self::strategy_for( $this->settings, (int) $v['wc_product_id'] ) ) {
					continue;
				}
				$d = $this->evaluate( $v, true );
				if ( ! $d ) {
					continue;
				}
				++$checked;
				$decisions[] = $d;
				if ( 'update' === $d['action'] ) {
					$updates[] = $d;
				}
			}
			if ( $apply && $updates ) {
				foreach ( array_chunk( $updates, 100 ) as $chunk ) {
					$items = array();
					foreach ( $chunk as $d ) {
						$items[] = array(
							'variant_id' => $d['variant_id'],
							'payload'    => array( 'selling_price' => $d['price'] ),
						);
					}
					$this->api->batch_update( $items );
					foreach ( $chunk as $d ) {
						$this->variants->update( $d['variant_id'], array( 'price_sale' => $d['price'] ) );
						$this->variants->log_price( $d['variant_id'], $d['current'], $d['price'], (int) $d['buy_box'], $d['strategy'], $d['reason'] );
					}
				}
				$this->logger->info( 'repricer', sprintf( 'قیمت %d تنوع از %d بررسی‌شده تغییر کرد.', count( $updates ), $checked ) );
			}
		} catch ( DryRunException $e ) {
			$this->logger->info( 'repricer', 'Dry run: price changes not sent.', array( 'count' => count( $updates ) ) );
		} catch ( ApiException $e ) {
			$this->logger->error( 'repricer', $e->full_message() );
		} finally {
			Queue::unlock( 'repricer' );
		}
		return array(
			'checked'   => $checked,
			'updated'   => $apply ? count( $updates ) : 0,
			'decisions' => $decisions,
		);
	}
}
