<?php
/**
 * Category compliance & seller-policy rules from Digikala's official seller FAQ.
 *
 * Sources (digikala.com/faq/question/{id}): 338/341 (non-original badge), 189 (bundles),
 * 182 (one photo per color), 164/136/598 (licences, health labels), 591 (union warranties),
 * 510 (expiry), 207/714 (no contact info / brochures).
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;
use DKBridge\Text\Normalizer;

/**
 * compliance section (not part of the content score, but shown in the report).
 */
class ComplianceRule extends Rule {

	/**
	 * Keyword groups → requirement message.
	 */
	const REQUIREMENTS = array(
		'health'  => array(
			'keywords' => array( 'آرایشی', 'بهداشتی', 'عطر', 'ادکلن', 'ادو', 'کرم', 'شامپو', 'رژ', 'ریمل', 'لوسیون', 'ضد آفتاب', 'خوراکی', 'غذایی', 'مکمل', 'نوشیدنی', 'قهوه', 'چای', 'عسل', 'شکلات', 'خشکبار', 'ادویه' ),
			'message'  => 'کالاهای آرایشی‌بهداشتی، عطر و خوراکی: نسخهٔ وارداتی لیبل وزارت بهداشت و نسخهٔ داخلی «سیب سلامت» لازم دارد.',
			'expiry'   => true,
		),
		'medical' => array(
			'keywords' => array( 'پزشکی', 'فشارسنج', 'دماسنج', 'تب سنج', 'قندسنج', 'نبولایزر', 'ماسک پزشکی', 'ارتوپدی', 'سمعک' ),
			'message'  => 'تجهیزات پزشکی: مجوز رسمی سازمان غذا و دارو و ثبت در فهرست مجاز لازم است.',
			'expiry'   => false,
		),
		'book'    => array(
			'keywords' => array( 'کتاب' ),
			'message'  => 'فروش کتاب نیاز به «پروانهٔ نشر» در پنل فروشنده دارد.',
			'expiry'   => false,
		),
		'mobile'  => array(
			'keywords' => array( 'گوشی موبایل', 'تبلت', 'موبایل' ),
			'message'  => 'موبایل و تبلت: «پروانهٔ کسب» یا تعهدنامهٔ فروش لوازم اورجینال باید در پنل بارگذاری شود.',
			'expiry'   => false,
		),
		'it'      => array(
			'keywords' => array( 'لپ تاپ', 'لپ‌تاپ', 'کامپیوتر', 'مانیتور', 'هارد', 'رم ', 'کارت گرافیک', 'پردازنده', 'مادربرد', 'ماوس', 'کیبورد', 'پرینتر', 'مودم', 'روتر' ),
			'message'  => 'کالاهای IT باید گارانتی مورد تأیید اتحادیهٔ فناوران رایانه داشته باشند.',
			'expiry'   => false,
		),
		'vehicle' => array(
			'keywords' => array( 'موتورسیکلت', 'خودرو' ),
			'message'  => 'فروش موتور و خودرو نیاز به مدارک وزارت صنعت، معدن و تجارت دارد.',
			'expiry'   => false,
		),
	);

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'compliance';
	}

	/**
	 * Requirement groups that match the product.
	 *
	 * @param ProductData $p Product.
	 * @return string[]
	 */
	public static function groups( ProductData $p ) {
		$haystack = ' ' . Normalizer::fold( $p->category_title . ' ' . $p->nature ) . ' ';
		$out      = array();
		foreach ( self::REQUIREMENTS as $key => $req ) {
			foreach ( $req['keywords'] as $kw ) {
				if ( false !== mb_strpos( $haystack, Normalizer::fold( $kw ) ) ) {
					$out[] = $key;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();

		// Non-original badge: brand must be «متفرقه» (FAQ 338).
		if ( $p->fake ) {
			if ( ! $p->brand_misc && $p->brand_id ) {
				$issues[] = $this->issue( 'fake.brand_misc', Issue::ERROR, 'originality', 'برای کالای غیراصل فقط برند «متفرقه» قابل انتخاب است.', $p->brand_other_id > 0 );
			}
			$issues[] = $this->issue( 'fake.limits', Issue::INFO, 'compliance', 'کالای غیراصل فقط «گارانتی سلامت فیزیکی کالا» می‌گیرد و نمی‌تواند در «پیشنهاد شگفت‌انگیز» شرکت کند.' );
		}

		// Bundle item must be named in the title (FAQ 189).
		if ( '' !== trim( $p->bundle ) && false === mb_strpos( Normalizer::fold( $p->title_fa ), Normalizer::fold( $p->bundle ) ) ) {
			$issues[] = $this->issue( 'bundle.title', Issue::ERROR, 'title_fa', sprintf( 'کالای همراه «%s» باید در عنوان کالا ذکر شود.', $p->bundle ), true );
		}

		// One album photo per color (FAQ 182).
		if ( 'colored' === $p->category_theme && $p->variations ) {
			$colors = array_unique( array_filter( array_map( 'strval', array_column( $p->variations, 'color' ) ) ) );
			if ( count( $colors ) > count( $p->images ) ) {
				$issues[] = $this->issue( 'img.per_color', Issue::WARNING, 'photo', sprintf( 'به ازای هر رنگ یک تصویر در آلبوم لازم است: %d رنگ، %d تصویر.', count( $colors ), count( $p->images ) ) );
			}
		}

		// Category specific documents.
		$expiry_needed = false;
		foreach ( self::groups( $p ) as $group ) {
			$issues[] = $this->issue( 'doc.' . $group, Issue::INFO, 'compliance', self::REQUIREMENTS[ $group ]['message'] );
			$expiry_needed = $expiry_needed || self::REQUIREMENTS[ $group ]['expiry'];
		}
		if ( $expiry_needed && '' === $p->expiry_date ) {
			$issues[] = $this->issue( 'expiry.missing', Issue::WARNING, 'compliance', 'تاریخ انقضا ثبت نشده است؛ هنگام تحویل به انبار باید دو سوم عمر کالا (یا ۶ ماه) باقی باشد.' );
		}
		return $issues;
	}

	/**
	 * Fix.
	 *
	 * @param ProductData $p      Product.
	 * @param Issue[]     $issues Issues.
	 * @return string[]
	 */
	public function fix( ProductData $p, array $issues ) {
		$changes = array();
		foreach ( $issues as $issue ) {
			if ( 'fake.brand_misc' === $issue->code && $p->brand_other_id ) {
				$p->brand_id   = (int) $p->brand_other_id;
				$p->brand_misc = true;
				$changes[]     = 'برند کالای غیراصل به «متفرقه» تغییر کرد.';
			}
			if ( 'bundle.title' === $issue->code ) {
				$p->title_fa = Normalizer::normalize( $p->title_fa . ' به همراه ' . trim( $p->bundle ) );
				$changes[]   = 'نام کالای همراه به عنوان اضافه شد.';
			}
		}
		return $changes;
	}
}

