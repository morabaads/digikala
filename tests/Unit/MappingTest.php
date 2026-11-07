<?php
/**
 * Tests for payload building and variant theme matching.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Mapping\AttributeMapper;
use DKBridge\Mapping\BrandResolver;
use DKBridge\Mapping\PayloadBuilder;
use DKBridge\Mapping\ProductData;
use DKBridge\Mapping\ProductMapper;
use DKBridge\Mapping\VariantMapper;
use PHPUnit\Framework\TestCase;

class MappingTest extends TestCase {

	private function colors() {
		return array(
			array( 'id' => 1, 'titleFa' => 'مشکی', 'titleEn' => 'Black', 'value' => array( 'hex' => '#000000' ) ),
			array( 'id' => 2, 'titleFa' => 'سفید', 'titleEn' => 'White', 'value' => array( 'hex' => '#FFFFFF' ) ),
			array( 'id' => 3, 'titleFa' => 'قرمز', 'titleEn' => 'Red', 'value' => array( 'hex' => '#FF0000' ) ),
			array( 'id' => 9, 'titleFa' => 'سبز', 'titleEn' => 'Green', 'value' => array( 'hex' => '#00FF00' ), 'active' => false ),
		);
	}

	public function test_variant_match_by_name_synonym_and_hex() {
		$this->assertSame( 1, VariantMapper::match( 'سیاه', '', $this->colors(), true )['id'] );
		$this->assertSame( 2, VariantMapper::match( 'white', '', $this->colors(), true )['id'] );
		$hex = VariantMapper::match( 'زرشکی تیره', '#e01010', $this->colors(), true );
		$this->assertSame( 3, $hex['id'] );
		$this->assertSame( 'hex', $hex['method'] );
		$this->assertNull( VariantMapper::match( 'سبز', '', $this->colors(), true )['id'], 'inactive values are skipped' );
	}

	public function test_size_match_is_strict() {
		$sizes = array(
			array( 'id' => 10, 'titleFa' => 'L', 'titleEn' => 'L' ),
			array( 'id' => 11, 'titleFa' => 'XL', 'titleEn' => 'XL' ),
		);
		$this->assertSame( 11, VariantMapper::match( 'xl', '', $sizes, false )['id'] );
		$this->assertSame( 10, VariantMapper::match( 'Large', '', $sizes, false )['id'] );
		$this->assertNull( VariantMapper::match( 'XXL', '', $sizes, false )['id'] );
	}

	public function test_resolve_reports_unknown_and_duplicates() {
		$rows   = array(
			array( 'wc_id' => 1, 'label' => 'مشکی', 'color' => 'مشکی', 'color_hex' => '', 'size' => '' ),
			array( 'wc_id' => 2, 'label' => 'سیاه', 'color' => 'سیاه', 'color_hex' => '', 'size' => '' ),
			array( 'wc_id' => 3, 'label' => 'فسفری', 'color' => 'فسفری', 'color_hex' => '', 'size' => '' ),
			array( 'wc_id' => 4, 'label' => 'سفید', 'color' => 'سفید', 'color_hex' => '', 'size' => '' ),
		);
		$config = array(
			'variant_theme' => 'colored',
			'themes'        => array( array( 'id' => 1, 'label' => 'رنگ', 'themeType' => 'colored' ) ),
		);
		$colors = $this->colors();
		$out    = VariantMapper::resolve(
			$rows,
			$config,
			function () use ( $colors ) {
				return $colors;
			}
		);
		$this->assertSame( array( 1, 4 ), array_column( $out['rows'], 'wc_id' ) );
		$this->assertSame( 1, $out['rows'][0]['theme']['color_id'] );
		$this->assertCount( 2, $out['errors'] );
	}

	public function test_no_theme_creates_single_variant() {
		$out = VariantMapper::resolve( array( array( 'wc_id' => 5, 'label' => 'a' ), array( 'wc_id' => 6, 'label' => 'b' ) ), array( 'variant_theme' => 'no_color_no_size' ), 'strval' );
		$this->assertCount( 1, $out['rows'] );
	}

	public function test_details_payload() {
		$p                   = new ProductData();
		$p->category_id      = 11;
		$p->brand_id         = 18;
		$p->model            = 'A54';
		$p->package          = array( 'length' => 15.2, 'width' => 0.4, 'height' => 3, 'weight' => 199.5 );
		$p->advantages       = array( 'a' );
		$p->disadvantages    = array( 'b' );
		$p->description      = 'desc';
		$p->product_type_ids = array( '7' );
		$body                = PayloadBuilder::details( $p, 99 );
		$this->assertSame( 16, $body['package_length'] );
		$this->assertSame( 1, $body['package_width'] );
		$this->assertSame( 200, $body['package_weight'] );
		$this->assertSame( array( 7 ), $body['product_type_ids'] );
		$this->assertSame( 99, $body['draft_product_id'] );
		$this->assertFalse( $body['fake'] );
		$this->assertArrayNotHasKey( 'general_mefa_id', $body );
	}

	public function test_attribute_and_save_payloads() {
		$p             = new ProductData();
		$p->category_id = 11;
		$p->package    = array( 'length' => 10, 'width' => 5, 'height' => 2, 'weight' => 300 );
		$p->attributes = array( 100 => '5', 101 => '195', 102 => '', 103 => array( '7', '8' ) );
		$attrs         = PayloadBuilder::attributes( $p, 42 );
		$this->assertSame( array( array( 'id' => 100, 'value' => 5 ), array( 'id' => 101, 'value' => 195 ), array( 'id' => 103, 'value' => array( 7, 8 ) ) ), $attrs['attributes'] );
		$this->assertSame( 300.0, $attrs['weight'] );

		$save = PayloadBuilder::save( $p, 42, array( 'A1', 'B2' ) );
		$this->assertSame( 'A1', $save['photos_detail']['main_image'] );
		$this->assertSame( 'A1,B2', $save['photos_detail']['order'] );
		$this->assertSame( array( 'encrypted_id' => 'B2', 'active' => true ), $save['photos_detail']['images'][1] );
	}

	public function test_variant_payload() {
		$defaults = array( 'warranty_id' => 272, 'site' => 'digikala', 'shipping_type' => 'seller', 'lead_time' => 5, 'lead_time_max' => 2, 'seller_lead_time' => 24, 'order_limit' => 3 );
		$body     = PayloadBuilder::variant( array( 'wc_id' => 55, 'sku' => '', 'price' => 1290000, 'stock' => 0 ), $defaults, array( 'color_id' => 2 ) );
		$this->assertSame( 'wc-55', $body['supplier_code'] );
		$this->assertFalse( $body['is_active'] );
		$this->assertSame( 2, $body['lead_time'] );
		$this->assertSame( 24, $body['fbs_lead_time'] );
		$this->assertSame( 2, $body['color_id'] );
	}

	public function test_attribute_flatten_and_coerce() {
		$flat = AttributeMapper::flatten(
			array(
				'category_group_attributes' => array(
					1904 => array(
						'group_title' => 'مشخصات',
						'attributes'  => array(
							1597 => array( 'id' => 1597, 'title' => 'جنس', 'type' => 'select', 'values' => array( 7070 => array( 'text' => 'پلاستیک' ), 7071 => array( 'text' => 'فلز' ) ) ),
							119  => array( 'id' => 119, 'title' => 'وزن', 'type' => 'number' ),
						),
					),
				),
				'weight_attribute' => true,
			)
		);
		$this->assertSame( 1904, $flat['groups'][1597] );
		$this->assertTrue( $flat['meta']['weight_attribute'] );
		$this->assertSame( '7071', AttributeMapper::coerce( $flat['defs'][1597], array( 'فلزی' ) ) );
		$this->assertSame( '7070', AttributeMapper::coerce( $flat['defs'][1597], 'x', array( 'x' => '7070' ) ) );
		$this->assertSame( '1.5', AttributeMapper::coerce( $flat['defs'][119], '۱٫۵ کیلو' ) );
	}

	public function test_brand_pick() {
		$items = array(
			'شیائومی' => array( 'id' => 1, 'name_fa' => 'شیائومی', 'name_en' => 'Xiaomi' ),
			'شیائو'   => array( 'id' => 2, 'name_fa' => 'شیائو', 'name_en' => 'Shiao' ),
		);
		$this->assertSame( 1, BrandResolver::pick( 'XIAOMI', $items )['id'] );
		$this->assertNull( BrandResolver::pick( 'سامسونگ', $items ) );
	}

	public function test_unit_conversion() {
		$this->assertSame( 25.0, ProductMapper::to_cm( 250, 'mm' ) );
		$this->assertSame( 1500.0, ProductMapper::to_gram( 1.5, 'kg' ) );
		$this->assertSame( 'گوشی موبایل', ProductMapper::nature_from_category( 'گوشی موبایل (Mobile Phone)' ) );
		$this->assertSame( array( 'a', 'b' ), ProductMapper::lines( "a\r\n\n b " ) );
	}
}
