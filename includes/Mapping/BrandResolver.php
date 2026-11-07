<?php
/**
 * Finds the Digikala brand for a WooCommerce product.
 *
 * @package DKBridge
 */

namespace DKBridge\Mapping;

use DKBridge\Api\ApiException;
use DKBridge\Api\Digikala;
use DKBridge\Matching\Similarity;
use DKBridge\Text\Normalizer;

defined( 'ABSPATH' ) || exit;

/**
 * Brand resolver.
 */
class BrandResolver {

	const OPTION = 'dkb_brand_map';

	/**
	 * Known brand taxonomies of popular plugins and WooCommerce core.
	 */
	const TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'berocket_brand', 'pa_brand', 'pa_برند' );

	/**
	 * API.
	 *
	 * @var Digikala|null
	 */
	private $api;

	/**
	 * Source setting.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Constructor.
	 *
	 * @param Digikala|null $api    API (null = offline).
	 * @param string        $source Brand source setting.
	 */
	public function __construct( $api, $source = 'auto' ) {
		$this->api    = $api;
		$this->source = $source;
	}

	/**
	 * Raw brand text of a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public function brand_text( $product ) {
		$id     = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
		$source = $this->source;
		if ( 0 === strpos( $source, 'taxonomy:' ) ) {
			return self::first_term( $id, substr( $source, 9 ) );
		}
		if ( 0 === strpos( $source, 'meta:' ) ) {
			return trim( (string) get_post_meta( $id, substr( $source, 5 ), true ) );
		}
		if ( 0 === strpos( $source, 'attribute:' ) ) {
			return trim( (string) $product->get_attribute( substr( $source, 10 ) ) );
		}
		foreach ( self::TAXONOMIES as $tax ) {
			if ( taxonomy_exists( $tax ) ) {
				$name = self::first_term( $id, $tax );
				if ( '' !== $name ) {
					return $name;
				}
			}
		}
		foreach ( array( 'brand', 'برند', 'Brand' ) as $attr ) {
			$value = trim( (string) $product->get_attribute( $attr ) );
			if ( '' !== $value ) {
				return trim( explode( ',', $value )[0] );
			}
		}
		foreach ( array( '_brand', 'brand', '_product_brand' ) as $meta ) {
			$value = trim( (string) get_post_meta( $id, $meta, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * First term name.
	 *
	 * @param int    $post_id  Post.
	 * @param string $taxonomy Taxonomy.
	 * @return string
	 */
	private static function first_term( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		return ( $terms && ! is_wp_error( $terms ) ) ? (string) $terms[0]->name : '';
	}

	/**
	 * Resolve to {id, fa, en, misc}.
	 *
	 * @param string $text           Brand text.
	 * @param array  $category_brands Brands bound to the category [{id,title_fa,title_en}].
	 * @param int    $other_id       «متفرقه» id.
	 * @return array|null
	 */
	public function resolve( $text, array $category_brands = array(), $other_id = 0 ) {
		$text = trim( (string) $text );
		$key  = Normalizer::fold( $text );
		if ( '' === $key ) {
			return null;
		}
		$cache = (array) get_option( self::OPTION, array() );
		if ( isset( $cache[ $key ] ) && ! empty( $cache[ $key ]['id'] ) ) {
			return $cache[ $key ];
		}
		if ( in_array( $key, array( 'متفرقه', 'miscellaneous', 'other', 'بدون برند', 'no brand', 'نامشخص' ), true ) && $other_id ) {
			return array(
				'id'   => (int) $other_id,
				'fa'   => 'متفرقه',
				'en'   => 'Miscellaneous',
				'misc' => true,
			);
		}

		$found = null;
		// 1) Brands bound to the category (no API call).
		if ( $category_brands ) {
			$labels = array();
			foreach ( $category_brands as $i => $b ) {
				$labels[ $i . '|fa' ] = isset( $b['title_fa'] ) ? $b['title_fa'] : ( isset( $b['text'] ) ? $b['text'] : '' );
				$labels[ $i . '|en' ] = isset( $b['title_en'] ) ? $b['title_en'] : '';
			}
			$best = Similarity::best( $text, array_filter( $labels ), 0.88 );
			if ( null !== $best['key'] ) {
				$b     = $category_brands[ (int) explode( '|', $best['key'] )[0] ];
				$found = array(
					'id'   => (int) $b['id'],
					'fa'   => isset( $b['title_fa'] ) ? $b['title_fa'] : ( isset( $b['text'] ) ? $b['text'] : $text ),
					'en'   => isset( $b['title_en'] ) ? $b['title_en'] : '',
					'misc' => (int) $b['id'] === (int) $other_id,
				);
			}
		}
		// 2) Brand search API (fa then en).
		if ( ! $found && $this->api ) {
			$lang = Normalizer::has_persian( $text ) ? 'fa' : 'en';
			try {
				$items = $this->api->search_brands( $text, $lang );
				$found = self::pick( $text, $items );
			} catch ( ApiException $e ) {
				$found = null;
			}
		}
		if ( $found ) {
			$cache[ $key ] = $found;
			update_option( self::OPTION, $cache, false );
		}
		return $found;
	}

	/**
	 * Pick the best brand from a brand search response (`items` keyed by brand name).
	 *
	 * @param string $text  Text.
	 * @param array  $items Items.
	 * @return array|null
	 */
	public static function pick( $text, array $items ) {
		$best_score = 0.0;
		$best       = null;
		foreach ( $items as $name => $b ) {
			if ( ! is_array( $b ) || empty( $b['id'] ) ) {
				continue;
			}
			$score = max(
				Similarity::score( $text, isset( $b['name_fa'] ) ? $b['name_fa'] : (string) $name ),
				Similarity::score( $text, isset( $b['name_en'] ) ? $b['name_en'] : '' )
			);
			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $b;
			}
		}
		if ( ! $best || $best_score < 0.85 ) {
			return null;
		}
		return array(
			'id'   => (int) $best['id'],
			'fa'   => isset( $best['name_fa'] ) ? $best['name_fa'] : '',
			'en'   => isset( $best['name_en'] ) ? $best['name_en'] : '',
			'misc' => false,
		);
	}

	/**
	 * Manually map a brand text.
	 *
	 * @param string $text  Text.
	 * @param array  $brand {id,fa,en}.
	 */
	public static function remember( $text, array $brand ) {
		$cache                                 = (array) get_option( self::OPTION, array() );
		$cache[ Normalizer::fold( $text ) ] = $brand;
		update_option( self::OPTION, $cache, false );
	}
}
