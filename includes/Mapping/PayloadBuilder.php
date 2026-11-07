<?php
/**
 * Converts ProductData to Digikala request bodies.
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

/**
 * Payload builder (pure).
 */
class PayloadBuilder {

	/**
	 * Body for POST /product-creation/product/detail/validation.
	 *
	 * @param ProductData $p        Data.
	 * @param int|null    $draft_id Existing draft.
	 * @return array
	 */
	public static function details( ProductData $p, $draft_id = null ) {
		$body = array(
			'category_id'    => (int) $p->category_id,
			'brand_id'       => (int) $p->brand_id,
			'model'          => (string) $p->model,
			'is_iranian'     => (bool) $p->is_iranian,
			'fake'           => (bool) $p->fake,
			// Package dimensions: centimeters / grams, integers, at least 1.
			'package_length' => self::int_dim( $p->package['length'] ),
			'package_width'  => self::int_dim( $p->package['width'] ),
			'package_height' => self::int_dim( $p->package['height'] ),
			'package_weight' => self::int_dim( $p->package['weight'] ),
			'description'    => (string) $p->description,
			'advantages'     => array_values( $p->advantages ),
			'disadvantages'  => array_values( $p->disadvantages ),
		);
		if ( $p->product_type_ids ) {
			$body['product_type_ids'] = array_values( array_map( 'intval', $p->product_type_ids ) );
		}
		if ( $p->product_classes ) {
			$body['product_classes'] = array_values( array_map( 'intval', $p->product_classes ) );
		}
		if ( $p->division_id ) {
			$body['division_id'] = (int) $p->division_id;
		}
		if ( $p->fake && $p->fake_reasons ) {
			$body['fake_reasons'] = array_values( array_map( 'intval', $p->fake_reasons ) );
		}
		if ( '' !== (string) $p->general_mefa_id ) {
			$body['general_mefa_id'] = (string) $p->general_mefa_id;
		}
		if ( '' !== (string) $p->exclusive_mefa_id ) {
			$body['exclusive_mefa_id'] = (string) $p->exclusive_mefa_id;
		}
		if ( $draft_id ) {
			$body['draft_product_id'] = (int) $draft_id;
		}
		return $body;
	}

	/**
	 * Body for POST /product-creation/attributes.
	 *
	 * @param ProductData $p        Data.
	 * @param int         $draft_id Draft.
	 * @return array
	 */
	public static function attributes( ProductData $p, $draft_id ) {
		$list = array();
		foreach ( $p->attributes as $id => $value ) {
			if ( null === $value || '' === $value || array() === $value ) {
				continue;
			}
			$list[] = array(
				'id'    => (int) $id,
				'value' => self::attribute_value( $value ),
			);
		}
		return array(
			'draft_product_id' => (int) $draft_id,
			'length'           => (float) ( $p->item['length'] > 0 ? $p->item['length'] : $p->package['length'] ),
			'width'            => (float) ( $p->item['width'] > 0 ? $p->item['width'] : $p->package['width'] ),
			'height'           => (float) ( $p->item['height'] > 0 ? $p->item['height'] : $p->package['height'] ),
			'weight'           => (float) ( $p->item['weight'] > 0 ? $p->item['weight'] : $p->package['weight'] ),
			'attributes'       => $list,
		);
	}

	/**
	 * Body for POST /product-creation/auto-title/save.
	 *
	 * @param ProductData $p        Data.
	 * @param int         $draft_id Draft.
	 * @return array
	 */
	public static function title( ProductData $p, $draft_id ) {
		$body = array(
			'draft_product_id' => (int) $draft_id,
			'title_fa'         => (string) $p->title_fa,
			'description'      => (string) $p->description,
			'advantages'       => array_values( $p->advantages ),
			'disadvantages'    => array_values( $p->disadvantages ),
		);
		if ( '' !== $p->title_en ) {
			$body['title_en'] = (string) $p->title_en;
		}
		return $body;
	}

	/**
	 * Body for POST /product-creation/save.
	 *
	 * @param ProductData $p         Data.
	 * @param int         $draft_id  Draft.
	 * @param string[]    $image_ids Uploaded image ids in order (first = main).
	 * @return array
	 */
	public static function save( ProductData $p, $draft_id, array $image_ids ) {
		$image_ids = array_values( array_filter( array_map( 'strval', $image_ids ) ) );
		return array(
			'category_id'      => (int) $p->category_id,
			'draft_product_id' => (int) $draft_id,
			'use_temp_images'  => false,
			'photos_detail'    => array(
				'main_image' => $image_ids ? $image_ids[0] : '',
				'order'      => implode( ',', $image_ids ),
				'images'     => array_map(
					static function ( $id ) {
						return array(
							'encrypted_id' => $id,
							'active'       => true,
						);
					},
					$image_ids
				),
			),
		);
	}

	/**
	 * Body for POST /variants/creation/{product_id}.
	 *
	 * @param array $row      Variation row {price, stock, sku, wc_id, package}.
	 * @param array $defaults {warranty_id, site, shipping_type, lead_time, seller_lead_time, order_limit, lead_time_max}.
	 * @param array $theme    {color_id?, size_id?, theme_values?}.
	 * @return array
	 */
	public static function variant( array $row, array $defaults, array $theme = array() ) {
		$lead = (int) $defaults['lead_time'];
		if ( ! empty( $defaults['lead_time_max'] ) ) {
			$lead = min( $lead, (int) $defaults['lead_time_max'] );
		}
		$body = array(
			'is_active'                => (int) $row['stock'] > 0,
			'order_limit'              => max( 1, (int) $defaults['order_limit'] ),
			'marketplace_seller_stock' => max( 0, (int) $row['stock'] ),
			'supplier_code'            => self::supplier_code( $row ),
			'price'                    => (int) $row['price'],
			'warranty_id'              => (int) $defaults['warranty_id'],
			'site'                     => (string) $defaults['site'],
			'shipping_type'            => (string) $defaults['shipping_type'],
			'lead_time'                => max( 1, $lead ),
		);
		if ( 'seller' === $defaults['shipping_type'] ) {
			$body['fbs_lead_time'] = max( 1, (int) $defaults['seller_lead_time'] );
		}
		if ( ! empty( $row['package'] ) && array_sum( $row['package'] ) > 0 ) {
			$body['package_length'] = self::int_dim( $row['package']['length'] );
			$body['package_width']  = self::int_dim( $row['package']['width'] );
			$body['package_height'] = self::int_dim( $row['package']['height'] );
			$body['package_weight'] = self::int_dim( $row['package']['weight'] );
		}
		foreach ( array( 'color_id', 'size_id' ) as $k ) {
			if ( ! empty( $theme[ $k ] ) ) {
				$body[ $k ] = (int) $theme[ $k ];
			}
		}
		if ( ! empty( $theme['theme_values'] ) ) {
			$body['theme_values'] = array_values( $theme['theme_values'] );
		}
		return $body;
	}

	/**
	 * Supplier code linking a Digikala variant back to WooCommerce.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public static function supplier_code( array $row ) {
		$sku = isset( $row['sku'] ) ? trim( (string) $row['sku'] ) : '';
		if ( '' !== $sku && strlen( $sku ) <= 40 ) {
			return $sku;
		}
		return 'wc-' . (int) $row['wc_id'];
	}

	/**
	 * Integer dimension ≥ 1.
	 *
	 * @param float $v Value.
	 * @return int
	 */
	private static function int_dim( $v ) {
		return max( 1, (int) ceil( (float) $v ) );
	}

	/**
	 * Attribute value encoding: numeric strings stay strings, option ids → int.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function attribute_value( $value ) {
		if ( is_array( $value ) ) {
			return array_values(
				array_map(
					static function ( $v ) {
						return ctype_digit( (string) $v ) ? (int) $v : $v;
					},
					$value
				)
			);
		}
		if ( is_bool( $value ) ) {
			return $value;
		}
		return ctype_digit( (string) $value ) ? (int) $value : (string) $value;
	}
}
