<?php
/**
 * Image rules (analysis based; actual pixel fixes happen in ImageProcessor at push time).
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;

/**
 * photo section.
 */
class ImageRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'photo';
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();
		if ( ! $p->images ) {
			return array( $this->issue( 'img.missing_main', Issue::ERROR, 'photo', 'کالا تصویر ندارد؛ حداقل یک تصویر اصلی با پس‌زمینهٔ سفید لازم است.' ) );
		}
		$min      = (int) $this->cfg( 'img_min', 600 );
		$max      = (int) $this->cfg( 'img_max', 2500 );
		$maxbytes = (int) $this->cfg( 'img_max_bytes', 5242880 );
		$fill     = (float) $this->cfg( 'img_fill', 0.88 );

		foreach ( $p->images as $index => $img ) {
			$a     = isset( $img['analysis'] ) ? (array) $img['analysis'] : array();
			$label = ! empty( $img['is_main'] ) ? 'تصویر اصلی' : sprintf( 'تصویر %d', $index + 1 );
			if ( empty( $a ) ) {
				continue;
			}
			if ( empty( $a['ok'] ) ) {
				$issues[] = $this->issue( 'img.unreadable', Issue::ERROR, 'photo', $label . ': فایل تصویر خوانده نشد.' );
				continue;
			}
			if ( ! in_array( $a['format'], array( 'jpg', 'png' ), true ) ) {
				$issues[] = $this->issue( 'img.format', Issue::INFO, 'photo', sprintf( '%s: قالب %s به JPG تبدیل می‌شود.', $label, strtoupper( $a['format'] ) ), true );
			}
			$short = min( $a['width'], $a['height'] );
			$long  = max( $a['width'], $a['height'] );
			if ( $long * $fill < $min / 2 ) {
				$issues[] = $this->issue( 'img.min_size', Issue::ERROR, 'photo', sprintf( '%s: ابعاد %d×%d بسیار کوچک است (حداقل %d پیکسل).', $label, $a['width'], $a['height'], $min ) );
			} elseif ( $long < $min ) {
				$issues[] = $this->issue( 'img.min_size', Issue::WARNING, 'photo', sprintf( '%s: ابعاد %d×%d کمتر از %d پیکسل است و بزرگ‌نمایی می‌شود (کیفیت ممکن است افت کند).', $label, $a['width'], $a['height'], $min ), true );
			}
			if ( $long > $max || $a['bytes'] > $maxbytes ) {
				$issues[] = $this->issue( 'img.max_size', Issue::INFO, 'photo', sprintf( '%s: تصویر کوچک و فشرده می‌شود.', $label ), true );
			}
			if ( $short && abs( $a['width'] - $a['height'] ) > 2 ) {
				$issues[] = $this->issue( 'img.square', Issue::INFO, 'photo', sprintf( '%s: نسبت ۱:۱ نیست؛ روی بوم سفید مربعی قرار می‌گیرد.', $label ), true );
			}
			if ( ! empty( $a['has_alpha'] ) ) {
				$issues[] = $this->issue( 'img.transparent', Issue::INFO, 'photo', sprintf( '%s: پس‌زمینهٔ شفاف با سفید جایگزین می‌شود.', $label ), true );
			}
			if ( ! empty( $img['is_main'] ) && isset( $a['white_border'] ) && $a['white_border'] < 0.9 && empty( $a['has_alpha'] ) ) {
				$issues[] = $this->issue( 'img.white_bg', Issue::WARNING, 'photo', sprintf( 'پس‌زمینهٔ تصویر اصلی سفید خالص نیست (%d٪ حاشیه سفید است). دیجی‌کالا برای تصویر اصلی پس‌زمینهٔ سفید (#FFFFFF) می‌خواهد.', (int) round( $a['white_border'] * 100 ) ) );
			}
			if ( isset( $a['content_ratio'] ) && $a['content_ratio'] > 0 && $a['content_ratio'] < 0.6 ) {
				$issues[] = $this->issue( 'img.fill_ratio', Issue::INFO, 'photo', sprintf( '%s: کالا بخش کوچکی از قاب را پر کرده؛ برش و بزرگ‌نمایی می‌شود.', $label ), true );
			}
		}

		$count = count( $p->images );
		if ( $count < 3 ) {
			$issues[] = $this->issue( 'img.count', Issue::WARNING, 'photo', sprintf( 'فقط %d تصویر دارید؛ حداقل ۳ تصویر از زوایای مختلف امتیاز تصویر را کامل می‌کند.', $count ) );
		}
		return $issues;
	}

	/**
	 * Image fixes are applied while uploading; nothing to change on the DTO.
	 *
	 * @param ProductData $p      Product.
	 * @param Issue[]     $issues Issues.
	 * @return string[]
	 */
	public function fix( ProductData $p, array $issues ) {
		$max = (int) $this->cfg( 'img_max_count', 10 );
		if ( count( $p->images ) > $max ) {
			$p->images = array_slice( $p->images, 0, $max );
			return array( sprintf( 'تعداد تصاویر به %d محدود شد.', $max ) );
		}
		return array();
	}
}
