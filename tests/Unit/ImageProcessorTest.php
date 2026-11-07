<?php
/**
 * Tests for the GD image processor.
 *
 * @package DKBridge
 */

namespace DKBridge\Tests\Unit;

use DKBridge\Images\ImageProcessor;
use PHPUnit\Framework\TestCase;

class ImageProcessorTest extends TestCase {

	private $dir;

	protected function setUp(): void {
		if ( ! ImageProcessor::available() ) {
			$this->markTestSkipped( 'GD not available' );
		}
		$this->dir = sys_get_temp_dir() . '/dkb-img-' . uniqid();
		mkdir( $this->dir );
	}

	protected function tearDown(): void {
		if ( $this->dir && is_dir( $this->dir ) ) {
			array_map( 'unlink', glob( $this->dir . '/*' ) );
			rmdir( $this->dir );
		}
	}

	private function make( $w, $h, $bg, $box, $type = 'png', $alpha = false ) {
		$img = imagecreatetruecolor( $w, $h );
		imagesavealpha( $img, true );
		if ( $alpha ) {
			imagealphablending( $img, false );
			imagefill( $img, 0, 0, imagecolorallocatealpha( $img, 0, 0, 0, 127 ) );
			imagealphablending( $img, true );
		} else {
			imagefill( $img, 0, 0, imagecolorallocate( $img, $bg[0], $bg[1], $bg[2] ) );
		}
		imagefilledrectangle( $img, $box[0], $box[1], $box[2], $box[3], imagecolorallocate( $img, 200, 30, 30 ) );
		$path = $this->dir . '/src-' . uniqid() . '.' . $type;
		'png' === $type ? imagepng( $img, $path ) : imagejpeg( $img, $path, 95 );
		imagedestroy( $img );
		return $path;
	}

	public function test_analyze_detects_non_white_background() {
		$p = new ImageProcessor();
		$a = $p->analyze( $this->make( 800, 600, array( 40, 40, 200 ), array( 300, 200, 500, 400 ), 'jpg' ) );
		$this->assertTrue( $a['ok'] );
		$this->assertSame( 800, $a['width'] );
		$this->assertLessThan( 0.5, $a['white_border'] );
	}

	public function test_process_makes_white_square_with_fill() {
		$src = $this->make( 1600, 900, array( 255, 255, 255 ), array( 600, 300, 999, 599 ), 'png' );
		$p   = new ImageProcessor( array( 'min' => 600, 'max' => 2500, 'fill' => 0.88 ) );
		$out = $p->process( $src, $this->dir . '/out.jpg' );
		$this->assertTrue( $out['ok'] );
		$this->assertSame( $out['width'], $out['height'] );
		$this->assertGreaterThanOrEqual( 600, $out['width'] );
		$this->assertContains( 'trim', $out['notes'] );

		$a = $p->analyze( $out['path'] );
		$this->assertSame( 'jpg', $a['format'] );
		$this->assertGreaterThan( 0.95, $a['white_border'] );
		$this->assertEqualsWithDelta( 0.88, $a['content_ratio'], 0.03 );
	}

	public function test_transparent_png_is_flattened_on_white() {
		$src = $this->make( 500, 500, array( 0, 0, 0 ), array( 100, 100, 400, 400 ), 'png', true );
		$p   = new ImageProcessor();
		$a   = $p->analyze( $src );
		$this->assertTrue( $a['has_alpha'] );
		$out = $p->process( $src, $this->dir . '/flat.jpg' );
		$this->assertContains( 'flatten', $out['notes'] );
		$this->assertContains( 'upscale', $out['notes'] );
		$this->assertGreaterThan( 0.95, $p->analyze( $out['path'] )['white_border'] );
	}

	public function test_large_image_is_downscaled() {
		$src = $this->make( 3000, 3000, array( 255, 255, 255 ), array( 10, 10, 2990, 2990 ), 'jpg' );
		$p   = new ImageProcessor( array( 'max' => 2500 ) );
		$out = $p->process( $src, $this->dir . '/big.jpg' );
		$this->assertSame( 2500, $out['width'] );
	}
}
