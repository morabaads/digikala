<?php
/**
 * WooCommerce category → Digikala leaf category mapping.
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

defined( 'ABSPATH' ) || exit;

/**
 * Category map repository + resolver.
 */
class CategoryMapper {

	/**
	 * Request cache.
	 *
	 * @var array|null
	 */
	private $all = null;

	/**
	 * Table.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dkb_category_map';
	}

	/**
	 * All mappings keyed by term id.
	 *
	 * @return array<int,array>
	 */
	public function all() {
		if ( null === $this->all ) {
			global $wpdb;
			$rows      = $wpdb->get_results( 'SELECT * FROM ' . self::table(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$this->all = array();
			foreach ( (array) $rows as $row ) {
				$this->all[ (int) $row['wc_term_id'] ] = $row;
			}
		}
		return $this->all;
	}

	/**
	 * Mapping of a term.
	 *
	 * @param int $term_id Term.
	 * @return array|null
	 */
	public function get( $term_id ) {
		$all = $this->all();
		return isset( $all[ (int) $term_id ] ) ? $all[ (int) $term_id ] : null;
	}

	/**
	 * Save a mapping.
	 *
	 * @param int    $term_id        Term.
	 * @param int    $dk_category_id DK category.
	 * @param string $title          DK title.
	 * @param string $theme          Category theme.
	 */
	public function set( $term_id, $dk_category_id, $title = '', $theme = '' ) {
		global $wpdb;
		$wpdb->replace(
			self::table(),
			array(
				'wc_term_id'        => (int) $term_id,
				'dk_category_id'    => (int) $dk_category_id,
				'dk_category_title' => sanitize_text_field( $title ),
				'theme'             => sanitize_key( $theme ),
				'updated_at'        => current_time( 'mysql', true ),
			)
		);
		$this->all = null;
	}

	/**
	 * Remove a mapping.
	 *
	 * @param int $term_id Term.
	 */
	public function delete( $term_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'wc_term_id' => (int) $term_id ) );
		$this->all = null;
	}

	/**
	 * Resolve the Digikala category for a product: per-product override, then the
	 * deepest mapped category, then the nearest mapped ancestor.
	 *
	 * @param \WC_Product $product Product.
	 * @return array|null {dk_category_id, dk_category_title, theme, wc_term_id}
	 */
	public function resolve( $product ) {
		$override = (int) $product->get_meta( '_dkb_category_id' );
		if ( $override ) {
			return array(
				'dk_category_id'    => $override,
				'dk_category_title' => (string) $product->get_meta( '_dkb_category_title' ),
				'theme'             => '',
				'wc_term_id'        => 0,
			);
		}
		$best       = null;
		$best_depth = -1;
		foreach ( $product->get_category_ids() as $term_id ) {
			$chain = array_merge( array( (int) $term_id ), array_map( 'intval', get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) );
			$depth = count( $chain );
			foreach ( $chain as $level => $candidate ) {
				$map = $this->get( $candidate );
				if ( $map ) {
					$score = ( $depth - $level ) * 100 + $depth;
					if ( $score > $best_depth ) {
						$best_depth = $score;
						$best       = $map;
					}
					break;
				}
			}
		}
		return $best;
	}

	/**
	 * Number of WooCommerce categories without mapping.
	 *
	 * @return int
	 */
	public function unmapped_count() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => true,
				'fields'     => 'ids',
			)
		);
		if ( is_wp_error( $terms ) ) {
			return 0;
		}
		return count( array_diff( array_map( 'intval', $terms ), array_keys( $this->all() ) ) );
	}
}
