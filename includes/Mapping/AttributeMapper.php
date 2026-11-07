<?php
/**
 * Digikala attribute ↔ WooCommerce attribute/meta mapping.
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

use DKBridge\Matching\Similarity;
use DKBridge\Rules\Checks\AttributeRule;
use DKBridge\Text\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Attribute mapper.
 */
class AttributeMapper {

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dkb_attribute_map';
	}

	/**
	 * Flatten the attributes endpoint response into id => definition (+ group).
	 *
	 * @param array $response category_attributes() data.
	 * @return array{defs:array<int,array>,groups:array<int,int>,meta:array}
	 */
	public static function flatten( array $response ) {
		$defs   = array();
		$groups = array();
		$cga    = isset( $response['category_group_attributes'] ) ? (array) $response['category_group_attributes'] : array();
		foreach ( $cga as $group_id => $group ) {
			$attrs = isset( $group['attributes'] ) ? (array) $group['attributes'] : array();
			foreach ( $attrs as $attr_id => $attr ) {
				$id = isset( $attr['id'] ) ? (int) $attr['id'] : (int) $attr_id;
				if ( ! $id ) {
					continue;
				}
				$attr['id']          = $id;
				$attr['group_title'] = isset( $group['group_title'] ) ? $group['group_title'] : '';
				$defs[ $id ]         = $attr;
				$groups[ $id ]       = (int) $group_id;
			}
		}
		$meta = $response;
		unset( $meta['category_group_attributes'] );
		return array(
			'defs'   => $defs,
			'groups' => $groups,
			'meta'   => $meta,
		);
	}

	/**
	 * Stored mappings for a category.
	 *
	 * @param int $dk_category_id Category.
	 * @return array<int,array> attribute id => {source_type, source_key, value_map}
	 */
	public function for_category( $dk_category_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE dk_category_id = %d', $dk_category_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row['value_map']                     = $row['value_map'] ? (array) json_decode( $row['value_map'], true ) : array();
			$out[ (int) $row['dk_attribute_id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Save a mapping row.
	 *
	 * @param int    $dk_category_id Category.
	 * @param int    $attribute_id   Attribute.
	 * @param string $source_type    attribute|meta|static|none.
	 * @param string $source_key     Key / static value.
	 * @param array  $value_map      Woo value => DK option id.
	 */
	public function save( $dk_category_id, $attribute_id, $source_type, $source_key, array $value_map = array() ) {
		global $wpdb;
		if ( 'none' === $source_type || '' === $source_key ) {
			$wpdb->delete(
				self::table(),
				array(
					'dk_category_id'  => (int) $dk_category_id,
					'dk_attribute_id' => (int) $attribute_id,
				)
			);
			return;
		}
		$wpdb->replace(
			self::table(),
			array(
				'dk_category_id'  => (int) $dk_category_id,
				'dk_attribute_id' => (int) $attribute_id,
				'source_type'     => in_array( $source_type, array( 'attribute', 'meta', 'static' ), true ) ? $source_type : 'attribute',
				'source_key'      => sanitize_text_field( $source_key ),
				'value_map'       => $value_map ? wp_json_encode( $value_map, JSON_UNESCAPED_UNICODE ) : null,
				'updated_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * WooCommerce attributes of a product as label => [values].
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,array{key:string,label:string,values:string[]}>
	 */
	public static function product_attributes( $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( ! is_object( $attr ) ) {
				continue;
			}
			if ( $attr->is_taxonomy() ) {
				$label  = wc_attribute_label( $attr->get_name() );
				$values = wc_get_product_terms( $product->get_id(), $attr->get_name(), array( 'fields' => 'names' ) );
			} else {
				$label  = $attr->get_name();
				$values = $attr->get_options();
			}
			$out[ (string) $key ] = array(
				'key'    => (string) $key,
				'label'  => (string) $label,
				'values' => array_values( array_map( 'strval', (array) $values ) ),
			);
		}
		return $out;
	}

	/**
	 * Resolve DK attribute values for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $defs    DK definitions.
	 * @param array       $maps    Stored mappings.
	 * @return array<int,mixed>
	 */
	public function resolve( $product, array $defs, array $maps ) {
		$woo    = self::product_attributes( $product );
		$labels = array();
		foreach ( $woo as $key => $info ) {
			$labels[ $key ] = $info['label'];
		}
		$overrides = (array) $product->get_meta( '_dkb_attributes' );
		$values    = array();

		foreach ( $defs as $id => $def ) {
			if ( isset( $overrides[ $id ] ) && '' !== $overrides[ $id ] && array() !== $overrides[ $id ] ) {
				$values[ $id ] = $overrides[ $id ];
				continue;
			}
			$raw = null;
			$map = isset( $maps[ $id ] ) ? $maps[ $id ] : null;
			if ( $map ) {
				switch ( $map['source_type'] ) {
					case 'static':
						$raw = $map['source_key'];
						break;
					case 'meta':
						$raw = $product->get_meta( $map['source_key'] );
						if ( '' === $raw && $product->get_parent_id() ) {
							$raw = get_post_meta( $product->get_parent_id(), $map['source_key'], true );
						}
						break;
					default:
						$raw = isset( $woo[ $map['source_key'] ] ) ? $woo[ $map['source_key'] ]['values'] : null;
				}
			} else {
				// Automatic: attribute label similar to the DK attribute title.
				$title = isset( $def['title'] ) ? (string) $def['title'] : '';
				if ( '' !== $title && $labels ) {
					$best = Similarity::best( $title, $labels, 0.82 );
					if ( null !== $best['key'] ) {
						$raw = $woo[ $best['key'] ]['values'];
					}
				}
			}
			if ( null === $raw || '' === $raw || array() === $raw ) {
				continue;
			}
			$values[ $id ] = self::coerce( $def, $raw, $map ? (array) $map['value_map'] : array() );
		}
		return $values;
	}

	/**
	 * Convert a raw WooCommerce value to the shape a DK attribute expects.
	 *
	 * @param array $def       Definition.
	 * @param mixed $raw       Raw value (string or list).
	 * @param array $value_map Explicit value map.
	 * @return mixed
	 */
	public static function coerce( array $def, $raw, array $value_map = array() ) {
		$list = is_array( $raw ) ? array_values( $raw ) : array( $raw );
		$type = isset( $def['type'] ) ? strtolower( (string) $def['type'] ) : 'text';
		if ( AttributeRule::is_select( $def ) ) {
			$options = AttributeRule::options( $def );
			$out     = array();
			foreach ( $list as $v ) {
				$v = (string) $v;
				if ( isset( $value_map[ $v ] ) ) {
					$out[] = (string) $value_map[ $v ];
					continue;
				}
				if ( isset( $options[ $v ] ) ) {
					$out[] = $v;
					continue;
				}
				$best  = Similarity::best( $v, $options, 0.8 );
				$out[] = null !== $best['key'] ? (string) $best['key'] : $v;
			}
			$out = array_values( array_unique( $out ) );
			return in_array( $type, array( 'multiselect', 'multi_select', 'checkbox' ), true ) ? $out : $out[0];
		}
		$text = Normalizer::normalize( implode( '، ', array_map( 'strval', $list ) ) );
		if ( in_array( $type, array( 'number', 'numeric', 'integer', 'float', 'decimal' ), true ) && preg_match( '/[0-9۰-۹][0-9۰-۹.,]*/u', $text, $m ) ) {
			return str_replace( ',', '.', Normalizer::latin_digits( $m[0] ) );
		}
		if ( in_array( $type, array( 'bool', 'boolean' ), true ) ) {
			return in_array( Normalizer::fold( $text ), array( '1', 'yes', 'true', 'بله', 'دارد', 'هست' ), true );
		}
		return $text;
	}
}
