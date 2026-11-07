<?php
/**
 * Products readiness table.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Data\ItemRepository;
use DKBridge\Plugin;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table.
 */
class ProductsTable extends \WP_List_Table {

	/** @var Plugin */
	private $plugin;

	/** @var array<int,array> */
	private $items_map = array();

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
		parent::__construct(
			array(
				'singular' => 'dkb_product',
				'plural'   => 'dkb_products',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox">',
			'thumb'    => '',
			'name'     => __( 'محصول', 'dk-bridge' ),
			'score'    => __( 'آمادگی', 'dk-bridge' ),
			'state'    => __( 'وضعیت دیجی‌کالا', 'dk-bridge' ),
			'issues'   => __( 'ایرادها', 'dk-bridge' ),
			'category' => __( 'گروه دیجی‌کالا', 'dk-bridge' ),
			'actions'  => '',
		);
	}

	/**
	 * Views per state.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = $this->plugin->items()->counts();
		$current = isset( $_GET['dkb_state'] ) ? sanitize_key( wp_unslash( $_GET['dkb_state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base    = admin_url( 'admin.php?page=dk-bridge-products' );
		$views   = array( 'all' => '<a href="' . esc_url( $base ) . '" class="' . ( '' === $current ? 'current' : '' ) . '">' . esc_html__( 'همه', 'dk-bridge' ) . '</a>' );
		foreach ( $counts as $state => $count ) {
			if ( ! $count ) {
				continue;
			}
			$views[ $state ] = '<a href="' . esc_url( add_query_arg( 'dkb_state', $state, $base ) ) . '" class="' . ( $state === $current ? 'current' : '' ) . '">' . esc_html( ItemRepository::STATES[ $state ] ) . ' <span class="count">(' . (int) $count . ')</span></a>';
		}
		return $views;
	}

	/**
	 * Bulk actions.
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		return array(
			'validate' => __( 'بررسی قوانین', 'dk-bridge' ),
			'autofix'  => __( 'اصلاح خودکار', 'dk-bridge' ),
			'push'     => __( 'ارسال به دیجی‌کالا', 'dk-bridge' ),
			'sync'     => __( 'همگام‌سازی موجودی و قیمت', 'dk-bridge' ),
			'exclude'  => __( 'مستثنی کردن', 'dk-bridge' ),
			'include'  => __( 'لغو استثنا', 'dk-bridge' ),
			'reset'    => __( 'حذف اتصال به دیجی‌کالا', 'dk-bridge' ),
		);
	}

	/**
	 * Category filter.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$cat = isset( $_GET['product_cat'] ) ? sanitize_title( wp_unslash( $_GET['product_cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions">';
		wp_dropdown_categories(
			array(
				'taxonomy'        => 'product_cat',
				'name'            => 'product_cat',
				'value_field'     => 'slug',
				'selected'        => $cat,
				'show_option_all' => __( 'همهٔ دسته‌ها', 'dk-bridge' ),
				'hierarchical'    => true,
				'hide_empty'      => false,
			)
		);
		submit_button( __( 'فیلتر', 'dk-bridge' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Load data.
	 */
	public function prepare_items() {
		$per   = 20;
		$paged = $this->get_pagenum();
		$args  = array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'private', 'draft' ),
			'posts_per_page' => $per,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['s'] ) ) {
			$args['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
		}
		if ( ! empty( $_GET['product_cat'] ) && '0' !== $_GET['product_cat'] ) {
			$args['product_cat'] = sanitize_title( wp_unslash( $_GET['product_cat'] ) );
		}
		if ( ! empty( $_GET['dkb_state'] ) ) {
			$ids              = $this->plugin->items()->product_ids_in_state( sanitize_key( wp_unslash( $_GET['dkb_state'] ) ) );
			$args['post__in'] = $ids ? $ids : array( 0 );
		}
		// phpcs:enable
		$query           = new \WP_Query( $args );
		$this->items     = $query->posts;
		$this->items_map = $this->plugin->items()->for_products( wp_list_pluck( $query->posts, 'ID' ) );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per,
			)
		);
	}

	/**
	 * Checkbox.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_cb( $post ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $post->ID . '">';
	}

	/**
	 * Thumb.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_thumb( $post ) {
		return get_the_post_thumbnail( $post->ID, array( 40, 40 ) );
	}

	/**
	 * Name.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_name( $post ) {
		$product = wc_get_product( $post->ID );
		$extra   = $product ? ' <span class="dkb-muted">' . esc_html( $product->is_type( 'variable' ) ? __( 'متغیر', 'dk-bridge' ) : __( 'ساده', 'dk-bridge' ) ) . ( $product->get_sku() ? ' · ' . esc_html( $product->get_sku() ) : '' ) . '</span>' : '';
		$excluded = 'yes' === get_post_meta( $post->ID, '_dkb_exclude', true ) ? ' <span class="dkb-badge">' . esc_html__( 'مستثنی', 'dk-bridge' ) . '</span>' : '';
		return '<strong><a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></strong>' . $extra . $excluded;
	}

	/**
	 * Score.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_score( $post ) {
		$item = isset( $this->items_map[ $post->ID ] ) ? $this->items_map[ $post->ID ] : null;
		return Views::score_ring( $item ? $item['score'] : null );
	}

	/**
	 * State.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_state( $post ) {
		$item = isset( $this->items_map[ $post->ID ] ) ? $this->items_map[ $post->ID ] : null;
		$html = Views::state_badge( $item );
		if ( $item && $item['last_error'] && in_array( $item['state'], array( 'error', 'rejected', 'blocked', 'in_review', 'live' ), true ) ) {
			$html .= '<div class="dkb-error-line">' . esc_html( wp_trim_words( $item['last_error'], 25 ) ) . '</div>';
		}
		return $html;
	}

	/**
	 * Issues.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_issues( $post ) {
		$item = isset( $this->items_map[ $post->ID ] ) ? $this->items_map[ $post->ID ] : null;
		if ( ! $item || null === $item['score'] ) {
			return '<span class="dkb-muted">—</span>';
		}
		$issues = array_values(
			array_filter(
				(array) $item['issues'],
				static function ( $i ) {
					return 'info' !== $i['severity'];
				}
			)
		);
		return Views::issues( $issues, 3 );
	}

	/**
	 * Category.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_category( $post ) {
		$product = wc_get_product( $post->ID );
		$map     = $product ? $this->plugin->categories()->resolve( $product ) : null;
		return $map ? esc_html( $map['dk_category_title'] ) . ' <code>#' . (int) $map['dk_category_id'] . '</code>' : '<span class="dkb-bad">' . esc_html__( 'نگاشت نشده', 'dk-bridge' ) . '</span>';
	}

	/**
	 * Row actions.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	protected function column_actions( $post ) {
		$id = (int) $post->ID;
		return '<div class="dkb-row-actions" data-id="' . $id . '">'
			. '<button type="button" class="button button-small" data-dkb-row="validate">' . esc_html__( 'بررسی', 'dk-bridge' ) . '</button> '
			. '<button type="button" class="button button-small" data-dkb-row="autofix">' . esc_html__( 'اصلاح', 'dk-bridge' ) . '</button> '
			. '<button type="button" class="button button-small" data-dkb-row="preview">' . esc_html__( 'پیش‌نمایش', 'dk-bridge' ) . '</button> '
			. '<button type="button" class="button button-small button-primary" data-dkb-row="push">' . esc_html__( 'ارسال', 'dk-bridge' ) . '</button>'
			. '</div>';
	}

	/**
	 * No items.
	 */
	public function no_items() {
		esc_html_e( 'محصولی پیدا نشد.', 'dk-bridge' );
	}
}
