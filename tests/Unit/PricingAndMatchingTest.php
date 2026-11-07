<?php
/**
 * Tests for pricing and fuzzy matching.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Matching\Similarity;
use DKBridge\Pricing\PriceCalculator;
use PHPUnit\Framework\TestCase;

class PricingAndMatchingTest extends TestCase {

	public function test_converts_toman_to_rial_and_rounds() {
		$r = PriceCalculator::calculate( 1234567, array( 'currency' => 'toman', 'step' => 10000 ) );
		$this->assertSame( 12350000, $r['price'] );
		$this->assertSame( 12345670, $r['base'] );
	}

	public function test_rial_currency_is_not_multiplied() {
		$r = PriceCalculator::calculate( 5000000, array( 'currency' => 'rial', 'step' => 1000 ) );
		$this->assertSame( 5000000, $r['price'] );
	}

	public function test_markup_and_commission_compensation() {
		$r = PriceCalculator::calculate(
			1000000,
			array(
				'currency'       => 'toman',
				'markup_percent' => 10,
				'markup_fixed'   => 50000,
				'commission'     => 0.1,
				'compensate'     => true,
				'step'           => 10000,
				'mode'           => 'up',
			)
		);
		// (10,000,000 * 1.1 + 50,000) / 0.9 = 12,277,777.8 → up to 12,280,000.
		$this->assertSame( 12280000, $r['price'] );
		$this->assertNotContains( 'price.below_cost', $r['warnings'] );
	}

	public function test_below_cost_warning_without_compensation() {
		$r = PriceCalculator::calculate( 1000000, array( 'commission' => 0.15, 'min_profit_percent' => 5 ) );
		$this->assertContains( 'price.below_cost', $r['warnings'] );
	}

	public function test_clamps_to_boundary() {
		$r = PriceCalculator::calculate( 100000, array( 'currency' => 'toman', 'min' => 1500000, 'max' => 2000000 ) );
		$this->assertSame( 1500000, $r['price'] );
		$this->assertContains( 'price.clamped_min', $r['warnings'] );

		$r = PriceCalculator::calculate( 900000, array( 'currency' => 'toman', 'min' => 1500000, 'max' => 2000000, 'clamp' => false ) );
		$this->assertContains( 'price.boundary', $r['errors'] );
	}

	public function test_fixed_price_type_uses_reference() {
		$r = PriceCalculator::calculate( 100, array( 'price_type' => 'printed', 'reference_price' => 4560000 ) );
		$this->assertSame( 4560000, $r['price'] );
	}

	public function test_rounding_modes() {
		$this->assertSame( 12390000, PriceCalculator::round_price( 12345000, 100000, 'psychological' ) );
		$this->assertSame( 12300000, PriceCalculator::round_price( 12345000, 100000, 'down' ) );
		$this->assertSame( 12400000, PriceCalculator::round_price( 12345000, 100000, 'up' ) );
		$this->assertSame( 12300000, PriceCalculator::round_price( 12345000, 100000, 'nearest' ) );
	}

	public function test_zero_price_is_error() {
		$r = PriceCalculator::calculate( 0 );
		$this->assertContains( 'price.missing', $r['errors'] );
	}

	public function test_similarity_synonyms_and_fuzzy() {
		$this->assertSame( 1.0, Similarity::score( 'سیاه', 'مشکی' ) );
		$this->assertSame( 1.0, Similarity::score( 'Black', 'مشکی' ) );
		$this->assertSame( 1.0, Similarity::score( 'قهوه‌ای', 'قهوه ای' ) );
		$this->assertGreaterThan( 0.8, Similarity::score( 'گوشی موبایل سامسونگ مدل Galaxy A54', 'گوشی موبایل سامسونگ مدل Galaxy A54 5G' ) );
		$this->assertLessThan( 0.5, Similarity::score( 'گوشی موبایل سامسونگ', 'کفش ورزشی نایکی' ) );
	}

	public function test_best_match() {
		$best = Similarity::best( 'خاکستری', array( 10 => 'سفید', 11 => 'طوسی', 12 => 'مشکی' ), 0.8 );
		$this->assertSame( 11, $best['key'] );
		$none = Similarity::best( 'فسفری', array( 10 => 'سفید' ), 0.8 );
		$this->assertNull( $none['key'] );
	}

	public function test_nearest_color() {
		$res = Similarity::nearest_color( '#0a0a0a', array( 'white' => '#ffffff', 'black' => '#000000', 'red' => '#ff0000' ) );
		$this->assertSame( 'black', $res['key'] );
	}
}
