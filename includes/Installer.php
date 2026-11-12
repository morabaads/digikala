<?php
/**
 * Activation / schema management.
 *
 * @package DKBridge
 */

namespace DKBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades plugin tables.
 */
class Installer {

	/**
	 * Table names (without prefix).
	 */
	const TABLES = array( 'dkb_items', 'dkb_category_map', 'dkb_attribute_map', 'dkb_orders', 'dkb_logs', 'dkb_variants', 'dkb_price_history' );

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install();
		$settings = get_option( Settings::OPTION, array() );
		if ( empty( $settings['webhook_secret'] ) ) {
			$settings                   = is_array( $settings ) ? $settings : array();
			$settings['webhook_secret'] = bin2hex( random_bytes( 16 ) );
			update_option( Settings::OPTION, $settings, false );
		}
		set_transient( 'dkb_activation_redirect', 1, 60 );
	}

	/**
	 * Deactivation hook: unschedule recurring jobs.
	 */
	public static function deactivate() {
		Sync\Queue::unschedule_all();
	}

	/**
	 * Run dbDelta if the schema version changed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'dkb_db_version' ) !== DKB_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Create / update tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$p}dkb_items (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wc_product_id bigint(20) unsigned NOT NULL,
			wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			dk_product_id bigint(20) unsigned NULL,
			dk_draft_id bigint(20) unsigned NULL,
			dk_variant_id bigint(20) unsigned NULL,
			state varchar(32) NOT NULL DEFAULT 'local',
			moderation_status varchar(40) NULL,
			score tinyint(3) unsigned NULL,
			issues longtext NULL,
			payload_hash char(40) NULL,
			context longtext NULL,
			last_error text NULL,
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			dirty_stock tinyint(1) NOT NULL DEFAULT 0,
			dirty_price tinyint(1) NOT NULL DEFAULT 0,
			last_synced_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_ref (wc_product_id,wc_variation_id),
			KEY state (state),
			KEY dk_product_id (dk_product_id),
			KEY dk_variant_id (dk_variant_id),
			KEY dirty (dirty_stock,dirty_price)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}dkb_category_map (
			wc_term_id bigint(20) unsigned NOT NULL,
			dk_category_id bigint(20) unsigned NOT NULL,
			dk_category_title varchar(255) NULL,
			theme varchar(32) NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (wc_term_id),
			KEY dk_category_id (dk_category_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}dkb_attribute_map (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dk_category_id bigint(20) unsigned NOT NULL,
			dk_attribute_id bigint(20) unsigned NOT NULL,
			source_type varchar(20) NOT NULL DEFAULT 'attribute',
			source_key varchar(191) NOT NULL DEFAULT '',
			value_map longtext NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY cat_attr (dk_category_id,dk_attribute_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}dkb_orders (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dk_order_id bigint(20) unsigned NOT NULL,
			dk_variant_id bigint(20) unsigned NOT NULL,
			quantity int(11) NOT NULL DEFAULT 0,
			selling_price bigint(20) NULL,
			wc_product_id bigint(20) unsigned NULL,
			status varchar(20) NOT NULL DEFAULT 'processed',
			order_created_at datetime NULL,
			processed_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_variant (dk_order_id,dk_variant_id)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}dkb_logs (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level varchar(10) NOT NULL,
			channel varchar(40) NOT NULL,
			message text NOT NULL,
			context longtext NULL,
			wc_product_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY level (level),
			KEY channel (channel),
			KEY wc_product_id (wc_product_id),
			KEY created_at (created_at)
		) $charset;";

		// Snapshot of every Digikala variant of the seller (catalog, inventory, buy box).
		$sql[] = "CREATE TABLE {$p}dkb_variants (
			dk_variant_id bigint(20) unsigned NOT NULL,
			dk_product_id bigint(20) unsigned NULL,
			wc_product_id bigint(20) unsigned NULL,
			wc_variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
			link_method varchar(20) NULL,
			supplier_code varchar(100) NULL,
			title varchar(255) NULL,
			product_title varchar(255) NULL,
			category_id bigint(20) unsigned NULL,
			category_title varchar(191) NULL,
			image varchar(255) NULL,
			active tinyint(1) NOT NULL DEFAULT 0,
			archived tinyint(1) NOT NULL DEFAULT 0,
			moderation_status varchar(40) NULL,
			price_sale bigint(20) NULL,
			price_list bigint(20) NULL,
			buy_box_price bigint(20) NULL,
			is_buy_box_winner tinyint(1) NOT NULL DEFAULT 0,
			in_challenge tinyint(1) NOT NULL DEFAULT 0,
			min_price_limit bigint(20) NULL,
			seller_stock int(11) NOT NULL DEFAULT 0,
			warehouse_stock int(11) NOT NULL DEFAULT 0,
			on_the_way_stock int(11) NOT NULL DEFAULT 0,
			reservation int(11) NOT NULL DEFAULT 0,
			selling_stock int(11) NOT NULL DEFAULT 0,
			max_per_order int(11) NULL,
			lead_time int(11) NULL,
			fbs_active tinyint(1) NOT NULL DEFAULT 0,
			in_promotion tinyint(1) NOT NULL DEFAULT 0,
			promotion_price bigint(20) NULL,
			rating decimal(3,2) NULL,
			fulfilment_cost bigint(20) NULL,
			dead_stock tinyint(1) NOT NULL DEFAULT 0,
			synced_at datetime NOT NULL,
			PRIMARY KEY  (dk_variant_id),
			KEY dk_product_id (dk_product_id),
			KEY wc_product_id (wc_product_id),
			KEY supplier_code (supplier_code),
			KEY active (active)
		) $charset;";

		$sql[] = "CREATE TABLE {$p}dkb_price_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			dk_variant_id bigint(20) unsigned NOT NULL,
			old_price bigint(20) NULL,
			new_price bigint(20) NOT NULL,
			buy_box_price bigint(20) NULL,
			strategy varchar(20) NOT NULL,
			reason varchar(255) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY dk_variant_id (dk_variant_id),
			KEY created_at (created_at)
		) $charset;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		update_option( 'dkb_db_version', DKB_DB_VERSION, false );
	}

	/**
	 * Drop everything (used by uninstall.php).
	 */
	public static function drop() {
		global $wpdb;
		foreach ( self::TABLES as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option( Settings::OPTION );
		delete_option( 'dkb_db_version' );
		delete_option( Api\TokenStore::OPTION );
		delete_option( Security\KeyPair::OPTION );
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_dkb\_%' OR option_name LIKE '\_transient\_timeout\_dkb\_%'" );
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_dkb\_%'" );
	}
}
