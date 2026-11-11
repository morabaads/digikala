<?php
/**
 * Tests for rules encoded from Digikala's official seller FAQ.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Intelligence\LogisticsRules;
use DKBridge\Intelligence\PromotionPlanner;
use DKBridge\Intelligence\RepricingEngine;
use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class RulebookTest extends TestCase {

	public function test_depot_allowance_faq_365() {
		$this->assertFalse( LogisticsRules::depot_allowance( 49 )['eligible'] );
		$ok = LogisticsRules::depot_allowance( 100 );
		$this->assertTrue( $ok['eligible'] );
		$this->assertSame( 20, $ok['max'] );
	}

	public function test_shelf_life_faq_510() {
		// 12 months of life → last delivery after 4 months.
		$r = LogisticsRules::shelf_life( '2026-01-01', '2027-01-01', '2026-04-01' );
		$this->assertTrue( $r['ok'] );
		$this->assertFalse( LogisticsRules::shelf_life( '2026-01-01', '2027-01-01', '2026-06-01' )['ok'] );
		// Only expiry: 6 months.
		$this->assertTrue( LogisticsRules::shelf_life( null, '2027-01-01', '2026-06-30' )['ok'] );
		$this->assertFalse( LogisticsRules::shelf_life( null, '2027-01-01', '2026-08-01' )['ok'] );
	}

	public function test_shipping_deadline_faq_331() {
		$tz = new \DateTimeZone( 'Asia/Tehran' );
		$ts = function ( $s ) use ( $tz ) {
			return ( new \DateTimeImmutable( $s, $tz ) )->getTimestamp();
		};
		// Saturday 2026-11-07 07:35, 1 day window → same day.
		$this->assertSame( '2026-11-07', LogisticsRules::shipping_deadline( $ts( '2026-11-07 07:35' ), 1 ) );
		// Saturday 09:00 → Sunday.
		$this->assertSame( '2026-11-08', LogisticsRules::shipping_deadline( $ts( '2026-11-07 09:00' ), 1 ) );
		// Thursday 10:00, 1 day → Friday skipped → Saturday.
		$this->assertSame( '2026-11-14', LogisticsRules::shipping_deadline( $ts( '2026-11-12 10:00' ), 1 ) );
		// Order on Friday counts as Saturday before 08:00 → Saturday.
		$this->assertSame( '2026-11-14', LogisticsRules::shipping_deadline( $ts( '2026-11-13 15:00' ), 1 ) );
		// Holiday list respected.
		$this->assertSame( '2026-11-10', LogisticsRules::shipping_deadline( $ts( '2026-11-08 09:00' ), 1, array( '2026-11-09' ) ) );
	}

	public function test_discrepancy_penalty_faq_319() {
		$this->assertSame( 270000, LogisticsRules::discrepancy_penalty( 3 ) );
	}

	public function test_weekly_price_stability_guard() {
		$d = RepricingEngine::decide(
			array( 'current' => 10000000, 'base' => 7000000, 'fixed_price' => 10000000, 'in_challenge' => false, 'week_ref' => 10000000 ),
			array( 'strategy' => 'undercut', 'raise_when_alone' => true, 'max_markup_percent' => 60, 'max_weekly_change' => 10, 'rounding_step' => 1000 )
		);
		$this->assertSame( 11000000, $d['price'] );
		$this->assertStringContainsString( 'نوسان هفتگی', $d['reason'] );
	}

	public function test_fake_products_cannot_join_incredible_offers() {
		$plan = PromotionPlanner::plan( array( 'selling_price' => 1000000, 'stock' => 5, 'fake' => true ), array( 'min_discount' => 5, 'nature' => 'incredible_offer' ), array() );
		$this->assertFalse( $plan['join'] );
		$this->assertTrue( PromotionPlanner::plan( array( 'selling_price' => 1000000, 'stock' => 5, 'fake' => true ), array( 'min_discount' => 5, 'nature' => 'mega_promotion' ), array() )['join'] );
	}

	private function product() {
		$p                    = new ProductData();
		$p->title_fa          = 'گوشی موبایل سامسونگ مدل A54';
		$p->nature            = 'گوشی موبایل';
		$p->category_title    = 'گوشی موبایل';
		$p->category_id       = 11;
		$p->category_valid    = true;
		$p->brand_id          = 18;
		$p->brand_fa          = 'سامسونگ';
		$p->brand_other_id    = 999;
		$p->model             = 'A54';
		$p->has_category_data = true;
		$p->package           = array( 'length' => 20, 'width' => 10, 'height' => 5, 'weight' => 400 );
		$p->price             = 1000;
		return $p;
	}

	private function codes( array $issues ) {
		return array_map(
			static function ( Issue $i ) {
				return $i->code;
			},
			$issues
		);
	}

	public function test_fake_product_gets_misc_brand_faq_338() {
		$p       = $this->product();
		$p->fake = true;
		$res     = RuleEngine::default_engine( array() )->run( $p, true );
		$this->assertSame( 999, $p->brand_id );
		$this->assertNotContains( 'fake.brand_misc', $this->codes( $res['issues'] ) );
		$this->assertContains( 'fake.limits', $this->codes( $res['issues'] ) );
	}

	public function test_bundle_in_title_and_documents() {
		$p         = $this->product();
		$p->bundle = 'پاور بانک';
		$res       = RuleEngine::default_engine( array() )->run( $p, true );
		$this->assertStringEndsWith( 'به همراه پاور بانک', $p->title_fa );
		$this->assertContains( 'doc.mobile', $this->codes( $res['issues'] ) );
	}

	public function test_one_photo_per_color_faq_182() {
		$p                 = $this->product();
		$p->category_theme = 'colored';
		$p->variations     = array( array( 'color' => 'مشکی' ), array( 'color' => 'سفید' ), array( 'color' => 'آبی' ) );
		$p->images         = array( array( 'attachment_id' => 1, 'analysis' => array() ) );
		$res               = RuleEngine::default_engine( array() )->run( $p, false );
		$this->assertContains( 'img.per_color', $this->codes( $res['issues'] ) );
	}

	public function test_food_requires_expiry() {
		$p                 = $this->product();
		$p->category_title = 'عسل';
		$p->nature         = 'عسل';
		$res               = RuleEngine::default_engine( array() )->run( $p, false );
		$this->assertContains( 'expiry.missing', $this->codes( $res['issues'] ) );
		$this->assertContains( 'doc.health', $this->codes( $res['issues'] ) );
	}
}
