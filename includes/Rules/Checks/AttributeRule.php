<?php
/**
 * Category attribute rules.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules\Checks;

use DKBridge\Mapping\ProductData;
use DKBridge\Matching\Similarity;
use DKBridge\Rules\Issue;
use DKBridge\Rules\Rule;
use DKBridge\Text\Normalizer;

/**
 * attributes section: required attributes filled, select values valid.
 */
class AttributeRule extends Rule {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function id() {
		return 'attributes';
	}

	/**
	 * Options of a select attribute: id => text.
	 *
	 * @param array $def Definition.
	 * @return array<string,string>
	 */
	public static function options( array $def ) {
		$out = array();
		if ( empty( $def['values'] ) || ! is_array( $def['values'] ) ) {
			return $out;
		}
		foreach ( $def['values'] as $key => $value ) {
			if ( is_array( $value ) ) {
				$out[ (string) $key ] = isset( $value['text'] ) ? (string) $value['text'] : (string) $key;
			} elseif ( is_scalar( $value ) ) {
				$out[ (string) $value ] = (string) $value;
			}
		}
		return $out;
	}

	/**
	 * Whether the attribute uses predefined options.
	 *
	 * @param array $def Definition.
	 * @return bool
	 */
	public static function is_select( array $def ) {
		$type = isset( $def['type'] ) ? strtolower( (string) $def['type'] ) : '';
		return in_array( $type, array( 'select', 'multiselect', 'multi_select', 'radio', 'checkbox' ), true ) && self::options( $def );
	}

	/**
	 * Check.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	public function check( ProductData $p ) {
		$issues = array();
		foreach ( $p->attribute_defs as $id => $def ) {
			$value   = array_key_exists( $id, $p->attributes ) ? $p->attributes[ $id ] : null;
			$title   = isset( $def['title'] ) ? $def['title'] : ( '#' . $id );
			$empty   = null === $value || '' === $value || array() === $value;
			$require = ! empty( $def['required'] );
			if ( $empty ) {
				if ( $require ) {
					$issues[] = $this->issue( 'attr.required', Issue::ERROR, 'attributes', sprintf( 'ویژگی اجباری «%s» مقدار ندارد.', $title ), false, array( 'attribute_id' => $id ) );
				}
				continue;
			}
			if ( self::is_select( $def ) ) {
				$options = self::options( $def );
				foreach ( (array) $value as $v ) {
					if ( ! isset( $options[ (string) $v ] ) ) {
						$best     = Similarity::best( (string) $v, $options, 0.8 );
						$issues[] = $this->issue(
							'attr.invalid_option',
							$require ? Issue::ERROR : Issue::WARNING,
							'attributes',
							sprintf( 'مقدار «%s» برای ویژگی «%s» در فهرست گزینه‌های دیجی‌کالا نیست.%s', $v, $title, null !== $best['key'] ? sprintf( ' نزدیک‌ترین گزینه: «%s».', $options[ $best['key'] ] ) : '' ),
							null !== $best['key'],
							array(
								'attribute_id' => $id,
								'value'        => $v,
								'suggestion'   => $best['key'],
							)
						);
					}
				}
			} elseif ( is_string( $value ) ) {
				if ( Normalizer::normalize( $value ) !== $value ) {
					$issues[] = $this->issue( 'attr.normalize', Issue::INFO, 'attributes', sprintf( 'مقدار ویژگی «%s» نرمال‌سازی می‌شود.', $title ), true, array( 'attribute_id' => $id ) );
				}
				if ( ! empty( $def['unit'] ) && preg_match( '/^\s*[0-9۰-۹.,]+\s*\p{L}+/u', $value ) ) {
					$issues[] = $this->issue( 'attr.unit', Issue::INFO, 'attributes', sprintf( 'واحد ویژگی «%s» (%s) ثابت است؛ فقط عدد ارسال می‌شود.', $title, $def['unit'] ), true, array( 'attribute_id' => $id ) );
				}
			}
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
			$id = isset( $issue->data['attribute_id'] ) ? $issue->data['attribute_id'] : null;
			if ( null === $id || ! array_key_exists( $id, $p->attributes ) ) {
				continue;
			}
			switch ( $issue->code ) {
				case 'attr.invalid_option':
					if ( null !== $issue->data['suggestion'] ) {
						$values = (array) $p->attributes[ $id ];
						foreach ( $values as $i => $v ) {
							if ( (string) $v === (string) $issue->data['value'] ) {
								$values[ $i ] = $issue->data['suggestion'];
							}
						}
						$p->attributes[ $id ] = is_array( $p->attributes[ $id ] ) ? array_values( array_unique( $values ) ) : $values[0];
						$changes[]            = sprintf( 'ویژگی #%s: «%s» با نزدیک‌ترین گزینه جایگزین شد.', $id, $issue->data['value'] );
					}
					break;
				case 'attr.normalize':
					$p->attributes[ $id ] = Normalizer::normalize( $p->attributes[ $id ] );
					break;
				case 'attr.unit':
					if ( preg_match( '/[0-9۰-۹][0-9۰-۹.,]*/u', $p->attributes[ $id ], $m ) ) {
						$p->attributes[ $id ] = str_replace( ',', '.', Normalizer::latin_digits( $m[0] ) );
						$changes[]            = sprintf( 'ویژگی #%s: واحد حذف شد.', $id );
					}
					break;
			}
		}
		return $changes;
	}
}
