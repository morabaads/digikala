<?php
/**
 * WP-CLI commands: wp dkb …
 *
 * @package DKBridge
 */

namespace DKBridge\Cli;

use DKBridge\Mapping\ProductMapper;
use DKBridge\Plugin;
use DKBridge\Rules\Issue;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the WooCommerce ↔ Digikala bridge.
 */
class Command {

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Show connection & sync status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dkb status
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function status( $args, $assoc ) {
		\WP_CLI::log( 'Connected: ' . ( $this->plugin->is_connected() ? 'yes' : 'no' ) );
		\WP_CLI::log( 'Dry run:   ' . ( $this->plugin->settings()->get( 'dry_run' ) ? 'yes' : 'no' ) );
		$rows = array();
		foreach ( $this->plugin->items()->counts() as $state => $count ) {
			$rows[] = array(
				'state' => $state,
				'count' => $count,
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'state', 'count' ) );
	}

	/**
	 * Validate products against Digikala content rules.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>...]
	 * : Product ids. Omit with --all.
	 *
	 * [--all]
	 * : All published products.
	 *
	 * [--fix]
	 * : Apply auto-fixes and store them as Digikala overrides.
	 *
	 * [--format=<format>]
	 * : table|json|csv. Default table.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function validate( $args, $assoc ) {
		$ids  = $this->ids( $args, $assoc );
		$fix  = ! empty( $assoc['fix'] );
		$rows = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			$eval = $this->plugin->publisher()->evaluate( $product, $fix );
			if ( $fix ) {
				ProductMapper::persist_overrides( $product, $eval['data'] );
			}
			$errors = array_filter(
				$eval['result']['issues'],
				static function ( Issue $i ) {
					return Issue::ERROR === $i->severity;
				}
			);
			$rows[] = array(
				'id'     => $id,
				'score'  => $eval['result']['score']['percent'],
				'errors' => count( $errors ),
				'issues' => count( $eval['result']['issues'] ),
				'first'  => $errors ? reset( $errors )->message : '',
				'title'  => $eval['data']->title_fa,
			);
		}
		\WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $rows, array( 'id', 'score', 'errors', 'issues', 'title', 'first' ) );
	}

	/**
	 * Send products to Digikala.
	 *
	 * ## OPTIONS
	 *
	 * [<ids>...]
	 * : Product ids.
	 *
	 * [--all]
	 * : All published products that are ready.
	 *
	 * [--now]
	 * : Run synchronously instead of queueing.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function push( $args, $assoc ) {
		$ids = $this->ids( $args, $assoc );
		foreach ( $ids as $id ) {
			if ( ! empty( $assoc['now'] ) ) {
				$item = $this->plugin->publisher()->run( $id );
				\WP_CLI::log( sprintf( '#%d → %s %s', $id, $item ? $item['state'] : '-', $item && $item['last_error'] ? '(' . $item['last_error'] . ')' : '' ) );
			} else {
				$this->plugin->publisher()->enqueue( $id );
			}
		}
		\WP_CLI::success( sprintf( '%d product(s) %s.', count( $ids ), empty( $assoc['now'] ) ? 'queued' : 'processed' ) );
	}

	/**
	 * Push dirty stock to Digikala now.
	 *
	 * [--all]
	 * : Mark every linked variant dirty first.
	 *
	 * @subcommand sync-stock
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function sync_stock( $args, $assoc ) {
		if ( ! empty( $assoc['all'] ) ) {
			$this->plugin->items()->mark_all_live_dirty();
		}
		\WP_CLI::success( sprintf( '%d variant(s) sent.', $this->plugin->inventory()->flush_stock() ) );
	}

	/**
	 * Push dirty prices to Digikala now.
	 *
	 * [--all]
	 * : Mark every linked variant dirty first.
	 *
	 * @subcommand sync-prices
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function sync_prices( $args, $assoc ) {
		if ( ! empty( $assoc['all'] ) ) {
			$this->plugin->items()->mark_all_live_dirty();
		}
		\WP_CLI::success( sprintf( '%d variant(s) sent.', $this->plugin->inventory()->flush_prices() ) );
	}

	/**
	 * Pull Digikala orders and decrease WooCommerce stock.
	 *
	 * @subcommand pull-orders
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function pull_orders( $args, $assoc ) {
		\WP_CLI::success( sprintf( '%d new order item(s).', $this->plugin->orders()->pull( 20 ) ) );
	}

	/**
	 * Check moderation status of products in review.
	 *
	 * @subcommand poll
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function poll( $args, $assoc ) {
		\WP_CLI::success( sprintf( '%d product(s) updated.', $this->plugin->poller()->poll() ) );
	}

	/**
	 * Map a WooCommerce category to a Digikala leaf category.
	 *
	 * ## OPTIONS
	 *
	 * <term_id>
	 * : WooCommerce product_cat term id.
	 *
	 * <dk_category_id>
	 * : Digikala leaf category id.
	 *
	 * @subcommand map-category
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function map_category( $args, $assoc ) {
		list( $term, $dk ) = array_map( 'intval', $args );
		$title             = '';
		$theme             = '';
		try {
			$v     = $this->plugin->api()->category_validation( $dk );
			$title = isset( $v['bind']['category_data']['categoryTitle'] ) ? $v['bind']['category_data']['categoryTitle'] : '';
			$theme = isset( $v['bind']['category_data']['categoryTheme'] ) ? $v['bind']['category_data']['categoryTheme'] : '';
			if ( empty( $v['isValid'] ) ) {
				\WP_CLI::warning( 'Category is restricted: ' . implode( ' ', (array) $v['errors'] ) );
			}
		} catch ( \Exception $e ) {
			\WP_CLI::warning( $e->getMessage() );
		}
		$this->plugin->categories()->set( $term, $dk, $title, $theme );
		\WP_CLI::success( sprintf( 'Mapped %d → %d (%s).', $term, $dk, $title ) );
	}

	/**
	 * Search Digikala categories.
	 *
	 * ## OPTIONS
	 *
	 * <keyword>
	 * : Keyword.
	 *
	 * @subcommand search-category
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 */
	public function search_category( $args, $assoc ) {
		$rows = array();
		foreach ( $this->plugin->api()->search_categories( $args[0] ) as $c ) {
			$rows[] = array(
				'id'   => $c['id'],
				'text' => $c['text'],
				'leaf' => ! empty( $c['leaf'] ) ? 'yes' : 'no',
			);
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'text', 'leaf' ) );
	}

	/**
	 * Resolve product ids from args.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Assoc.
	 * @return int[]
	 */
	private function ids( $args, $assoc ) {
		if ( ! empty( $assoc['all'] ) ) {
			return array_map(
				'intval',
				get_posts(
					array(
						'post_type'      => 'product',
						'post_status'    => 'publish',
						'posts_per_page' => -1,
						'fields'         => 'ids',
					)
				)
			);
		}
		if ( ! $args ) {
			\WP_CLI::error( 'Pass product ids or --all.' );
		}
		return array_map( 'intval', $args );
	}
}
