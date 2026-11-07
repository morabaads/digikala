<?php
/**
 * Tests for text normalization, sanitization and title building.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Text\ContentSanitizer;
use DKBridge\Text\Normalizer;
use DKBridge\Text\TitleBuilder;
use PHPUnit\Framework\TestCase;

class TextTest extends TestCase {

	const ZWNJ = "\u{200C}";

	public function test_normalizes_arabic_letters_and_spacing() {
		$this->assertSame( 'کتاب یک', Normalizer::normalize( 'كتاب  يك' ) );
		$this->assertSame( 'سلام، دنیا', Normalizer::normalize( 'سلام ، دنیا' ) );
		$this->assertSame( 'مدل', Normalizer::normalize( 'مـــدل' ) );
	}

	public function test_fixes_zwnj() {
		$this->assertSame( 'این کالا می' . self::ZWNJ . 'شود', Normalizer::normalize( 'این کالا می شود' ) );
		$this->assertSame( 'کتاب' . self::ZWNJ . 'ها', Normalizer::normalize( 'کتاب ها' ) );
		$this->assertSame( 'بزرگ' . self::ZWNJ . 'ترین', Normalizer::normalize( 'بزرگ ترین' ) );
	}

	public function test_latin_digits_and_fold() {
		$this->assertSame( 'A54 128', Normalizer::latin_digits( 'A۵۴ ۱۲۸' ) );
		$this->assertSame( Normalizer::fold( 'قهوه‌ای' ), Normalizer::fold( 'قهوه ای' ) );
		$this->assertSame( 'samsung a54', Normalizer::fold( 'SAMSUNG - A۵۴!' ) );
	}

	public function test_strip_emoji() {
		$this->assertSame( 'گوشی عالی', Normalizer::strip_emoji( 'گوشی 🔥 عالی ✅' ) );
	}

	public function test_truncate_words() {
		$text = str_repeat( 'کلمه ', 40 );
		$out  = Normalizer::truncate_words( $text, 50 );
		$this->assertLessThanOrEqual( 50, Normalizer::length( $out ) );
		$this->assertStringEndsWith( 'کلمه', $out );
	}

	public function test_removes_contact_information() {
		$res = ContentSanitizer::remove_contact( 'برای سفارش با ۰۹۱۲ ۳۴۵ ۶۷۸۹ تماس بگیرید یا به www.shop.ir و @myshop در اینستاگرام ما سر بزنید info@shop.com' );
		$this->assertStringNotContainsString( '0912', $res['text'] );
		$this->assertStringNotContainsString( 'shop.ir', $res['text'] );
		$this->assertStringNotContainsString( '@myshop', $res['text'] );
		$this->assertStringNotContainsString( 'اینستاگرام', $res['text'] );
		$this->assertStringNotContainsString( 'info@', $res['text'] );
		$this->assertGreaterThanOrEqual( 4, count( $res['found'] ) );
	}

	public function test_does_not_treat_model_numbers_as_phones() {
		$res = ContentSanitizer::remove_contact( 'گوشی موبایل مدل A54 ظرفیت 128 گیگابایت' );
		$this->assertSame( array(), $res['found'] );
	}

	public function test_remove_words_is_whole_word() {
		$res = ContentSanitizer::remove_words( 'عسل اصل طبیعی اصلی', array( 'اصل' ) );
		$this->assertSame( 'عسل طبیعی اصلی', $res['text'] );
		$this->assertSame( array( 'اصل' ), $res['found'] );
	}

	public function test_html_to_text() {
		$html = '<p>این <strong>کالا</strong> عالی است.</p><ul><li>باتری قوی</li><li>صفحه بزرگ</li></ul><script>alert(1)</script>[gallery ids="1"]';
		$text = ContentSanitizer::html_to_text( $html );
		$this->assertStringNotContainsString( '<', $text );
		$this->assertStringNotContainsString( 'alert', $text );
		$this->assertStringNotContainsString( 'gallery', $text );
		$this->assertStringContainsString( "باتری قوی\nصفحه بزرگ", $text );
		$this->assertSame( array( 'باتری قوی', 'صفحه بزرگ' ), ContentSanitizer::list_items( $html ) );
	}

	public function test_remove_warranty_and_prices() {
		$this->assertSame( 'گوشی سامسونگ', ContentSanitizer::remove_warranty( 'گوشی سامسونگ با گارانتی 18 ماهه' )['text'] );
		$this->assertSame( 'گوشی سامسونگ', ContentSanitizer::remove_prices( 'گوشی سامسونگ 25,000,000 تومان' )['text'] );
	}

	public function test_build_title_follows_formula() {
		$title = TitleBuilder::build(
			array(
				'nature'   => 'گوشی موبایل',
				'brand'    => 'سامسونگ',
				'model'    => 'Galaxy A54',
				'features' => array( 'ظرفیت 128 گیگابایت' ),
			)
		);
		$this->assertSame( 'گوشی موبایل سامسونگ مدل Galaxy A54 ظرفیت 128 گیگابایت', $title );
	}

	public function test_build_title_drops_brand_for_fake_and_misc() {
		$this->assertSame( 'ساعت مچی مدل X1', TitleBuilder::build( array( 'nature' => 'ساعت مچی', 'brand' => 'رولکس', 'model' => 'X1', 'fake' => true ) ) );
		$this->assertSame( 'ساعت مچی مدل X1', TitleBuilder::build( array( 'nature' => 'ساعت مچی', 'brand' => 'متفرقه', 'model' => 'X1' ) ) );
	}

	public function test_formula_order_from_digikala_hint() {
		$this->assertSame( array( 'nature', 'brand', 'model', 'features' ), TitleBuilder::formula_order( 'ماهیت کالا + برند کالا + مدل کالا' ) );
		$this->assertSame( array( 'brand', 'nature', 'model', 'features' ), TitleBuilder::formula_order( 'برند + ماهیت + مدل' ) );
	}

	public function test_clean_title_removes_everything_digikala_rejects() {
		$res = TitleBuilder::clean(
			'🔥 گوشی موبايل سامسونگ A54 اورجینال رنگ مشکی با گارانتی 18 ماهه ارسال رایگان!! 09123456789',
			array(
				'model'        => 'A54',
				'strip_colors' => true,
			)
		);
		$this->assertSame( 'گوشی موبایل سامسونگ مدل A54', $res['title'] );
		$this->assertNotEmpty( $res['changes'] );
	}

	public function test_clean_title_strips_brand_for_fake_products() {
		$res = TitleBuilder::clean( 'کیف دستی گوچی مدل G1', array( 'fake' => true, 'brand' => 'گوچی', 'brand_en' => 'Gucci', 'model' => 'G1' ) );
		$this->assertSame( 'کیف دستی مدل G1', $res['title'] );
	}

	public function test_complete_inserts_missing_brand_and_model() {
		$this->assertSame( 'گوشی موبایل سامسونگ مدل A54', TitleBuilder::complete( 'گوشی موبایل', 'گوشی موبایل', 'سامسونگ', 'A54', false ) );
	}

	public function test_english_title_cleaning() {
		$this->assertSame( 'Samsung Mobile Phone Model A54', TitleBuilder::build_en( 'Mobile Phone', 'SAMSUNG', 'A54', false ) );
		$this->assertSame( 'Galaxy A54', TitleBuilder::clean_en( 'Galaxy ★ A54 گوشی' ) );
	}
}
