<?php
/**
 * Builds ProductData from a WooCommerce product (+ Digikala category data).
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Images\ImageProcessor;
use DKBridge\Pricing\PriceCalculator;
use DKBridge\Settings;
use DKBridge\Text\ContentSanitizer;
use DKBridge\Text\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Product mapper.
 */
class ProductMapper {

	/**
	 * Meta keys for per-product overrides (shadow content).
	 */
	const META = array(
		'title_fa'          => '_dkb_title_fa',
		'title_en'          => '_dkb_title_en',
		'nature'            => '_dkb_nature',
		'nature_en'         => '_dkb_nature_en',
		'model'             => '_dkb_model',
		'brand'             => '_dkb_brand',
		'brand_id'          => '_dkb_brand_id',
		'description'       => '_dkb_description',
		'advantages'        => '_dkb_advantages',
		'disadvantages'     => '_dkb_disadvantages',
		'fake'              => '_dkb_fake',
		'is_iranian'        => '_dkb_is_iranian',
		'general_mefa_id'   => '_dkb_general_mefa_id',
		'exclusive_mefa_id' => '_dkb_exclusive_mefa_id',
		'product_type_ids'  => '_dkb_product_type_ids',
		'division_id'       => '_dkb_division_id',
		'exclude'           => '_dkb_exclude',
		'warranty_id'       => '_dkb_warranty_id',
		'bundle'            => '_dkb_bundle',
		'production_date'   => '_dkb_production_date',
		'expiry_date'       => '_dkb_expiry_date',
	);

	/**
	 * Color attribute names.
	 */
	const COLOR_KEYS = array( 'color', 'colour', 'رنگ', 'pa_color', 'pa_colour', 'pa_رنگ', 'رنگ بندی' );

	/**
	 * Size attribute names.
	 */
	const SIZE_KEYS = array( 'size', 'سایز', 'pa_size', 'pa_سایز', 'اندازه', 'pa_اندازه', 'شماره', 'pa_shoe-size' );

	/** @var Settings */
	private $settings;
	/** @var Digikala|null */
	private $api;
	/** @var CategoryMapper */
	private $categories;
	/** @var AttributeMapper */
	private $attributes;
	/** @var BrandResolver */
	private $brands;

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings   Settings.
	 * @param Digikala|null   $api        API (null = offline build).
	 * @param CategoryMapper  $categories Category mapper.
	 * @param AttributeMapper $attributes Attribute mapper.
	 */
	public function __construct( Settings $settings, $api, CategoryMapper $categories, AttributeMapper $attributes ) {
		$this->settings   = $settings;
		$this->api        = $api;
		$this->categories = $categories;
		$this->attributes = $attributes;
		$this->brands     = new BrandResolver( $api, (string) $settings->get( 'brand_source', 'auto' ) );
	}

	/**
	 * Rule configuration derived from settings.
	 *
	 * @param ProductData|null $p Product (for category specific values).
	 * @return array
	 */
	public function rule_config( $p = null ) {
		$s = $this->settings;
		return array(
			'title_min'             => (int) $s->get( 'title_min' ),
			'title_max'             => (int) $s->get( 'title_max' ),
			'desc_min'              => (int) $s->get( 'desc_min' ),
			'desc_max'              => (int) $s->get( 'desc_max' ),
			'extra_forbidden_words' => (string) $s->get( 'extra_forbidden_words' ),
			'default_disadvantage'  => (string) $s->get( 'default_disadvantage' ),
			'img_min'               => (int) $s->get( 'img_min' ),
			'img_max'               => (int) $s->get( 'img_max' ),
			'img_max_bytes'         => (int) $s->get( 'img_max_bytes' ),
			'img_fill'              => (float) $s->get( 'img_fill' ),
			'img_max_count'         => (int) $s->get( 'img_max_count' ),
			'brand_other_id'        => $p && isset( $p->brand_other_id ) ? (int) $p->brand_other_id : 0,
		);
	}

	/**
	 * Read an override.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Key of META.
	 * @return mixed
	 */
	public static function override( $product, $key ) {
		return $product->get_meta( self::META[ $key ] );
	}

	/**
	 * Build the DTO.
	 *
	 * @param \WC_Product $product  Product.
	 * @param bool        $with_api Load Digikala category data.
	 * @return ProductData
	 */
	public function build( $product, $with_api = true ) {
		$p          = new ProductData();
		$p->wc_id   = $product->get_id();
		$p->wc_type = $product->is_type( 'variable' ) ? 'variable' : 'simple';

		// Content.
		$p->title_fa         = Normalizer::normalize( (string) ( self::override( $product, 'title_fa' ) ?: $product->get_name() ) );
		$p->title_en         = (string) self::override( $product, 'title_en' );
		$p->description_html = (string) ( $product->get_description() ?: $product->get_short_description() );
		$desc_override       = (string) self::override( $product, 'description' );
		$p->description      = '' !== $desc_override ? $desc_override : ContentSanitizer::html_to_text( $p->description_html );
		$p->advantages       = self::lines( self::override( $product, 'advantages' ) );
		$p->disadvantages    = self::lines( self::override( $product, 'disadvantages' ) );
		$p->model            = Normalizer::normalize( (string) ( self::override( $product, 'model' ) ?: $this->model_from_source( $product ) ) );
		$p->fake             = 'yes' === self::override( $product, 'fake' );
		$iranian             = self::override( $product, 'is_iranian' );
		$p->is_iranian       = '' === $iranian ? (bool) $this->settings->get( 'default_is_iranian' ) : 'yes' === $iranian;
		$p->general_mefa_id  = (string) self::override( $product, 'general_mefa_id' );
		$p->exclusive_mefa_id = (string) self::override( $product, 'exclusive_mefa_id' );
		$p->product_type_ids = array_values( array_filter( array_map( 'intval', (array) self::override( $product, 'product_type_ids' ) ) ) );
		$p->division_id      = (int) self::override( $product, 'division_id' ) ?: null;
		$p->nature           = (string) self::override( $product, 'nature' );
		$p->nature_en        = (string) self::override( $product, 'nature_en' );
		$p->bundle           = trim( (string) self::override( $product, 'bundle' ) );
		$p->production_date  = (string) self::override( $product, 'production_date' );
		$p->expiry_date      = (string) self::override( $product, 'expiry_date' );

		// Dimensions.
		$p->package = self::dimensions( $product );
		$p->item    = $p->package;

		// Brand text.
		$brand_text      = (string) ( self::override( $product, 'brand' ) ?: $this->brands->brand_text( $product ) );
		$p->brand_source = $brand_text;
		$brand_id        = (int) self::override( $product, 'brand_id' );
		if ( $brand_id ) {
			$p->brand_id = $brand_id;
			$p->brand_fa = $brand_text;
		}

		// Category.
		$map = $this->categories->resolve( $product );
		if ( $map ) {
			$p->category_id    = (int) $map['dk_category_id'];
			$p->category_title = (string) $map['dk_category_title'];
			$p->category_theme = $map['theme'] ? $map['theme'] : null;
		}
		if ( '' === $p->nature && '' !== $p->category_title ) {
			$p->nature = self::nature_from_category( $p->category_title );
		}

		$p->brand_other_id = 0;
		if ( $with_api && $this->api && $p->category_id ) {
			$this->load_category_data( $p, $product );
		}

		if ( ! $p->brand_id && '' !== $brand_text ) {
			$brand = $this->brands->resolve( $brand_text, array(), $p->brand_other_id );
			if ( $brand ) {
				$this->apply_brand( $p, $brand );
			}
		} elseif ( ! $p->brand_id && '' === $brand_text && $p->brand_other_id && ! $p->fake ) {
			$this->apply_brand(
				$p,
				array(
					'id'   => $p->brand_other_id,
					'fa'   => 'متفرقه',
					'en'   => 'Miscellaneous',
					'misc' => true,
				)
			);
		}

		// Images.
		$p->images = $this->images( $product );

		// Price & stock.
		$p->price_info = $this->price( $product );
		$p->price      = (int) $p->price_info['price'];
		$p->stock      = $this->stock( $product );

		// Variations.
		if ( 'variable' === $p->wc_type ) {
			$p->variations     = $this->variations( $product );
			$p->variant_values = array();
			foreach ( $p->variations as $v ) {
				foreach ( array( 'color', 'size' ) as $k ) {
					if ( '' !== $v[ $k ] ) {
						$p->variant_values[] = $v[ $k ];
					}
				}
			}
			$p->variant_values = array_values( array_unique( $p->variant_values ) );
			if ( ! $p->category_theme ) {
				$has_color         = (bool) array_filter( wp_list_pluck( $p->variations, 'color' ) );
				$has_size          = (bool) array_filter( wp_list_pluck( $p->variations, 'size' ) );
				$p->category_theme = $has_color ? 'colored' : ( $has_size ? 'sized' : null );
			}
		}

		/**
		 * Filter the prepared data before rules run.
		 *
		 * @param ProductData $p       Data.
		 * @param \WC_Product $product Product.
		 */
		return apply_filters( 'dkb_product_data', $p, $product );
	}

	/**
	 * Load binding data of the category from Digikala.
	 *
	 * @param ProductData $p       Data.
	 * @param \WC_Product $product Product.
	 */
	private function load_category_data( ProductData $p, $product ) {
		try {
			$v = $this->api->category_validation( $p->category_id );
		} catch ( ApiException $e ) {
			$p->category_errors[] = $e->full_message();
			return;
		}
		$p->has_category_data = true;
		$p->category_valid    = ! empty( $v['isValid'] );
		$p->category_errors   = isset( $v['errors'] ) ? array_map( 'strval', (array) $v['errors'] ) : array();
		$bind                 = isset( $v['bind'] ) ? (array) $v['bind'] : array();

		if ( isset( $bind['category_data']['categoryTheme'] ) ) {
			$p->category_theme = (string) $bind['category_data']['categoryTheme'];
		}
		if ( '' === $p->category_title && isset( $bind['category_data']['categoryTitle'] ) ) {
			$p->category_title = (string) $bind['category_data']['categoryTitle'];
			if ( '' === $p->nature ) {
				$p->nature = self::nature_from_category( $p->category_title );
			}
		}
		$p->allow_fake     = isset( $bind['allow_fake'] ) ? (bool) $bind['allow_fake'] : null;
		$p->brand_other_id = isset( $bind['brand_other_id'] ) ? (int) $bind['brand_other_id'] : 0;
		$p->mefa_type      = isset( $bind['category_mefa_type'] ) ? (string) $bind['category_mefa_type'] : 'none';
		if ( isset( $bind['dimension_config'] ) ) {
			$p->dimension_config = (array) $bind['dimension_config'];
		}
		if ( ! empty( $bind['category_product_types'] ) ) {
			foreach ( (array) $bind['category_product_types'] as $t ) {
				$p->available_product_types[ (int) $t['value'] ] = (string) $t['text'];
			}
		}
		if ( ! empty( $bind['divisions'] ) ) {
			$p->available_divisions = (array) $bind['divisions'];
		}
		if ( 'general' === $p->mefa_type && '' === $p->general_mefa_id && ! empty( $bind['general_mefa'] ) && 1 === count( (array) $bind['general_mefa'] ) ) {
			$only               = array_values( (array) $bind['general_mefa'] )[0];
			$p->general_mefa_id = isset( $only['value'] ) ? (string) $only['value'] : '';
		}

		// Brand within category brands.
		if ( ! $p->brand_id && '' !== $p->brand_source && ! empty( $bind['brands'] ) ) {
			$brand = $this->brands->resolve( $p->brand_source, (array) $bind['brands'], $p->brand_other_id );
			if ( $brand ) {
				$this->apply_brand( $p, $brand );
			}
		} elseif ( $p->brand_id && ! empty( $bind['brands'] ) ) {
			foreach ( (array) $bind['brands'] as $b ) {
				if ( (int) $b['id'] === (int) $p->brand_id ) {
					$p->brand_fa   = isset( $b['title_fa'] ) ? $b['title_fa'] : $p->brand_fa;
					$p->brand_en   = isset( $b['title_en'] ) ? $b['title_en'] : '';
					$p->brand_misc = (int) $b['id'] === $p->brand_other_id;
				}
			}
		}

		// Attributes.
		try {
			$flat                = AttributeMapper::flatten( $this->api->category_attributes( $p->category_id ) );
			$p->attribute_defs   = $flat['defs'];
			$p->attribute_groups = $flat['groups'];
			$p->attributes       = $this->attributes->resolve( $product, $flat['defs'], $this->attributes->for_category( $p->category_id ) );
		} catch ( ApiException $e ) {
			$p->category_errors[] = $e->full_message();
		}
	}

	/**
	 * Apply a resolved brand.
	 *
	 * @param ProductData $p     Data.
	 * @param array       $brand Brand.
	 */
	private function apply_brand( ProductData $p, array $brand ) {
		$p->brand_id   = (int) $brand['id'];
		$p->brand_fa   = (string) $brand['fa'];
		$p->brand_en   = (string) $brand['en'];
		$p->brand_misc = ! empty( $brand['misc'] ) || ( $p->brand_other_id && (int) $brand['id'] === (int) $p->brand_other_id );
	}

	/**
	 * Derive the product nature from a category title («گوشی موبایل» etc.).
	 *
	 * @param string $title Category title.
	 * @return string
	 */
	public static function nature_from_category( $title ) {
		$title = trim( preg_replace( '/\s*[\(\[].*?[\)\]]\s*/u', ' ', (string) $title ) );
		return Normalizer::normalize( $title );
	}

	/**
	 * Model from the configured source.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function model_from_source( $product ) {
		$source = (string) $this->settings->get( 'model_source', 'sku' );
		if ( 0 === strpos( $source, 'meta:' ) ) {
			return (string) $product->get_meta( substr( $source, 5 ) );
		}
		if ( 0 === strpos( $source, 'attribute:' ) ) {
			return (string) $product->get_attribute( substr( $source, 10 ) );
		}
		$model = (string) $product->get_attribute( 'model' ) ?: (string) $product->get_attribute( 'مدل' );
		return '' !== $model ? $model : (string) $product->get_sku();
	}

	/**
	 * Split textarea / array values into lines.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public static function lines( $value ) {
		if ( is_array( $value ) ) {
			$lines = $value;
		} else {
			$lines = preg_split( '/\r\n|\r|\n/', (string) $value );
		}
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', $lines ) ) ) );
	}

	/**
	 * Package dimensions in cm / g.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function dimensions( $product ) {
		$dim_unit    = $this->settings->get( 'dimension_unit_override' ) ?: get_option( 'woocommerce_dimension_unit', 'cm' );
		$weight_unit = get_option( 'woocommerce_weight_unit', 'kg' );
		return array(
			'length' => self::to_cm( (float) $product->get_length(), $dim_unit ),
			'width'  => self::to_cm( (float) $product->get_width(), $dim_unit ),
			'height' => self::to_cm( (float) $product->get_height(), $dim_unit ),
			'weight' => self::to_gram( (float) $product->get_weight(), $weight_unit ),
		);
	}

	/**
	 * Convert to centimeters.
	 *
	 * @param float  $value Value.
	 * @param string $unit  Unit.
	 * @return float
	 */
	public static function to_cm( $value, $unit ) {
		$factor = array(
			'm'  => 100,
			'cm' => 1,
			'mm' => 0.1,
			'in' => 2.54,
			'yd' => 91.44,
		);
		return round( $value * ( isset( $factor[ $unit ] ) ? $factor[ $unit ] : 1 ), 2 );
	}

	/**
	 * Convert to grams.
	 *
	 * @param float  $value Value.
	 * @param string $unit  Unit.
	 * @return float
	 */
	public static function to_gram( $value, $unit ) {
		$factor = array(
			'kg'  => 1000,
			'g'   => 1,
			'lbs' => 453.592,
			'oz'  => 28.3495,
		);
		return round( $value * ( isset( $factor[ $unit ] ) ? $factor[ $unit ] : 1 ), 1 );
	}

	/**
	 * Product images with cached analysis.
	 *
	 * @param \WC_Product $product Product.
	 * @return array
	 */
	public function images( $product ) {
		$ids = array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) );
		$ids = array_values( array_unique( $ids ) );
		$out = array();
		$processor = new ImageProcessor( $this->image_options() );
		foreach ( $ids as $index => $id ) {
			$path = get_attached_file( $id );
			if ( ! $path || ! file_exists( $path ) ) {
				continue;
			}
			$out[] = array(
				'attachment_id' => $id,
				'path'          => $path,
				'url'           => wp_get_attachment_url( $id ),
				'is_main'       => 0 === $index,
				'analysis'      => self::analysis( $id, $path, $processor ),
			);
		}
		return $out;
	}

	/**
	 * Image options from settings.
	 *
	 * @return array
	 */
	public function image_options() {
		return array(
			'min'       => (int) $this->settings->get( 'img_min' ),
			'target'    => (int) $this->settings->get( 'img_target' ),
			'max'       => (int) $this->settings->get( 'img_max' ),
			'quality'   => (int) $this->settings->get( 'img_quality' ),
			'fill'      => (float) $this->settings->get( 'img_fill' ),
			'max_bytes' => (int) $this->settings->get( 'img_max_bytes' ),
		);
	}

	/**
	 * Cached image analysis.
	 *
	 * @param int            $id        Attachment.
	 * @param string         $path      Path.
	 * @param ImageProcessor $processor Processor.
	 * @return array
	 */
	private static function analysis( $id, $path, ImageProcessor $processor ) {
		$key    = md5( $path . '|' . filemtime( $path ) );
		$cached = get_post_meta( $id, '_dkb_img_analysis', true );
		if ( is_array( $cached ) && isset( $cached['key'] ) && $cached['key'] === $key ) {
			return $cached['data'];
		}
		$data = $processor->analyze( $path );
		update_post_meta(
			$id,
			'_dkb_img_analysis',
			array(
				'key'  => $key,
				'data' => $data,
			)
		);
		return $data;
	}

	/**
	 * Price calculation for a product/variation.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param array       $extra   Extra calculator options (commission, min, max, price_type…).
	 * @return array
	 */
	public function price( $product, array $extra = array() ) {
		$s   = $this->settings;
		$raw = 'regular' === $s->get( 'price_source' ) ? $product->get_regular_price() : $product->get_price();
		if ( '' === $raw && $product->is_type( 'variable' ) ) {
			$raw = $product->get_variation_price( 'min' );
		}
		$commission = isset( $extra['commission'] ) ? (float) $extra['commission'] : (float) $s->get( 'default_commission' ) / 100;
		$result     = PriceCalculator::calculate(
			(float) $raw,
			array_merge(
				array(
					'currency'           => $s->currency(),
					'markup_percent'     => (float) $s->get( 'markup_percent' ),
					'markup_fixed'       => (int) $s->get( 'markup_fixed' ),
					'commission'         => $commission,
					'compensate'         => (bool) $s->get( 'commission_compensation' ),
					'step'               => (int) $s->get( 'rounding_step' ),
					'mode'               => (string) $s->get( 'rounding_mode' ),
					'clamp'              => (bool) $s->get( 'clamp_to_boundary' ),
					'min_profit_percent' => (float) $s->get( 'min_profit_percent' ),
				),
				$extra
			)
		);
		/**
		 * Filter the calculated Digikala price (Rial).
		 *
		 * @param array       $result  Calculation.
		 * @param \WC_Product $product Product.
		 */
		return apply_filters( 'dkb_calculated_price', $result, $product );
	}

	/**
	 * Stock to announce on Digikala.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return int
	 */
	public function stock( $product ) {
		$s = $this->settings;
		if ( ! $product->is_in_stock() || ! $product->is_purchasable() && ! $product->is_type( 'variable' ) ) {
			$stock = 0;
		} elseif ( $product->managing_stock() ) {
			$stock = max( 0, (int) $product->get_stock_quantity() - (int) $s->get( 'stock_buffer' ) );
		} else {
			$stock = (int) $s->get( 'unmanaged_stock' );
		}
		$cap = (int) $s->get( 'stock_max' );
		if ( $cap > 0 ) {
			$stock = min( $stock, $cap );
		}
		/**
		 * Filter the seller stock sent to Digikala.
		 *
		 * @param int         $stock   Stock.
		 * @param \WC_Product $product Product.
		 */
		return (int) apply_filters( 'dkb_seller_stock', $stock, $product );
	}

	/**
	 * Variations info.
	 *
	 * @param \WC_Product_Variable $product Product.
	 * @return array
	 */
	public function variations( $product ) {
		$out = array();
		foreach ( $product->get_children() as $child_id ) {
			$v = wc_get_product( $child_id );
			if ( ! $v || 'publish' !== get_post_status( $child_id ) && 'private' !== get_post_status( $child_id ) ) {
				continue;
			}
			$color = '';
			$hex   = '';
			$size  = '';
			$label = array();
			foreach ( $v->get_variation_attributes( false ) as $attr => $value ) {
				$name     = (string) $attr;
				$readable = $value;
				if ( taxonomy_exists( $name ) ) {
					$term = get_term_by( 'slug', $value, $name );
					if ( $term ) {
						$readable = $term->name;
						if ( self::is_key( $name, self::COLOR_KEYS ) ) {
							$hex = self::term_hex( $term->term_id );
						}
					}
				} else {
					$readable = urldecode( (string) $value );
				}
				$label[] = $readable;
				$title   = taxonomy_exists( $name ) ? wc_attribute_label( $name ) : $name;
				if ( self::is_key( $name, self::COLOR_KEYS ) || self::is_key( $title, self::COLOR_KEYS ) ) {
					$color = (string) $readable;
				} elseif ( self::is_key( $name, self::SIZE_KEYS ) || self::is_key( $title, self::SIZE_KEYS ) ) {
					$size = (string) $readable;
				}
			}
			$price = $this->price( $v );
			$out[] = array(
				'wc_id'          => $v->get_id(),
				'sku'            => (string) $v->get_sku(),
				'label'          => implode( ' / ', array_filter( $label ) ),
				'color'          => $color,
				'color_hex'      => $hex,
				'size'           => $size,
				'price'          => (int) $price['price'],
				'price_warnings' => $price['warnings'],
				'stock'          => $this->stock( $v ),
				'package'        => self::dimensions_or_parent( $v, $product, $this ),
			);
		}
		return $out;
	}

	/**
	 * Variation dimensions (WooCommerce inherits from parent).
	 *
	 * @param \WC_Product   $v       Variation.
	 * @param \WC_Product   $parent  Parent.
	 * @param ProductMapper $mapper  Mapper.
	 * @return array
	 */
	private static function dimensions_or_parent( $v, $parent, ProductMapper $mapper ) {
		$d = $mapper->dimensions( $v );
		return array_sum( $d ) > 0 ? $d : $mapper->dimensions( $parent );
	}

	/**
	 * Name matches one of the keys.
	 *
	 * @param string   $name Name.
	 * @param string[] $keys Keys.
	 * @return bool
	 */
	private static function is_key( $name, array $keys ) {
		$name = Normalizer::fold( str_replace( array( 'attribute_', 'pa_' ), '', urldecode( (string) $name ) ) );
		foreach ( $keys as $k ) {
			if ( Normalizer::fold( str_replace( 'pa_', '', $k ) ) === $name ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hex color of a term from popular swatch plugins.
	 *
	 * @param int $term_id Term.
	 * @return string
	 */
	private static function term_hex( $term_id ) {
		foreach ( array( 'color', 'product_attribute_color', 'pa_color', 'swatch_color', 'term_color', '_swatch_color', 'cfvsw_color' ) as $key ) {
			$value = get_term_meta( $term_id, $key, true );
			if ( is_string( $value ) && preg_match( '/^#?[0-9a-f]{3,6}$/i', trim( $value ) ) ) {
				return '#' . ltrim( trim( $value ), '#' );
			}
		}
		return '';
	}

	/**
	 * Persist auto-fixed fields as overrides so the user can review them.
	 *
	 * @param \WC_Product $product Product.
	 * @param ProductData $p       Fixed data.
	 */
	public static function persist_overrides( $product, ProductData $p ) {
		$product->update_meta_data( self::META['title_fa'], $p->title_fa );
		$product->update_meta_data( self::META['title_en'], $p->title_en );
		$product->update_meta_data( self::META['description'], $p->description );
		$product->update_meta_data( self::META['advantages'], implode( "\n", $p->advantages ) );
		$product->update_meta_data( self::META['disadvantages'], implode( "\n", $p->disadvantages ) );
		if ( '' !== $p->model ) {
			$product->update_meta_data( self::META['model'], $p->model );
		}
		if ( $p->brand_id ) {
			$product->update_meta_data( self::META['brand_id'], $p->brand_id );
			$product->update_meta_data( self::META['brand'], $p->brand_fa );
		}
		$product->update_meta_data( self::META['fake'], $p->fake ? 'yes' : 'no' );
		if ( $p->product_type_ids ) {
			$product->update_meta_data( self::META['product_type_ids'], $p->product_type_ids );
		}
		$product->save_meta_data();
	}
}
