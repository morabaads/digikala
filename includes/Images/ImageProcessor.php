<?php
/**
 * Analyzes and prepares product images for Digikala (GD based).
 *
 * @package DKBridge
 */

namespace DKBridge\Images;

/**
 * Image processor (pure PHP + GD).
 *
 * Output: square JPEG on a pure white canvas, product filling ~88% of the frame,
 * EXIF stripped and orientation applied, within min/max pixel and byte limits.
 */
class ImageProcessor {

	const WHITE_THRESHOLD = 245;

	/**
	 * Options.
	 *
	 * @var array
	 */
	private $o;

	/**
	 * Constructor.
	 *
	 * @param array $options min, target, max, quality, fill, max_bytes.
	 */
	public function __construct( array $options = array() ) {
		$this->o = array_merge(
			array(
				'min'       => 600,
				'target'    => 1200,
				'max'       => 2500,
				'quality'   => 90,
				'fill'      => 0.88,
				'max_bytes' => 5242880,
			),
			$options
		);
	}

	/**
	 * Whether GD is available.
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'imagecreatefromstring' ) && function_exists( 'imagejpeg' );
	}

	/**
	 * Analyze an image without modifying it.
	 *
	 * @param string $path File.
	 * @return array{ok:bool,width:int,height:int,format:string,bytes:int,ratio:float,white_border:float,has_alpha:bool,content_ratio:float,error:string}
	 */
	public function analyze( $path ) {
		$result = array(
			'ok'            => false,
			'width'         => 0,
			'height'        => 0,
			'format'        => '',
			'bytes'         => is_readable( $path ) ? (int) filesize( $path ) : 0,
			'ratio'         => 0.0,
			'white_border'  => 0.0,
			'has_alpha'     => false,
			'content_ratio' => 0.0,
			'error'         => '',
		);
		$info = is_readable( $path ) ? @getimagesize( $path ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info ) {
			$result['error'] = 'unreadable';
			return $result;
		}
		$result['width']  = (int) $info[0];
		$result['height'] = (int) $info[1];
		$result['format'] = self::format_from_mime( isset( $info['mime'] ) ? $info['mime'] : '' );
		$result['ratio']  = $info[1] ? round( $info[0] / $info[1], 3 ) : 0.0;

		if ( ! self::available() ) {
			$result['ok'] = true;
			return $result;
		}
		$img = $this->load( $path );
		if ( ! $img ) {
			$result['error'] = 'decode_failed';
			return $result;
		}
		$result['has_alpha']    = $this->has_alpha( $img );
		$flat                   = $this->flatten( $img );
		$result['white_border'] = $this->white_border_ratio( $flat );
		$box                    = $this->content_box( $flat );
		$w                      = imagesx( $flat );
		$h                      = imagesy( $flat );
		$result['content_ratio'] = $box ? round( max( $box[2] / $w, $box[3] / $h ), 3 ) : 0.0;
		imagedestroy( $flat );
		$result['ok'] = true;
		return $result;
	}

	/**
	 * Produce a Digikala-ready JPEG.
	 *
	 * @param string $src  Source file.
	 * @param string $dest Destination .jpg path.
	 * @return array{ok:bool,path:string,width:int,height:int,bytes:int,upscale:float,notes:string[],error:string}
	 */
	public function process( $src, $dest ) {
		$out = array(
			'ok'      => false,
			'path'    => $dest,
			'width'   => 0,
			'height'  => 0,
			'bytes'   => 0,
			'upscale' => 1.0,
			'notes'   => array(),
			'error'   => '',
		);
		if ( ! self::available() ) {
			$out['error'] = 'gd_missing';
			return $out;
		}
		$img = $this->load( $src );
		if ( ! $img ) {
			$out['error'] = 'decode_failed';
			return $out;
		}
		$img = $this->apply_orientation( $img, $src );
		if ( $this->has_alpha( $img ) ) {
			$out['notes'][] = 'flatten';
		}
		$img = $this->flatten( $img );

		$w   = imagesx( $img );
		$h   = imagesy( $img );
		$box = $this->content_box( $img );
		if ( $box && ( $box[2] < $w * 0.98 || $box[3] < $h * 0.98 ) ) {
			// Only trim when the removed area is (near) white.
			$out['notes'][] = 'trim';
		} else {
			$box = array( 0, 0, $w, $h );
		}
		list( $bx, $by, $bw, $bh ) = $box;

		$content = max( $bw, $bh );
		$fill    = min( 1.0, max( 0.5, (float) $this->o['fill'] ) );
		$side    = (int) ceil( $content / $fill );
		$side    = max( (int) $this->o['min'], min( (int) $this->o['max'], $side ) );
		$scale = ( $side * $fill ) / $content;
		if ( $scale > 1.0 ) {
			$out['upscale'] = round( $scale, 2 );
			$out['notes'][] = 'upscale';
		} elseif ( $scale < 1.0 ) {
			$out['notes'][] = 'downscale';
		}

		$tw = max( 1, (int) round( $bw * $scale ) );
		$th = max( 1, (int) round( $bh * $scale ) );

		$canvas = imagecreatetruecolor( $side, $side );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $side, $side, $white );
		imagecopyresampled( $canvas, $img, (int) floor( ( $side - $tw ) / 2 ), (int) floor( ( $side - $th ) / 2 ), $bx, $by, $tw, $th, $bw, $bh );
		imagedestroy( $img );

		if ( $side !== $w || $side !== $h ) {
			$out['notes'][] = 'square';
		}

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$quality = (int) $this->o['quality'];
		do {
			imagejpeg( $canvas, $dest, $quality );
			clearstatcache( true, $dest );
			$bytes    = (int) filesize( $dest );
			$quality -= 5;
		} while ( $bytes > (int) $this->o['max_bytes'] && $quality >= 60 );
		imagedestroy( $canvas );

		$out['ok']     = $bytes > 0 && $bytes <= (int) $this->o['max_bytes'];
		$out['width']  = $side;
		$out['height'] = $side;
		$out['bytes']  = $bytes;
		if ( ! $out['ok'] ) {
			$out['error'] = 'too_large';
		}
		return $out;
	}

	/**
	 * Decode any supported format.
	 *
	 * @param string $path Path.
	 * @return resource|\GdImage|false
	 */
	private function load( $path ) {
		$data = is_readable( $path ) ? file_get_contents( $path ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data || '' === $data ) {
			return false;
		}
		$img = @imagecreatefromstring( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $img ) {
			return false;
		}
		if ( function_exists( 'imagepalettetotruecolor' ) && ! imageistruecolor( $img ) ) {
			imagepalettetotruecolor( $img );
		}
		imagealphablending( $img, false );
		imagesavealpha( $img, true );
		return $img;
	}

	/**
	 * Rotate according to EXIF orientation.
	 *
	 * @param resource|\GdImage $img  Image.
	 * @param string            $path Source.
	 * @return resource|\GdImage
	 */
	private function apply_orientation( $img, $path ) {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return $img;
		}
		$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( empty( $exif['Orientation'] ) ) {
			return $img;
		}
		$angles = array(
			3 => 180,
			6 => -90,
			8 => 90,
		);
		$o = (int) $exif['Orientation'];
		if ( isset( $angles[ $o ] ) ) {
			$rotated = imagerotate( $img, $angles[ $o ], 0 );
			if ( $rotated ) {
				imagedestroy( $img );
				return $rotated;
			}
		}
		return $img;
	}

	/**
	 * Whether the image uses transparency.
	 *
	 * @param resource|\GdImage $img Image.
	 * @return bool
	 */
	private function has_alpha( $img ) {
		$w    = imagesx( $img );
		$h    = imagesy( $img );
		$step = max( 1, (int) floor( min( $w, $h ) / 40 ) );
		for ( $x = 0; $x < $w; $x += $step ) {
			for ( $y = 0; $y < $h; $y += $step ) {
				if ( ( imagecolorat( $img, $x, $y ) >> 24 ) & 0x7F ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Composite onto white.
	 *
	 * @param resource|\GdImage $img Image.
	 * @return resource|\GdImage New image.
	 */
	private function flatten( $img ) {
		$w   = imagesx( $img );
		$h   = imagesy( $img );
		$out = imagecreatetruecolor( $w, $h );
		imagefilledrectangle( $out, 0, 0, $w, $h, imagecolorallocate( $out, 255, 255, 255 ) );
		imagealphablending( $out, true );
		imagecopy( $out, $img, 0, 0, 0, 0, $w, $h );
		return $out;
	}

	/**
	 * Pixel is (near) white.
	 *
	 * @param int $rgb Color int.
	 * @return bool
	 */
	private static function is_white( $rgb ) {
		$t = self::WHITE_THRESHOLD;
		return ( ( $rgb >> 16 ) & 0xFF ) >= $t && ( ( $rgb >> 8 ) & 0xFF ) >= $t && ( $rgb & 0xFF ) >= $t;
	}

	/**
	 * Ratio of near-white pixels on the outer border band.
	 *
	 * @param resource|\GdImage $img Image.
	 * @return float
	 */
	public function white_border_ratio( $img ) {
		$w     = imagesx( $img );
		$h     = imagesy( $img );
		$band  = max( 1, (int) round( min( $w, $h ) * 0.02 ) );
		$step  = max( 1, (int) floor( max( $w, $h ) / 200 ) );
		$total = 0;
		$white = 0;
		for ( $x = 0; $x < $w; $x += $step ) {
			for ( $b = 0; $b < $band; $b++ ) {
				foreach ( array( $b, $h - 1 - $b ) as $y ) {
					++$total;
					$white += self::is_white( imagecolorat( $img, $x, $y ) ) ? 1 : 0;
				}
			}
		}
		for ( $y = 0; $y < $h; $y += $step ) {
			for ( $b = 0; $b < $band; $b++ ) {
				foreach ( array( $b, $w - 1 - $b ) as $x ) {
					++$total;
					$white += self::is_white( imagecolorat( $img, $x, $y ) ) ? 1 : 0;
				}
			}
		}
		return $total ? round( $white / $total, 3 ) : 0.0;
	}

	/**
	 * Bounding box [x, y, w, h] of non-white content.
	 *
	 * @param resource|\GdImage $img Image.
	 * @return int[]|null
	 */
	public function content_box( $img ) {
		$w    = imagesx( $img );
		$h    = imagesy( $img );
		$step = max( 1, (int) floor( max( $w, $h ) / 600 ) );
		$minx = $w;
		$miny = $h;
		$maxx = -1;
		$maxy = -1;
		for ( $y = 0; $y < $h; $y += $step ) {
			for ( $x = 0; $x < $w; $x += $step ) {
				if ( ! self::is_white( imagecolorat( $img, $x, $y ) ) ) {
					if ( $x < $minx ) {
						$minx = $x;
					}
					if ( $x > $maxx ) {
						$maxx = $x;
					}
					if ( $y < $miny ) {
						$miny = $y;
					}
					if ( $y > $maxy ) {
						$maxy = $y;
					}
				}
			}
		}
		if ( $maxx < 0 ) {
			return null;
		}
		$minx = max( 0, $minx - $step );
		$miny = max( 0, $miny - $step );
		$maxx = min( $w - 1, $maxx + $step );
		$maxy = min( $h - 1, $maxy + $step );
		return array( $minx, $miny, $maxx - $minx + 1, $maxy - $miny + 1 );
	}

	/**
	 * Normalize mime → short format.
	 *
	 * @param string $mime Mime.
	 * @return string
	 */
	public static function format_from_mime( $mime ) {
		$map = array(
			'image/jpeg' => 'jpg',
			'image/jpg'  => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
			'image/avif' => 'avif',
			'image/bmp'  => 'bmp',
		);
		return isset( $map[ $mime ] ) ? $map[ $mime ] : (string) $mime;
	}
}
