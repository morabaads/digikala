<?php
/**
 * Tests for repricing, promotion planning and stock planning.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Intelligence\PromotionPlanner;
use DKBridge\Intelligence\RepricingEngine;
use DKBridge\Intelligence\StockPlanner;
use PHPUnit\Framework\TestCase;

class IntelligenceTest extends TestCase {

	private function state( array $o = array() ) {
		return array_merge(
			array(
				'current'       => 10000000,
				'base'          => 9000000,
				'fixed_price'   => 10000000,
				'commission'    => 0.1,
				'buy_box_price' => 9800000,
				'is_winner'     => false,
				'in_challenge'  => true,
			),
			$o
		);
	}

	private function rule( array $o = array() ) {
		return array_merge(
			array(
				'strategy'           => 'undercut',
				'step'               => 10000,
				'min_change'         => 10000,
				'min_profit_percent' => 0,
				'max_markup_percent' => 40,
				'rounding_step'      => 1000,
			),
			$o
		);
	}

	public function test_floor_price_covers_commission_and_profit() {
		// 9,000,000 × 1.05 / 0.9 = 10,500,000.
		$this->assertSame( 10500000, RepricingEngine::floor_price( 9000000, 0.1, 5 ) );
	}

	public function test_undercut_buybox_winner() {
		$d = RepricingEngine::decide( $this->state( array( 'base' => 8000000 ) ), $this->rule() );
		$this->assertSame( 'update', $d['action'] );
		$this->assertSame( 9790000, $d['price'] );
		$this->assertSame( 8888889, $d['floor'] );

		// With a 9M base and 10% commission the floor is 10M: undercutting would lose money.
		$this->assertSame( 'keep', RepricingEngine::decide( $this->state(), $this->rule() )['action'] );
	}

	public function test_never_below_profit_floor() {
		$d = RepricingEngine::decide( $this->state( array( 'buy_box_price' => 8000000 ) ), $this->rule( array( 'min_profit_percent' => 5 ) ) );
		$this->assertSame( 10500000, $d['price'] );
		$this->assertStringContainsString( 'کف سود', $d['reason'] );
	}

	public function test_winner_keeps_price() {
		$d = RepricingEngine::decide( $this->state( array( 'is_winner' => true ) ), $this->rule() );
		$this->assertSame( 'keep', $d['action'] );
	}

	public function test_raise_to_ceiling_when_alone() {
		$d = RepricingEngine::decide( $this->state( array( 'in_challenge' => false ) ), $this->rule( array( 'raise_when_alone' => true, 'max_markup_percent' => 20 ) ) );
		$this->assertSame( 12000000, $d['price'] );
	}

	public function test_buybox_uses_digikala_suggestion_within_boundary() {
		$d = RepricingEngine::decide( $this->state( array( 'suggested' => 11000000, 'max_allowed' => 10800000 ) ), $this->rule( array( 'strategy' => 'buybox' ) ) );
		$this->assertSame( 10800000, $d['price'] );
		$this->assertSame( 10800000, $d['ceiling'] );
	}

	public function test_small_changes_are_skipped() {
		$d = RepricingEngine::decide( $this->state( array( 'buy_box_price' => 10015000 ) ), $this->rule( array( 'strategy' => 'undercut' ) ) );
		$this->assertSame( 'keep', $d['action'] );
	}

	public function test_fixed_strategy_uses_site_price() {
		$d = RepricingEngine::decide( $this->state( array( 'fixed_price' => 10500000 ) ), $this->rule( array( 'strategy' => 'fixed' ) ) );
		$this->assertSame( 10500000, $d['price'] );
	}

	public function test_promotion_plan_profitable() {
		$plan = PromotionPlanner::plan(
			array( 'selling_price' => 12345678, 'base' => 9000000, 'commission' => 0.08, 'stock' => 9 ),
			array( 'min_discount' => 10 ),
			array( 'max_discount' => 15, 'stock_share' => 50, 'order_limit' => 2 )
		);
		$this->assertTrue( $plan['join'] );
		$this->assertSame( 0, $plan['promotion_price'] % 100, 'ends with two zeros' );
		$this->assertSame( 11111100, $plan['promotion_price'] );
		$this->assertSame( 4, $plan['price_limit'] );
		$this->assertSame( 2, $plan['order_limit'] );
	}

	public function test_promotion_plan_rejects_unprofitable_or_too_deep() {
		$deep = PromotionPlanner::plan( array( 'selling_price' => 10000000, 'base' => 5000000, 'stock' => 5 ), array( 'min_discount' => 25 ), array( 'max_discount' => 15 ) );
		$this->assertFalse( $deep['join'] );
		$this->assertStringContainsString( 'سقف تخفیف', $deep['reason'] );

		$loss = PromotionPlanner::plan( array( 'selling_price' => 10000000, 'base' => 9500000, 'commission' => 0.1, 'stock' => 5 ), array( 'min_discount' => 10 ), array( 'max_discount' => 20 ) );
		$this->assertFalse( $loss['join'] );
		$this->assertStringContainsString( 'زیان‌ده', $loss['reason'] );

		$none = PromotionPlanner::plan( array( 'selling_price' => 10000000, 'stock' => 0 ), array(), array() );
		$this->assertFalse( $none['join'] );
	}

	public function test_velocity_blends_recent_trend() {
		$now   = strtotime( '2026-11-07 12:00:00 UTC' );
		$sales = array( '2026-11-06' => 7, '2026-10-20' => 3, '2026-09-01' => 100 );
		// 30-day avg = 10/30, last week = 7/7.
		$this->assertEqualsWithDelta( 0.5 * ( 10 / 30 ) + 0.5 * 1, StockPlanner::velocity( $sales, 30, $now ), 0.001 );
	}

	public function test_health_and_cover() {
		$this->assertSame( 'out', StockPlanner::health( 0, 1 ) );
		$this->assertSame( 'low', StockPlanner::health( 5, 1, 7 ) );
		$this->assertSame( 'ok', StockPlanner::health( 30, 1, 7 ) );
		$this->assertSame( 'overstock', StockPlanner::health( 500, 1, 7 ) );
		$this->assertSame( 'no_sales', StockPlanner::health( 5, 0 ) );
		$this->assertSame( 2.5, StockPlanner::days_of_cover( 5, 2 ) );
	}

	public function test_replenishment() {
		$r = StockPlanner::replenish( array( 'velocity' => 2, 'warehouse_stock' => 3, 'on_the_way' => 1, 'allowed' => 100, 'seller_available' => 40 ), array( 'cover_days' => 14, 'safety_days' => 4, 'inbound_days' => 2, 'min_qty' => 2 ) );
		$this->assertSame( 36, $r['qty'] ); // 2 × 20 − 4.
		$this->assertSame( 'critical', $r['urgency'] ); // cover 2 days ≤ inbound.

		$capped = StockPlanner::replenish( array( 'velocity' => 2, 'warehouse_stock' => 0, 'allowed' => 10, 'seller_available' => 40 ), array() );
		$this->assertSame( 10, $capped['qty'] );
		$this->assertStringContainsString( 'سقف مجاز', $capped['reason'] );

		$idle = StockPlanner::replenish( array( 'velocity' => 0, 'warehouse_stock' => 5 ), array() );
		$this->assertSame( 0, $idle['qty'] );
		$this->assertSame( 'none', $idle['urgency'] );
	}
}
