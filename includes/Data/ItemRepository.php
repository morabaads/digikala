<?php
/**
 * Persistence for product ↔ Digikala mapping & pipeline state.
 *
 * @package DKBridge
 */

namespace DKBridge\Data;

defined( 'ABSPATH' ) || exit;

/**
 * dkb_items repository.
 */
class ItemRepository {

	const STATES = array(
		'local'        => 'ارسال نشده',
		'ready'        => 'آمادهٔ ارسال',
		'blocked'      => 'نیاز به اصلاح',
		'queued'       => 'در صف',
		'processing'   => 'در حال ارسال',
		'match_review' => 'کالای مشابه یافت شد',
		'in_review'    => 'در انتظار تأیید دیجی‌کالا',
		'rejected'     => 'رد شده',
		'approved'     => 'تأیید شده',
		'live'         => 'فعال در دیجی‌کالا',
		'error'        => 'خطا',
		'dry_run'      => 'اجرای آزمایشی',
	);

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dkb_items';
	}

	/**
	 * Decode JSON columns.
	 *
	 * @param array|null $row Row.
	 * @return array|null
	 */
	public static function hydrate( $row ) {
		if ( ! $row ) {
			return null;
		}
		$row['context'] = $row['context'] ? (array) json_decode( $row['context'], true ) : array();
		$row['issues']  = $row['issues'] ? (array) json_decode( $row['issues'], true ) : array();
		foreach ( array( 'id', 'wc_product_id', 'wc_variation_id', 'dk_product_id', 'dk_draft_id', 'dk_variant_id', 'attempts' ) as $k ) {
			$row[ $k ] = null === $row[ $k ] ? null : (int) $row[ $k ];
		}
		return $row;
	}

	/**
	 * Get an item.
	 *
	 * @param int $product_id   WC product.
	 * @param int $variation_id WC variation (0 = parent).
	 * @return array|null
	 */
	public function get( $product_id, $variation_id = 0 ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wc_product_id = %d AND wc_variation_id = %d', $product_id, $variation_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return self::hydrate( $row );
	}

	/**
	 * Get by id.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public function find( $id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Find by Digikala variant id.
	 *
	 * @param int $variant_id Variant.
	 * @return array|null
	 */
	public function find_by_variant( $variant_id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE dk_variant_id = %d LIMIT 1', $variant_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Find parent by Digikala product id.
	 *
	 * @param int $dk_product_id DKP.
	 * @return array|null
	 */
	public function find_by_dk_product( $dk_product_id ) {
		global $wpdb;
		return self::hydrate( $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE dk_product_id = %d AND wc_variation_id = 0 LIMIT 1', $dk_product_id ), ARRAY_A ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Children (variation rows) of a product.
	 *
	 * @param int $product_id WC product.
	 * @return array[]
	 */
	public function children( $product_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE wc_product_id = %d AND wc_variation_id > 0', $product_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Insert or update.
	 *
	 * @param int   $product_id   WC product.
	 * @param int   $variation_id WC variation.
	 * @param array $fields       Fields.
	 * @return array Row.
	 */
	public function upsert( $product_id, $variation_id, array $fields ) {
		global $wpdb;
		$existing = $this->get( $product_id, $variation_id );
		$data     = self::encode( $fields );
		$now      = current_time( 'mysql', true );
		if ( $existing ) {
			$data['updated_at'] = $now;
			$wpdb->update( self::table(), $data, array( 'id' => $existing['id'] ) );
		} else {
			$data = array_merge(
				array(
					'wc_product_id'   => (int) $product_id,
					'wc_variation_id' => (int) $variation_id,
					'state'           => 'local',
					'created_at'      => $now,
					'updated_at'      => $now,
				),
				$data
			);
			$wpdb->insert( self::table(), $data );
		}
		return $this->get( $product_id, $variation_id );
	}

	/**
	 * Update by id.
	 *
	 * @param int   $id     Id.
	 * @param array $fields Fields.
	 */
	public function update( $id, array $fields ) {
		global $wpdb;
		$data               = self::encode( $fields );
		$data['updated_at'] = current_time( 'mysql', true );
		$wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Merge keys into the context JSON.
	 *
	 * @param array $item   Item.
	 * @param array $values Values.
	 * @return array New context.
	 */
	public function merge_context( array $item, array $values ) {
		$context = array_merge( (array) $item['context'], $values );
		$this->update( $item['id'], array( 'context' => $context ) );
		return $context;
	}

	/**
	 * Encode JSON fields.
	 *
	 * @param array $fields Fields.
	 * @return array
	 */
	private static function encode( array $fields ) {
		foreach ( array( 'context', 'issues' ) as $k ) {
			if ( array_key_exists( $k, $fields ) && ! is_string( $fields[ $k ] ) ) {
				$fields[ $k ] = null === $fields[ $k ] ? null : wp_json_encode( $fields[ $k ], JSON_UNESCAPED_UNICODE );
			}
		}
		return $fields;
	}

	/**
	 * Delete rows of a product.
	 *
	 * @param int $product_id WC product.
	 */
	public function delete_product( $product_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'wc_product_id' => (int) $product_id ) );
	}

	/**
	 * Mark rows dirty.
	 *
	 * @param int    $product_id   WC product (parent).
	 * @param int    $variation_id WC variation or -1 for all rows of the product.
	 * @param string $what         stock|price|both.
	 */
	public function mark_dirty( $product_id, $variation_id, $what ) {
		global $wpdb;
		$set = array();
		if ( 'stock' === $what || 'both' === $what ) {
			$set[] = 'dirty_stock = 1';
		}
		if ( 'price' === $what || 'both' === $what ) {
			$set[] = 'dirty_price = 1';
		}
		if ( ! $set ) {
			return;
		}
		$where = $wpdb->prepare( 'wc_product_id = %d AND dk_variant_id IS NOT NULL', $product_id );
		if ( $variation_id >= 0 ) {
			$where .= $wpdb->prepare( ' AND wc_variation_id = %d', $variation_id );
		}
		$wpdb->query( 'UPDATE ' . self::table() . ' SET ' . implode( ', ', $set ) . ' WHERE ' . $where ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Dirty rows.
	 *
	 * @param string $what  stock|price.
	 * @param int    $limit Limit.
	 * @return array[]
	 */
	public function dirty( $what, $limit = 500 ) {
		global $wpdb;
		$col  = 'price' === $what ? 'dirty_price' : 'dirty_stock';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$col} = 1 AND dk_variant_id IS NOT NULL LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Clear dirty flags.
	 *
	 * @param int[]  $ids  Ids.
	 * @param string $what stock|price.
	 */
	public function clear_dirty( array $ids, $what ) {
		global $wpdb;
		if ( ! $ids ) {
			return;
		}
		$col = 'price' === $what ? 'dirty_price' : 'dirty_stock';
		$in  = implode( ',', array_map( 'intval', $ids ) );
		$wpdb->query( 'UPDATE ' . self::table() . " SET {$col} = 0, last_synced_at = '" . esc_sql( current_time( 'mysql', true ) ) . "' WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rows in a given state.
	 *
	 * @param string|string[] $states States.
	 * @param int             $limit  Limit.
	 * @return array[]
	 */
	public function in_state( $states, $limit = 200 ) {
		global $wpdb;
		$states       = (array) $states;
		$placeholders = implode( ',', array_fill( 0, count( $states ), '%s' ) );
		$sql          = 'SELECT * FROM ' . self::table() . " WHERE wc_variation_id = 0 AND state IN ({$placeholders}) ORDER BY updated_at ASC LIMIT %d";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $states, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	/**
	 * Count parent rows by state.
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT state, COUNT(*) AS c FROM ' . self::table() . ' WHERE wc_variation_id = 0 GROUP BY state', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array_fill_keys( array_keys( self::STATES ), 0 );
		foreach ( (array) $rows as $row ) {
			$out[ $row['state'] ] = (int) $row['c'];
		}
		return $out;
	}

	/**
	 * Mark all live items dirty (nightly reconciliation).
	 */
	public function mark_all_live_dirty() {
		global $wpdb;
		$wpdb->query( 'UPDATE ' . self::table() . ' SET dirty_stock = 1, dirty_price = 1 WHERE dk_variant_id IS NOT NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Map of product id → parent row for a list of products.
	 *
	 * @param int[] $product_ids Ids.
	 * @return array<int,array>
	 */
	public function for_products( array $product_ids ) {
		global $wpdb;
		$product_ids = array_filter( array_map( 'intval', $product_ids ) );
		if ( ! $product_ids ) {
			return array();
		}
		$in   = implode( ',', $product_ids );
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . " WHERE wc_variation_id = 0 AND wc_product_id IN ({$in})", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$row                                  = self::hydrate( $row );
			$out[ (int) $row['wc_product_id'] ] = $row;
		}
		return $out;
	}

	/**
	 * Product ids in a state (for filtering the admin table).
	 *
	 * @param string $state State.
	 * @return int[]
	 */
	public function product_ids_in_state( $state ) {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT wc_product_id FROM ' . self::table() . ' WHERE wc_variation_id = 0 AND state = %s', $state ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
