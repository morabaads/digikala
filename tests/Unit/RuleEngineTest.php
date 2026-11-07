<?php
/**
 * Tests for the rule engine & auto-fix.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\RuleEngine;
use PHPUnit\Framework\TestCase;

class RuleEngineTest extends TestCase {

	private function product() {
		$p                    = new ProductData();
		$p->wc_id             = 10;
		$p->title_fa          = '🔥گوشي موبايل سامسونگ A54 اورجینال رنگ مشکی ارسال رایگان!!';
		$p->nature            = 'گوشی موبایل';
		$p->brand_id          = 18;
		$p->brand_fa          = 'سامسونگ';
		$p->brand_en          = 'Samsung';
		$p->model             = 'A54';
		$p->category_id       = 11;
		$p->category_valid    = true;
		$p->category_theme    = 'colored';
		$p->has_category_data = true;
		$p->description_html  = '<p>گوشی عالی برای خرید. شماره تماس 09121234567</p><ul><li>باتری 5000</li><li>دوربین 50 مگاپیکسل</li></ul>';
		$p->package           = array( 'length' => 20, 'width' => 10, 'height' => 5, 'weight' => 400 );
		$p->dimension_config  = array( 'weight' => array( 'min' => 1, 'max' => 200000 ) );
		$p->images            = array(
			array( 'attachment_id' => 1, 'is_main' => true, 'analysis' => array( 'ok' => true, 'width' => 1000, 'height' => 800, 'format' => 'webp', 'bytes' => 1000, 'white_border' => 1.0, 'has_alpha' => false, 'content_ratio' => 0.9 ) ),
		);
		$p->price             = 120000000;
		$p->stock             = 5;
		$p->attribute_defs    = array(
			100 => array( 'id' => 100, 'title' => 'سیستم عامل', 'type' => 'select', 'required' => true, 'values' => array( 5 => array( 'text' => 'اندروید' ), 6 => array( 'text' => 'iOS' ) ) ),
			101 => array( 'id' => 101, 'title' => 'وزن', 'type' => 'text', 'required' => false, 'unit' => 'گرم' ),
		);
		$p->attributes        = array( 100 => 'آندروید', 101 => '195 گرم' );
		return $p;
	}

	private function engine() {
		return RuleEngine::default_engine( array( 'title_min' => 15, 'title_max' => 120, 'default_disadvantage' => 'قیمت نسبتاً بالا' ) );
	}

	private function codes( array $issues ) {
		return array_map(
			static function ( Issue $i ) {
				return $i->code;
			},
			$issues
		);
	}

	public function test_detects_violations_without_fixing() {
		$p      = $this->product();
		$result = $this->engine()->run( $p, false );
		$codes  = $this->codes( $result['issues'] );
		$this->assertContains( 'title.promo_words', $codes );
		$this->assertContains( 'title.symbols', $codes );
		$this->assertContains( 'title.variant_attr', $codes );
		$this->assertContains( 'desc.empty', $codes );
		$this->assertContains( 'attr.invalid_option', $codes );
		$this->assertContains( 'img.count', $codes );
		$this->assertTrue( $result['blocking'] );
		$this->assertLessThan( 70, $result['score']['percent'] );
	}

	public function test_autofix_produces_clean_product() {
		$p      = $this->product();
		$result = $this->engine()->run( $p, true );
		$codes  = $this->codes(
			array_filter(
				$result['issues'],
				static function ( Issue $i ) {
					return Issue::ERROR === $i->severity;
				}
			)
		);
		$this->assertSame( array(), $codes, 'No blocking errors after auto-fix: ' . implode( ',', $codes ) );
		$this->assertSame( 'گوشی موبایل سامسونگ مدل A54', $p->title_fa );
		$this->assertSame( 'Samsung Model A54', $p->title_en );
		$this->assertStringNotContainsString( '0912', $p->description );
		$this->assertSame( array( 'باتری 5000', 'دوربین 50 مگاپیکسل' ), $p->advantages );
		$this->assertSame( array( 'قیمت نسبتاً بالا' ), $p->disadvantages );
		$this->assertSame( '5', (string) $p->attributes[100] );
		$this->assertSame( '195', $p->attributes[101] );
		$this->assertNotEmpty( $result['changes'] );
		$this->assertGreaterThan( 70, $result['score']['percent'] );
	}

	public function test_fake_product_rules() {
		$p             = $this->product();
		$p->fake       = true;
		$p->allow_fake = false;
		$result        = $this->engine()->run( $p, true );
		$codes         = $this->codes( $result['issues'] );
		$this->assertContains( 'fake.not_allowed', $codes );
		$this->assertStringNotContainsString( 'سامسونگ', $p->title_fa );
	}

	public function test_missing_category_and_model_block() {
		$p              = $this->product();
		$p->category_id = null;
		$p->model       = '';
		$result         = $this->engine()->run( $p, true );
		$codes          = $this->codes( $result['issues'] );
		$this->assertContains( 'cat.unmapped', $codes );
		$this->assertContains( 'model.missing', $codes );
		$this->assertTrue( $result['blocking'] );
	}

	public function test_dimension_range() {
		$p                   = $this->product();
		$p->package['weight'] = 0.4; // Entered in kg by mistake.
		$p->dimension_config = array( 'weight' => array( 'min' => 50, 'max' => 2000 ) );
		$result              = $this->engine()->run( $p, true );
		$this->assertContains( 'dim.range', $this->codes( $result['issues'] ) );
	}

	public function test_unresolved_brand_falls_back_to_misc() {
		$p               = $this->product();
		$p->brand_id     = null;
		$p->brand_source = 'برند ناشناخته';
		$engine          = RuleEngine::default_engine( array( 'brand_other_id' => 999 ) );
		$engine->run( $p, true );
		$this->assertSame( 999, $p->brand_id );
		$this->assertTrue( $p->brand_misc );
		$this->assertStringNotContainsString( 'سامسونگ', $p->title_fa );
	}

	public function test_score_sections() {
		$p     = $this->product();
		$score = RuleEngine::score( $p, array() );
		$this->assertArrayHasKey( 'photo', $score['sections'] );
		$this->assertArrayNotHasKey( 'mefa', $score['sections'] );
		$this->assertSame( 2.0, $score['sections']['photo']['current'] );
	}
}
