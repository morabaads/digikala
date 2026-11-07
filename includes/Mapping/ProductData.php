<?php
/**
 * Normalized, marketplace-neutral representation of a product being prepared for Digikala.
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

/**
 * DTO (public properties on purpose: rules read & auto-fix them).
 */
class ProductData {

	/** @var int */
	public $wc_id = 0;
	/** @var string simple|variable */
	public $wc_type = 'simple';

	/** @var string */
	public $title_fa = '';
	/** @var string */
	public $title_en = '';
	/** @var string Product nature, e.g. «گوشی موبایل». */
	public $nature = '';
	/** @var string */
	public $nature_en = '';
	/** @var string Plain text. */
	public $description = '';
	/** @var string Raw HTML description (for extracting lists). */
	public $description_html = '';
	/** @var string[] */
	public $advantages = array();
	/** @var string[] */
	public $disadvantages = array();

	/** @var int|null */
	public $brand_id = null;
	/** @var string */
	public $brand_fa = '';
	/** @var string */
	public $brand_en = '';
	/** @var int Digikala id of the «متفرقه» brand in this category. */
	public $brand_other_id = 0;
	/** @var bool Brand is «متفرقه». */
	public $brand_misc = false;
	/** @var string Raw brand text from WooCommerce. */
	public $brand_source = '';

	/** @var string */
	public $model = '';
	/** @var bool */
	public $fake = false;
	/** @var int[] */
	public $fake_reasons = array();
	/** @var bool */
	public $is_iranian = false;

	/** @var int|null */
	public $category_id = null;
	/** @var string */
	public $category_title = '';
	/** @var bool|null */
	public $category_valid = null;
	/** @var string[] */
	public $category_errors = array();
	/** @var string|null no_color_no_size|colored|sized */
	public $category_theme = null;
	/** @var bool|null */
	public $allow_fake = null;
	/** @var string none|general|exclusive */
	public $mefa_type = 'none';
	/** @var string */
	public $general_mefa_id = '';
	/** @var string */
	public $exclusive_mefa_id = '';
	/** @var int[] */
	public $product_type_ids = array();
	/** @var array value=>text */
	public $available_product_types = array();
	/** @var int|null */
	public $division_id = null;
	/** @var array */
	public $available_divisions = array();
	/** @var int[] */
	public $product_classes = array();
	/** @var array {length:{min,max},width:{},height:{},weight:{}} */
	public $dimension_config = array();
	/** @var string Title formula hint from Digikala. */
	public $title_formula = '';

	/** @var array{length:float,width:float,height:float,weight:float} Package, cm & g. */
	public $package = array(
		'length' => 0.0,
		'width'  => 0.0,
		'height' => 0.0,
		'weight' => 0.0,
	);
	/** @var array{length:float,width:float,height:float,weight:float} Item itself, cm & g. */
	public $item = array(
		'length' => 0.0,
		'width'  => 0.0,
		'height' => 0.0,
		'weight' => 0.0,
	);

	/** @var array<int,mixed> dk attribute id => value */
	public $attributes = array();
	/** @var array<int,array> dk attribute id => definition {id,title,type,required,values,unit} */
	public $attribute_defs = array();
	/** @var array<int,array> dk attribute id => group id */
	public $attribute_groups = array();

	/**
	 * Images: [{attachment_id, path, url, is_main, analysis}].
	 *
	 * @var array
	 */
	public $images = array();

	/** @var int Price in Rial. */
	public $price = 0;
	/** @var array Price calculation details. */
	public $price_info = array();
	/** @var int */
	public $stock = 0;

	/** @var string[] Variation option values (colors/sizes) that must not appear in titles. */
	public $variant_values = array();

	/**
	 * Variations: [{wc_id, sku, color, color_hex, size, price, stock, attributes}].
	 *
	 * @var array
	 */
	public $variations = array();

	/** @var bool Whether Digikala category binding data was loaded. */
	public $has_category_data = false;

	/**
	 * Snapshot of content fields (for change detection).
	 *
	 * @return array
	 */
	public function fingerprint_fields() {
		return array(
			$this->title_fa,
			$this->title_en,
			$this->description,
			$this->advantages,
			$this->disadvantages,
			$this->brand_id,
			$this->model,
			$this->fake,
			$this->is_iranian,
			$this->category_id,
			$this->package,
			$this->item,
			$this->attributes,
			array_map(
				static function ( $img ) {
					return isset( $img['attachment_id'] ) ? $img['attachment_id'] : ( isset( $img['path'] ) ? $img['path'] : '' );
				},
				$this->images
			),
			$this->general_mefa_id,
		);
	}

	/**
	 * Content hash.
	 *
	 * @return string
	 */
	public function hash() {
		return sha1( serialize( $this->fingerprint_fields() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}
}
