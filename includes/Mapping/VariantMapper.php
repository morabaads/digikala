<?php
/**
 * Matches WooCommerce variation values to Digikala theme values (colors/sizes).
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

use DKBridge\Matching\Similarity;

/**
 * Variant mapper (pure).
 */
class VariantMapper {

	/**
	 * Find the theme value id for a variation.
	 *
	 * @param string $name   Woo value name.
	 * @param string $hex    Hex color (optional).
	 * @param array  $values Theme values [{id,titleFa,titleEn,value:{hex,value}}].
	 * @param bool   $color  Is a color theme.
	 * @return array{id:int|null,label:string,score:float,method:string}
	 */
	public static function match( $name, $hex, array $values, $color ) {
		$labels = array();
		$hexes  = array();
		foreach ( $values as $v ) {
			if ( isset( $v['active'] ) && false === $v['active'] ) {
				continue;
			}
			$id = (int) $v['id'];
			if ( ! empty( $v['titleFa'] ) ) {
				$labels[ $id . '|fa' ] = (string) $v['titleFa'];
			}
			if ( ! empty( $v['titleEn'] ) ) {
				$labels[ $id . '|en' ] = (string) $v['titleEn'];
			}
			if ( ! empty( $v['value']['value'] ) ) {
				$labels[ $id . '|v' ] = (string) $v['value']['value'];
			}
			if ( $color && ! empty( $v['value']['hex'] ) ) {
				$hexes[ $id ] = (string) $v['value']['hex'];
			}
		}
		$result = array(
			'id'     => null,
			'label'  => '',
			'score'  => 0.0,
			'method' => '',
		);
		if ( '' !== trim( (string) $name ) && $labels ) {
			$best = Similarity::best( $name, $labels, $color ? 0.8 : 0.95 );
			if ( null !== $best['key'] ) {
				$result = array(
					'id'     => (int) explode( '|', $best['key'] )[0],
					'label'  => $labels[ $best['key'] ],
					'score'  => $best['score'],
					'method' => 'name',
				);
			}
		}
		if ( null === $result['id'] && $color && '' !== $hex && $hexes ) {
			$near = Similarity::nearest_color( $hex, $hexes );
			if ( null !== $near['key'] && $near['distance'] < 120 ) {
				$label = '';
				foreach ( $values as $v ) {
					if ( (int) $v['id'] === (int) $near['key'] ) {
						$label = isset( $v['titleFa'] ) ? $v['titleFa'] : '';
					}
				}
				$result = array(
					'id'     => (int) $near['key'],
					'label'  => $label,
					'score'  => round( 1 - $near['distance'] / 765, 3 ),
					'method' => 'hex',
				);
			}
		}
		return $result;
	}

	/**
	 * Resolve theme ids for every variation given the variant creation config.
	 *
	 * @param array    $variations Rows from ProductMapper::variations().
	 * @param array    $config     variant_config() data.
	 * @param callable $loader     function( int $theme_id ): array theme values.
	 * @return array{rows:array,errors:string[]}
	 */
	public static function resolve( array $variations, array $config, $loader ) {
		$theme_type = isset( $config['variant_theme'] ) ? (string) $config['variant_theme'] : 'no_color_no_size';
		$themes     = isset( $config['themes'] ) ? (array) $config['themes'] : array();
		$errors     = array();
		$rows       = array();
		$cache      = array();

		if ( 'no_color_no_size' === $theme_type || ! $themes ) {
			// One variant only.
			if ( $variations ) {
				$first          = $variations[0];
				$first['theme'] = array();
				$rows[]         = $first;
			}
			return array(
				'rows'   => $rows,
				'errors' => $errors,
			);
		}

		foreach ( $variations as $row ) {
			$theme_values = array();
			$ok           = true;
			foreach ( $themes as $theme ) {
				$theme_id = (int) $theme['id'];
				$is_color = isset( $theme['themeType'] ) ? 'colored' === $theme['themeType'] : 'colored' === $theme_type;
				if ( ! isset( $cache[ $theme_id ] ) ) {
					$cache[ $theme_id ] = (array) call_user_func( $loader, $theme_id );
				}
				$name  = $is_color ? $row['color'] : $row['size'];
				$match = self::match( $name, $is_color ? $row['color_hex'] : '', $cache[ $theme_id ], $is_color );
				if ( null === $match['id'] ) {
					$ok       = false;
					$errors[] = sprintf(
						'%s «%s» (تنوع %s) در فهرست %s دیجی‌کالا پیدا نشد؛ آن را نگاشت کنید یا درخواست %s جدید بدهید.',
						$is_color ? 'رنگ' : 'سایز',
						'' !== $name ? $name : '—',
						$row['label'],
						isset( $theme['label'] ) ? $theme['label'] : '',
						$is_color ? 'رنگ' : 'سایز'
					);
					continue;
				}
				$theme_values[] = array(
					'theme_id'       => $theme_id,
					'theme_value_id' => $match['id'],
					'is_color'       => $is_color,
				);
			}
			if ( ! $ok ) {
				continue;
			}
			$theme = array( 'theme_values' => array() );
			foreach ( $theme_values as $tv ) {
				$theme[ $tv['is_color'] ? 'color_id' : 'size_id' ] = $tv['theme_value_id'];
				$theme['theme_values'][]                           = array(
					'theme_id'       => $tv['theme_id'],
					'theme_value_id' => $tv['theme_value_id'],
				);
			}
			if ( count( $theme['theme_values'] ) < 2 ) {
				unset( $theme['theme_values'] );
			}
			$row['theme'] = $theme;
			$rows[]       = $row;
		}
		// Digikala rejects two variants with the same theme value + warranty.
		$seen = array();
		foreach ( $rows as $i => $row ) {
			$key = (string) json_encode( $row['theme'] );
			if ( isset( $seen[ $key ] ) ) {
				$errors[] = sprintf( 'تنوع «%s» با تنوع «%s» در دیجی‌کالا یکسان می‌شود و ارسال نمی‌شود.', $row['label'], $rows[ $seen[ $key ] ]['label'] );
				unset( $rows[ $i ] );
				continue;
			}
			$seen[ $key ] = $i;
		}
		return array(
			'rows'   => array_values( $rows ),
			'errors' => $errors,
		);
	}
}

