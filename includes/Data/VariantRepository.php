<?php
/**
 * Snapshot of the seller's Digikala variants (dkb_variants) + sales history helpers.
 *
 * @package DKBridge
 */

namespace DKBridge\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Variant snapshot repository.
 */
class VariantRepository {

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dkb_variants';
	}

	/**
	 * Map a GET /variants row to table columns.
	 *
	 * @param array $r API row.
	 * @return array
	 */
	public static function columns_from_api( array $r ) {
		$b = static function ( $v ) {
			return ! empty( $v ) ? 1 : 0;
		};
		$i = static function ( $k ) use ( $r ) {
			return isset( $r[ $k ] ) && null !== $r[ $k ] ? (int) $r[ $k ] : null;
		};
		return array(
			'dk_variant_id'     => (int) $r['id'],
			'dk_product_id'     => $i( 'product_id' ),
			'supplier_code'     => isset( $r['supplier_code'] ) ? substr( (string) $r['supplier_code'], 0, 100 ) : null,
			'title'             => isset( $r['title'] ) ? mb_substr( (string) $r['title'], 0, 255 ) : null,
			'product_title'     => isset( $r['product_title'] ) ? mb_substr( (string) $r['product_title'], 0, 255 ) : null,
			'category_id'       => $i( 'category_id' ),
			'category_title'    => isset( $r['main_category_title'] ) ? mb_substr( (string) $r['main_category_title'], 0, 191 ) : null,
			'image'             => isset( $r['image_src'] ) ? substr( (string) $r['image_src'], 0, 255 ) : null,
			'active'            => $b( isset( $r['active'] ) ? $r['active'] : 0 ),
			'archived'          => $b( isset( $r['is_archived'] ) ? $r['is_archived'] : 0 ),
			'moderation_status' => isset( $r['product_moderation_status'] ) ? (string) $r['product_moderation_status'] : null,
			'price_sale'        => $i( 'price_sale' ),
			'price_list'        => $i( 'price_list' ),
			'buy_box_price'     => $i( 'buy_box_price' ),
			'is_buy_box_winner' => $b( isset( $r['is_buy_box_winner'] ) ? $r['is_buy_box_winner'] : 0 ),
			'in_challenge'      => $b( isset( $r['is_in_buy_box_challenge'] ) ? $r['is_in_buy_box_challenge'] : 0 ),
			'min_price_limit'   => $i( 'min_selling_price_limit' ),
			'seller_stock'      => (int) ( isset( $r['marketplace_seller_stock'] ) ? $r['marketplace_seller_stock'] : 0 ),
			'warehouse_stock'   => (int) ( isset( $r['warehouse_stock'] ) ? $r['warehouse_stock'] : 0 ),
			'on_the_way_stock'  => (int) ( isset( $r['on_the_way_stock'] ) ? $r['on_the_way_stock'] : 0 ),
			'reservation'       => (int) ( isset( $r['reservation'] ) ? $r['reservation'] : 0 ),
			'selling_stock'     => (int) ( isset( $r['left_consumer'] ) ? $r['left_consumer'] : 0 ),
			'max_per_order'     => $i( 'maximum_per_order' ),
			'lead_time'         => $i( 'lead_time' ),
			'fbs_active'        => $b( isset( $r['shipping_options']['is_fbs_active'] ) ? $r['shipping_options']['is_fbs_active'] : 0 ),
			'in_promotion'      => $b( ! empty( $r['is_in_promotion'] ) || ! empty( $r['is_in_incredible_promotion'] ) || ! empty( $r['is_in_periodic_promotion'] ) ),
			'promotion_price'   => $i( 'promotion_price' ),
			'rating'            => isset( $r['rating'] ) ? round( (float) $r['rating'], 2 ) : null,
			'fulfilment_cost'   => $i( 'fulfilment_and_delivery_cost' ),
		);
	}

	/**
	 * Insert or update from an API row (keeps existing WooCommerce link).
	 *
	 * @param array $row API row.
	 */
	public function upsert_api( array $row ) {
		global $wpdb;
		if ( empty( $row['id'] ) ) {
			return;
		}
		$data              = self::columns_from_api( $row );
		$data['synced_at'] = current_time( 'mysql', true );
		$exists            = $wpdb->get_var( $wpdb->prepare( 'SELECT dk_variant_id FROM ' . self::table() . ' WHERE dk_variant_id = %d', $data['dk_variant_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $exists ) {
			$wpdb->update( self::table(), $data, array( 'dk_variant_id' => $data['dk_variant_id'] ) );
		} else {
			$wpdb->insert( self::table(), $data );
		}
	}

	/**
	 * Update columns of a variant.
	 *
	 * @param int   $variant_id Variant.
	 * @param array $fields     Fields.
	 */
	public function update( $variant_id, array $fields ) {
		global $wpdb;
		$wpdb->update( self::table(), $fields, array( 'dk_variant_id' => (int) $variant_id ) );
	}

	/**
	 * Get one.
	 *
	 * @param int $variant_id Variant.
	 * @return array|null
	 */
	public function get( $variant_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE dk_variant_id = %d', $variant_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? $row : null;
	}

	/**
	 * Link a variant to a WooCommerce product.
	 *
	 * @param int    $variant_id   Variant.
	 * @param int    $product_id   WC parent.
	 * @param int    $variation_id WC variation (0 for simple).
	 * @param string $method       sku|code|pipeline|manual.
	 */
	public function link( $variant_id, $product_id, $variation_id, $method ) {
		$this->update(
			$variant_id,
			array(
				'wc_product_id'   => $product_id ? (int) $product_id : null,
				'wc_variation_id' => (int) $variation_id,
				'link_method'     => $product_id ? $method : null,
			)
		);
	}

	/**
	 * Filtered list.
	 *
	 * @param array $f     Filters: search, active, archived, linked, buybox (winner|loser), stock (out|in), category.
	 * @param int   $page  Page.
	 * @param int   $per   Per page.
	 * @return array{rows:array,total:int}
	 */
	public function search( array $f, $page = 1, $per = 50 ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $f['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( (string) $f['search'] ) . '%';
			$where[]  = '(title LIKE %s OR supplier_code LIKE %s OR dk_variant_id = %d OR dk_product_id = %d)';
			$params[] = $like;
			$params[] = $like;
			$params[] = (int) $f['search'];
			$params[] = (int) $f['search'];
		}
		foreach ( array( 'active', 'archived' ) as $k ) {
			if ( isset( $f[ $k ] ) && '' !== $f[ $k ] ) {
				$where[]  = "{$k} = %d";
				$params[] = (int) $f[ $k ];
			}
		}
		if ( isset( $f['linked'] ) && '' !== $f['linked'] ) {
			$where[] = $f['linked'] ? 'wc_product_id IS NOT NULL' : 'wc_product_id IS NULL';
		}
		if ( ! empty( $f['buybox'] ) ) {
			$where[] = 'winner' === $f['buybox'] ? 'is_buy_box_winner = 1' : 'is_buy_box_winner = 0 AND in_challenge = 1';
		}
		if ( ! empty( $f['stock'] ) ) {
			$where[] = 'out' === $f['stock'] ? 'selling_stock <= 0' : 'selling_stock > 0';
		}
		if ( ! empty( $f['ids'] ) ) {
			$where[] = 'dk_variant_id IN (' . implode( ',', array_map( 'intval', (array) $f['ids'] ) ) . ')';
		}
		$sql_where = implode( ' AND ', $where );
		$total_sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$sql_where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$order     = isset( $f['orderby'] ) && in_array( $f['orderby'], array( 'selling_stock', 'price_sale', 'synced_at', 'title' ), true ) ? $f['orderby'] : 'dk_variant_id';
		$sql       = 'SELECT * FROM ' . self::table() . " WHERE {$sql_where} ORDER BY {$order} DESC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $params, array( (int) $per, max( 0, ( (int) $page - 1 ) * (int) $per ) ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * All linked variants.
	 *
	 * @param int $limit Limit.
	 * @return array[]
	 */
	public function linked( $limit = 5000 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wc_product_id IS NOT NULL AND archived = 0 LIMIT %d', $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Variants of a WooCommerce product.
	 *
	 * @param int $product_id WC parent.
	 * @return array[]
	 */
	public function for_wc_product( $product_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wc_product_id = %d', $product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Summary counts.
	 *
	 * @return array
	 */
	public function summary() {
		global $wpdb;
		$row = $wpdb->get_row(
			'SELECT COUNT(*) AS total, SUM(active) AS active, SUM(archived) AS archived, SUM(wc_product_id IS NOT NULL) AS linked,
			SUM(is_buy_box_winner) AS winners, SUM(in_challenge = 1 AND is_buy_box_winner = 0) AS losing, SUM(selling_stock <= 0 AND active = 1) AS out_of_stock,
			SUM(in_promotion) AS in_promotion, SUM(dead_stock) AS dead_stock, MAX(synced_at) AS synced_at FROM ' . self::table(), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);
		return array_map(
			static function ( $v ) {
				return is_numeric( $v ) ? (int) $v : $v;
			},
			(array) $row
		);
	}

	/**
	 * Daily Digikala sales per variant from dkb_orders.
	 *
	 * @param int   $days        Window.
	 * @param int[] $variant_ids Restrict (optional).
	 * @return array<int,array<string,int>> variant => [Y-m-d => qty]
	 */
	public function sales_map( $days = 30, array $variant_ids = array() ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$sql   = "SELECT dk_variant_id, DATE(COALESCE(order_created_at, processed_at)) AS d, SUM(quantity) AS q FROM {$wpdb->prefix}dkb_orders WHERE status <> 'restored' AND COALESCE(order_created_at, processed_at) >= %s";
		if ( $variant_ids ) {
			$sql .= ' AND dk_variant_id IN (' . implode( ',', array_map( 'intval', $variant_ids ) ) . ')';
		}
		$sql .= ' GROUP BY dk_variant_id, d';
		$out  = array();
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( $sql, $since ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$out[ (int) $r['dk_variant_id'] ][ $r['d'] ] = (int) $r['q'];
		}
		return $out;
	}

	/**
	 * Daily Digikala revenue (from imported orders).
	 *
	 * @param int $days Window.
	 * @return array<string,array{qty:int,revenue:int}>
	 */
	public function daily_revenue( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(COALESCE(order_created_at, processed_at)) AS d, SUM(quantity) AS q, SUM(quantity * COALESCE(selling_price,0)) AS r FROM {$wpdb->prefix}dkb_orders WHERE status <> 'restored' AND COALESCE(order_created_at, processed_at) >= %s GROUP BY d ORDER BY d", $since ), ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['d'] ] = array(
				'qty'     => (int) $r['q'],
				'revenue' => (int) $r['r'],
			);
		}
		return $out;
	}

	/**
	 * Price change history.
	 *
	 * @param int $variant_id Variant.
	 * @param int $limit      Limit.
	 * @return array[]
	 */
	public function price_history( $variant_id, $limit = 30 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}dkb_price_history WHERE dk_variant_id = %d ORDER BY id DESC LIMIT %d", $variant_id, $limit ), ARRAY_A );
	}

	/**
	 * Price of a variant 7 days ago (first recorded change in the window, else null).
	 *
	 * @param int $variant_id Variant.
	 * @return int|null
	 */
	public function price_week_ago( $variant_id ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT old_price FROM {$wpdb->prefix}dkb_price_history WHERE dk_variant_id = %d AND created_at >= %s ORDER BY id ASC LIMIT 1", $variant_id, gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ) ) );
		return null === $v ? null : (int) $v;
	}

	/**
	 * Record a price change.
	 *
	 * @param int    $variant_id Variant.
	 * @param int    $old        Old price.
	 * @param int    $new        New price.
	 * @param int    $buy_box    Buy box price.
	 * @param string $strategy   Strategy.
	 * @param string $reason     Reason.
	 */
	public function log_price( $variant_id, $old, $new, $buy_box, $strategy, $reason ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'dkb_price_history',
			array(
				'dk_variant_id' => (int) $variant_id,
				'old_price'     => $old ? (int) $old : null,
				'new_price'     => (int) $new,
				'buy_box_price' => $buy_box ? (int) $buy_box : null,
				'strategy'      => substr( (string) $strategy, 0, 20 ),
				'reason'        => mb_substr( (string) $reason, 0, 255 ),
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}
}
