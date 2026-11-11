<?php
/**
 * Admin screens: assistant, catalog, inventory, logistics, pricing, promotions, analytics, rulebook.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Api\ApiException;
use DKBridge\Intelligence\LogisticsRules;
use DKBridge\Intelligence\PromotionPlanner;
use DKBridge\Intelligence\RepricingEngine;
use DKBridge\Modules\AnalyticsService;
use DKBridge\Modules\InventoryService;
use DKBridge\Modules\LogisticsService;
use DKBridge\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Module pages.
 */
class ModulePages {

	/**
	 * Header.
	 *
	 * @param string $title Title.
	 * @param string $sub   Subtitle.
	 */
	private static function header( $title, $sub = '' ) {
		echo '<div class="wrap dkb-wrap" dir="rtl"><h1 class="dkb-title"><span class="dkb-logo">DK</span> ' . esc_html( $title ) . '</h1>';
		if ( $sub ) {
			echo '<p class="dkb-sub">' . esc_html( $sub ) . '</p>';
		}
		echo '<div id="dkb-modal" class="dkb-modal" hidden><div class="dkb-modal-box"><button type="button" class="dkb-modal-close" aria-label="close">×</button><div class="dkb-modal-body"></div></div></div>';
	}

	/**
	 * Footer.
	 */
	private static function footer() {
		echo '</div>';
	}

	/**
	 * Requires a connection.
	 *
	 * @param Plugin $plugin Plugin.
	 * @return bool
	 */
	private static function require_connection( Plugin $plugin ) {
		if ( $plugin->is_connected() ) {
			return true;
		}
		echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( sprintf( __( 'این بخش به اتصال API نیاز دارد. <a href="%s">اتصال به دیجی‌کالا</a>', 'dk-bridge' ), esc_url( admin_url( 'admin.php?page=dk-bridge-settings' ) ) ) ) . '</p></div>';
		self::footer();
		return false;
	}

	/**
	 * Inline API error box.
	 *
	 * @param \Throwable $e Error.
	 */
	private static function error( $e ) {
		$msg = $e instanceof ApiException ? $e->full_message() : $e->getMessage();
		echo '<div class="notice notice-error inline"><p>' . esc_html( $msg ) . '</p></div>';
	}

	/**
	 * Money (Rial) → readable Toman.
	 *
	 * @param int|null $rial Rial.
	 * @return string
	 */
	public static function money( $rial ) {
		if ( null === $rial || '' === $rial ) {
			return '—';
		}
		return number_format_i18n( (int) round( (int) $rial / 10 ) ) . ' ' . __( 'تومان', 'dk-bridge' );
	}

	/**
	 * Pager links.
	 *
	 * @param int $total Total.
	 * @param int $page  Page.
	 * @param int $per   Per page.
	 */
	private static function pager( $total, $page, $per ) {
		$pages = (int) ceil( $total / max( 1, $per ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<p class="dkb-pager">';
		for ( $i = 1; $i <= min( $pages, 30 ); $i++ ) {
			echo $i === $page ? '<strong>' . (int) $i . '</strong> ' : '<a class="button button-small" href="' . esc_url( add_query_arg( 'paged', $i ) ) . '">' . (int) $i . '</a> ';
		}
		echo '</p>';
	}

	// --------------------------------------------------------------- Assistant.

	/**
	 * The daily assistant.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function assistant( Plugin $plugin ) {
		self::header( __( 'دستیار دیجی‌کالا — کارهای امروز', 'dk-bridge' ), __( 'همهٔ کارهایی که امروز به توجه شما نیاز دارند، به ترتیب اولویت: ناموجودی کالاهای پرفروش، تعهدهای ارسال، بای‌باکس، کالاهای ردشده، پروموشن‌ها و شاخص‌های عملکرد.', 'dk-bridge' ) );
		$refresh = isset( $_GET['refresh'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tasks   = $plugin->assistant()->tasks( $refresh );
		echo '<p><a class="button" href="' . esc_url( add_query_arg( 'refresh', 1 ) ) . '">' . esc_html__( 'به‌روزرسانی', 'dk-bridge' ) . '</a></p>';
		if ( ! $tasks ) {
			echo '<div class="dkb-card"><p class="dkb-ok dkb-big">' . esc_html__( 'کار فوری‌ای نیست. 🎉', 'dk-bridge' ) . '</p></div>';
		} else {
			$labels = array(
				1 => __( 'فوری', 'dk-bridge' ),
				2 => __( 'مهم', 'dk-bridge' ),
				3 => __( 'به‌زودی', 'dk-bridge' ),
				4 => __( 'بهبود', 'dk-bridge' ),
				5 => __( 'پیشنهاد', 'dk-bridge' ),
			);
			echo '<div class="dkb-tasks">';
			foreach ( $tasks as $t ) {
				echo '<a class="dkb-task dkb-task-p' . (int) $t['priority'] . '" href="' . esc_url( $t['link'] ) . '"><span class="dkb-task-badge">' . esc_html( $labels[ $t['priority'] ] ) . ' · ' . esc_html( $t['area'] ) . '</span><strong>' . esc_html( $t['title'] ) . ' <span class="dkb-task-count">' . esc_html( number_format_i18n( $t['count'] ) ) . '</span></strong><span class="dkb-muted">' . esc_html( $t['detail'] ) . '</span></a>';
			}
			echo '</div>';
		}
		echo '<h2>' . esc_html__( 'میان‌برها', 'dk-bridge' ) . '</h2><div class="dkb-grid">';
		$links = array(
			'dk-bridge-products'   => array( __( 'مدیریت محتوا و ارسال', 'dk-bridge' ), __( 'بررسی قوانین، اصلاح خودکار، ارسال کالای جدید', 'dk-bridge' ) ),
			'dk-bridge-catalog'    => array( __( 'مدیریت کالا', 'dk-bridge' ), __( 'همهٔ تنوع‌های دیجی‌کالا، اتصال، فعال‌سازی، ویرایش گروهی', 'dk-bridge' ) ),
			'dk-bridge-inventory'  => array( __( 'مدیریت موجودی', 'dk-bridge' ), __( 'سلامت موجودی، سرعت فروش، مغایرت‌ها', 'dk-bridge' ) ),
			'dk-bridge-logistics'  => array( __( 'لجستیک هوشمند', 'dk-bridge' ), __( 'برنامهٔ تأمین انبار، محموله، تعهدها، ارسال توسط فروشنده', 'dk-bridge' ) ),
			'dk-bridge-pricing'    => array( __( 'قیمت‌گذاری هوشمند', 'dk-bridge' ), __( 'بای‌باکس با کف سود و محافظ نوسان', 'dk-bridge' ) ),
			'dk-bridge-promotions' => array( __( 'پروموشن‌ها', 'dk-bridge' ), __( 'برنامهٔ سودآور پیوستن، تخفیف هوشمند', 'dk-bridge' ) ),
			'dk-bridge-analytics'  => array( __( 'تحلیل عملکرد', 'dk-bridge' ), __( 'فروش، بازدید، فروش ازدست‌رفته، شاخص‌ها', 'dk-bridge' ) ),
			'dk-bridge-rules'      => array( __( 'قوانین دیجی‌کالا', 'dk-bridge' ), __( 'خلاصهٔ قواعد درج کالا، انبار، پروموشن و جریمه‌ها', 'dk-bridge' ) ),
		);
		foreach ( $links as $page => $l ) {
			echo '<a class="dkb-card dkb-link-card" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '"><h3>' . esc_html( $l[0] ) . '</h3><p class="dkb-muted">' . esc_html( $l[1] ) . '</p></a>';
		}
		echo '</div>';
		self::footer();
	}

	// ----------------------------------------------------------------- Catalog.

	/**
	 * Catalog (all Digikala variants).
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function catalog( Plugin $plugin ) {
		self::header( __( 'مدیریت کالا', 'dk-bridge' ), __( 'همهٔ تنوع‌های شما در دیجی‌کالا — حتی کالاهایی که قبلاً دستی درج کرده‌اید. تنوع‌ها با SKU یا کد تأمین‌کننده خودکار به محصولات ووکامرس وصل می‌شوند تا موجودی و قیمتشان همگام شود. تنوع و کالا در دیجی‌کالا حذف‌شدنی نیستند؛ فقط غیرفعال یا بایگانی می‌شوند.', 'dk-bridge' ) );
		if ( ! self::require_connection( $plugin ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$f    = array(
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'active'   => isset( $_GET['active'] ) ? sanitize_text_field( wp_unslash( $_GET['active'] ) ) : '',
			'linked'   => isset( $_GET['linked'] ) ? sanitize_text_field( wp_unslash( $_GET['linked'] ) ) : '',
			'buybox'   => isset( $_GET['buybox'] ) ? sanitize_key( wp_unslash( $_GET['buybox'] ) ) : '',
			'stock'    => isset( $_GET['stock'] ) ? sanitize_key( wp_unslash( $_GET['stock'] ) ) : '',
			'archived' => isset( $_GET['archived'] ) ? sanitize_text_field( wp_unslash( $_GET['archived'] ) ) : '0',
		);
		$page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$sum  = $plugin->variants()->summary();
		$sync = (int) get_option( 'dkb_catalog_synced_at' );

		echo '<div class="dkb-kpis">';
		foreach ( array(
			'total'        => __( 'کل تنوع‌ها', 'dk-bridge' ),
			'active'       => __( 'فعال', 'dk-bridge' ),
			'linked'       => __( 'متصل به ووکامرس', 'dk-bridge' ),
			'winners'      => __( 'برندهٔ بای‌باکس', 'dk-bridge' ),
			'losing'       => __( 'بازندهٔ بای‌باکس', 'dk-bridge' ),
			'out_of_stock' => __( 'فعالِ ناموجود', 'dk-bridge' ),
			'in_promotion' => __( 'در پروموشن', 'dk-bridge' ),
		) as $k => $label ) {
			echo '<div class="dkb-kpi"><span>' . esc_html( $label ) . '</span><b>' . esc_html( number_format_i18n( isset( $sum[ $k ] ) ? (int) $sum[ $k ] : 0 ) ) . '</b></div>';
		}
		echo '</div>';
		echo '<p><button type="button" class="button button-primary" data-dkb-call="m/catalog/sync" data-reload="1">' . esc_html__( 'همگام‌سازی کاتالوگ از دیجی‌کالا', 'dk-bridge' ) . '</button> <span class="dkb-muted">' . ( $sync ? esc_html( sprintf( __( 'آخرین همگام‌سازی: %s پیش', 'dk-bridge' ), human_time_diff( $sync ) ) ) : esc_html__( 'هنوز همگام نشده', 'dk-bridge' ) ) . '</span> <span class="dkb-result"></span></p>';

		echo '<form method="get" class="dkb-filters"><input type="hidden" name="page" value="dk-bridge-catalog"><input type="search" name="s" value="' . esc_attr( $f['search'] ) . '" placeholder="' . esc_attr__( 'عنوان، DKPC، DKP یا کد تأمین‌کننده', 'dk-bridge' ) . '"> ';
		$selects = array(
			'active'   => array( '' => __( 'همه', 'dk-bridge' ), '1' => __( 'فعال', 'dk-bridge' ), '0' => __( 'غیرفعال', 'dk-bridge' ) ),
			'linked'   => array( '' => __( 'اتصال: همه', 'dk-bridge' ), '1' => __( 'متصل', 'dk-bridge' ), '0' => __( 'متصل‌نشده', 'dk-bridge' ) ),
			'buybox'   => array( '' => __( 'بای‌باکس: همه', 'dk-bridge' ), 'winner' => __( 'برنده', 'dk-bridge' ), 'loser' => __( 'بازنده', 'dk-bridge' ) ),
			'stock'    => array( '' => __( 'موجودی: همه', 'dk-bridge' ), 'in' => __( 'موجود', 'dk-bridge' ), 'out' => __( 'ناموجود', 'dk-bridge' ) ),
			'archived' => array( '0' => __( 'بدون بایگانی', 'dk-bridge' ), '1' => __( 'بایگانی‌شده', 'dk-bridge' ), '' => __( 'همه', 'dk-bridge' ) ),
		);
		foreach ( $selects as $name => $opts ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			foreach ( $opts as $k => $l ) {
				echo '<option value="' . esc_attr( $k ) . '" ' . selected( (string) $f[ $name ], (string) $k, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select> ';
		}
		echo '<button class="button">' . esc_html__( 'فیلتر', 'dk-bridge' ) . '</button></form>';

		$res = $plugin->variants()->search( $f, $page, 50 );
		echo '<div class="dkb-bulkbar" data-scope="catalog"><strong>' . esc_html__( 'برای انتخاب‌شده‌ها:', 'dk-bridge' ) . '</strong> ';
		echo '<button type="button" class="button" data-dkb-call="m/catalog/activate" data-body="{&quot;active&quot;:true}" data-ids=".dkb-sel" data-reload="1">' . esc_html__( 'فعال', 'dk-bridge' ) . '</button> ';
		echo '<button type="button" class="button" data-dkb-call="m/catalog/activate" data-body="{&quot;active&quot;:false}" data-ids=".dkb-sel" data-confirm="1" data-reload="1">' . esc_html__( 'غیرفعال', 'dk-bridge' ) . '</button> ';
		echo '<button type="button" class="button" data-dkb-call="m/catalog/archive" data-body="{&quot;archive&quot;:true}" data-ids=".dkb-sel" data-confirm="1" data-reload="1">' . esc_html__( 'بایگانی', 'dk-bridge' ) . '</button> ';
		echo '<button type="button" class="button" data-dkb-call="m/catalog/archive" data-body="{&quot;archive&quot;:false}" data-ids=".dkb-sel" data-reload="1">' . esc_html__( 'خروج از بایگانی', 'dk-bridge' ) . '</button> ';
		echo '<span class="dkb-sep"></span><label>' . esc_html__( 'حداکثر در سفارش', 'dk-bridge' ) . ' <input type="number" min="1" class="small-text" data-field="payload.maximum_per_order"></label> ';
		echo '<label>' . esc_html__( 'زمان ارسال (روز)', 'dk-bridge' ) . ' <input type="number" min="1" class="small-text" data-field="payload.lead_time"></label> ';
		echo '<button type="button" class="button" data-dkb-call="m/catalog/bulk" data-ids=".dkb-sel" data-fields="1">' . esc_html__( 'اعمال ویرایش گروهی', 'dk-bridge' ) . '</button> <span class="dkb-result"></span></div>';

		echo '<table class="widefat striped dkb-table"><thead><tr><th><input type="checkbox" class="dkb-sel-all"></th><th></th><th>' . esc_html__( 'تنوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'قیمت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'بای‌باکس', 'dk-bridge' ) . '</th><th>' . esc_html__( 'موجودی قابل فروش', 'dk-bridge' ) . '</th><th>' . esc_html__( 'وضعیت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'ووکامرس', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $v ) {
			$id   = (int) $v['dk_variant_id'];
			$bb   = (int) $v['is_buy_box_winner'] ? '<span class="dkb-badge dkb-state-live">' . esc_html__( 'برنده', 'dk-bridge' ) . '</span>' : ( (int) $v['in_challenge'] ? '<span class="dkb-badge dkb-state-rejected">' . esc_html__( 'بازنده', 'dk-bridge' ) . '</span> ' . esc_html( self::money( $v['buy_box_price'] ) ) : '<span class="dkb-muted">' . esc_html__( 'بدون رقیب', 'dk-bridge' ) . '</span>' );
			$link = $v['wc_product_id'] ? '<a href="' . esc_url( get_edit_post_link( (int) $v['wc_product_id'] ) ) . '">#' . (int) ( $v['wc_variation_id'] ? $v['wc_variation_id'] : $v['wc_product_id'] ) . '</a> <span class="dkb-muted">(' . esc_html( (string) $v['link_method'] ) . ')</span> <button type="button" class="button-link" data-dkb-call="m/catalog/unlink" data-body="' . esc_attr( wp_json_encode( array( 'variant_id' => $id ) ) ) . '" data-confirm="1" data-reload="1">' . esc_html__( 'قطع', 'dk-bridge' ) . '</button>'
				: '<input type="text" class="small-text dkb-link-input" placeholder="ID / SKU" data-field="wc_id"> <button type="button" class="button button-small" data-dkb-call="m/catalog/link" data-body="' . esc_attr( wp_json_encode( array( 'variant_id' => $id ) ) ) . '" data-fields="row" data-reload="1">' . esc_html__( 'اتصال', 'dk-bridge' ) . '</button>';
			echo '<tr><td><input type="checkbox" class="dkb-sel" value="' . $id . '"></td><td>' . ( $v['image'] ? '<img src="' . esc_url( $v['image'] ) . '" width="36" height="36" alt="">' : '' ) . '</td>';
			echo '<td><a target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://www.digikala.com/product/dkp-' . (int) $v['dk_product_id'] . '/' ) . '">' . esc_html( (string) $v['title'] ) . '</a><div class="dkb-muted">DKPC-' . $id . ( $v['supplier_code'] ? ' · ' . esc_html( $v['supplier_code'] ) : '' ) . ( (int) $v['dead_stock'] ? ' · <span class="dkb-bad">' . esc_html__( 'موجودی راکد', 'dk-bridge' ) . '</span>' : '' ) . '</div></td>';
			echo '<td>' . esc_html( self::money( $v['price_sale'] ) ) . ( (int) $v['in_promotion'] ? '<div class="dkb-muted">' . esc_html__( 'پروموشن:', 'dk-bridge' ) . ' ' . esc_html( self::money( $v['promotion_price'] ) ) . '</div>' : '' ) . '</td>';
			echo '<td>' . $bb . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . (int) $v['selling_stock'] . '<div class="dkb-muted">' . esc_html( sprintf( __( 'نزد شما %1$d · انبار %2$d · در راه %3$d', 'dk-bridge' ), $v['seller_stock'], $v['warehouse_stock'], $v['on_the_way_stock'] ) ) . '</div></td>';
			echo '<td>' . ( (int) $v['archived'] ? esc_html__( 'بایگانی', 'dk-bridge' ) : ( (int) $v['active'] ? '<span class="dkb-good">' . esc_html__( 'فعال', 'dk-bridge' ) . '</span>' : '<span class="dkb-muted">' . esc_html__( 'غیرفعال', 'dk-bridge' ) . '</span>' ) ) . ( $v['dk_product_id'] ? ' <button type="button" class="button-link" data-dkb-call="m/catalog/score/' . (int) $v['dk_product_id'] . '" data-method="GET" data-modal="score">' . esc_html__( 'امتیاز محتوا', 'dk-bridge' ) . '</button>' : '' ) . '</td>';
			echo '<td class="dkb-row-fields">' . $link . '<span class="dkb-result"></span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'تنوعی پیدا نشد. «همگام‌سازی کاتالوگ» را بزنید.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::pager( $res['total'], $page, 50 );
		self::footer();
	}

	// --------------------------------------------------------------- Inventory.

	/**
	 * Inventory.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function inventory( Plugin $plugin ) {
		self::header( __( 'مدیریت موجودی', 'dk-bridge' ), __( 'سلامت موجودی هر تنوع بر اساس سرعت فروش در دیجی‌کالا: چند روز دیگر تمام می‌شود، کدام کالا راکد است و کجا موجودی سایت با دیجی‌کالا فرق دارد.', 'dk-bridge' ) );
		if ( ! self::require_connection( $plugin ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filters = array(
			'health'      => isset( $_GET['health'] ) ? sanitize_key( wp_unslash( $_GET['health'] ) ) : '',
			'discrepancy' => ! empty( $_GET['discrepancy'] ),
			'dead'        => ! empty( $_GET['dead'] ),
			'search'      => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
		);
		$page    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$data = $plugin->inventory_service()->overview( $filters, $page );
		$base = admin_url( 'admin.php?page=dk-bridge-inventory' );
		echo '<div class="dkb-kpis">';
		foreach ( InventoryService::HEALTH as $key => $label ) {
			echo '<a class="dkb-kpi dkb-health-' . esc_attr( $key ) . '" href="' . esc_url( add_query_arg( 'health', $key, $base ) ) . '"><span>' . esc_html( $label ) . '</span><b>' . esc_html( number_format_i18n( $data['summary'][ $key ] ) ) . '</b></a>';
		}
		echo '<a class="dkb-kpi" href="' . esc_url( add_query_arg( 'discrepancy', 1, $base ) ) . '"><span>' . esc_html__( 'مغایرت با سایت', 'dk-bridge' ) . '</span><b>' . esc_html( number_format_i18n( $data['summary']['discrepancy'] ) ) . '</b></a>';
		echo '<div class="dkb-kpi"><span>' . esc_html__( 'ارزش موجودی قابل فروش', 'dk-bridge' ) . '</span><b>' . esc_html( self::money( $data['summary']['stock_value'] ) ) . '</b></div></div>';
		echo '<p><button type="button" class="button button-primary" data-dkb-call="m/inventory/fix" data-reload="1">' . esc_html__( 'رفع همهٔ مغایرت‌ها (ارسال موجودی سایت)', 'dk-bridge' ) . '</button> <button type="button" class="button" data-dkb-call="m/inventory/alerts">' . esc_html__( 'ارسال گزارش هشدار', 'dk-bridge' ) . '</button> <a class="button" href="' . esc_url( $base ) . '">' . esc_html__( 'همه', 'dk-bridge' ) . '</a> <span class="dkb-result"></span></p>';
		echo '<table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'تنوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'وضعیت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'قابل فروش', 'dk-bridge' ) . '</th><th>' . esc_html__( 'نزد شما / انبار / در راه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'فروش ۳۰ روز', 'dk-bridge' ) . '</th><th>' . esc_html__( 'فروش روزانه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'پوشش (روز)', 'dk-bridge' ) . '</th><th>' . esc_html__( 'ووکامرس', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $data['rows'] as $r ) {
			$v = $r['variant'];
			echo '<tr><td>' . esc_html( (string) $v['title'] ) . '<div class="dkb-muted">DKPC-' . (int) $v['dk_variant_id'] . '</div></td>';
			echo '<td><span class="dkb-badge dkb-health-' . esc_attr( $r['health'] ) . '">' . esc_html( InventoryService::HEALTH[ $r['health'] ] ) . '</span>' . ( (int) $v['dead_stock'] ? ' <span class="dkb-badge">' . esc_html__( 'راکد', 'dk-bridge' ) . '</span>' : '' ) . '</td>';
			echo '<td><b>' . (int) $v['selling_stock'] . '</b></td><td>' . (int) $v['seller_stock'] . ' / ' . (int) $v['warehouse_stock'] . ' / ' . (int) $v['on_the_way_stock'] . '</td>';
			echo '<td>' . (int) $r['sold'] . '</td><td>' . esc_html( (string) $r['velocity'] ) . '</td><td>' . esc_html( null === $r['cover'] ? '—' : (string) $r['cover'] ) . '</td>';
			echo '<td>' . ( null === $r['announce'] ? '<span class="dkb-muted">' . esc_html__( 'متصل نیست', 'dk-bridge' ) . '</span>' : esc_html( sprintf( __( 'اعلامی %d', 'dk-bridge' ), $r['announce'] ) ) . ( $r['discrepancy'] ? ' <span class="dkb-bad">≠ ' . (int) $v['seller_stock'] . '</span> <button type="button" class="button button-small" data-dkb-call="m/inventory/fix" data-body="' . esc_attr( wp_json_encode( array( 'ids' => array( (int) $v['dk_variant_id'] ) ) ) ) . '">' . esc_html__( 'همگام کن', 'dk-bridge' ) . '</button><span class="dkb-result"></span>' : '' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'موردی نیست. ابتدا کاتالوگ را همگام کنید.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::pager( $data['total'], $page, 50 );
		self::footer();
	}

	// --------------------------------------------------------------- Logistics.

	/**
	 * Logistics.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function logistics( Plugin $plugin ) {
		self::header( __( 'لجستیک هوشمند', 'dk-bridge' ), __( 'چه چیزی، چه تعدادی و کِی به انبار دیجی‌کالا بفرستید؛ تعهدهای ارسال و جریمه‌ها؛ و مدیریت سفارش‌های «ارسال توسط فروشنده».', 'dk-bridge' ) );
		if ( ! self::require_connection( $plugin ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'plan'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'plan'        => __( 'برنامهٔ تأمین انبار', 'dk-bridge' ),
			'commitments' => __( 'تعهدهای ارسال', 'dk-bridge' ),
			'packages'    => __( 'محموله‌ها', 'dk-bridge' ),
			'sbs'         => __( 'ارسال توسط فروشنده', 'dk-bridge' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			echo '<a class="nav-tab ' . ( $k === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-logistics&tab=' . $k ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav>';
		try {
			switch ( $tab ) {
				case 'commitments':
					self::logistics_commitments( $plugin );
					break;
				case 'packages':
					self::logistics_packages( $plugin );
					break;
				case 'sbs':
					self::logistics_sbs( $plugin );
					break;
				default:
					self::logistics_plan( $plugin );
			}
		} catch ( \Throwable $e ) {
			self::error( $e );
		}
		self::footer();
	}

	/**
	 * Replenishment plan tab.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	private static function logistics_plan( Plugin $plugin ) {
		$plan = $plugin->logistics()->replenishment_plan();
		echo '<div class="dkb-card dkb-help"><p>' . esc_html( sprintf( __( 'قواعد دیجی‌کالا: انبارش (دپو) فقط برای کالایی ممکن است که در ۳۰ روز اخیر دست‌کم %1$d فروش عادی داشته باشد و سقف آن ۲۰٪ همان فروش است. هر کالای ثبت‌نشده در محموله %2$s جریمهٔ مغایرت دارد. کالاهای دارای تاریخ انقضا باید هنگام تحویل دو سوم عمر (یا ۶ ماه) اعتبار داشته باشند. انبار کالای کوچک: %3$s — کالای بزرگ و متوسط: %4$s.', 'dk-bridge' ), LogisticsRules::DEPOT_MIN_SALES, self::money( LogisticsRules::discrepancy_penalty( 1 ) ), implode( '، ', LogisticsRules::WAREHOUSES['small'] ), implode( '، ', LogisticsRules::WAREHOUSES['large'] ) ) ) . '</p></div>';
		echo '<div class="dkb-package-form"><label>' . esc_html__( 'تاریخ تحویل به انبار', 'dk-bridge' ) . ' <input type="date" id="dkb-pkg-date" value="' . esc_attr( gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) . '"></label> <label>' . esc_html__( 'روش تحویل', 'dk-bridge' ) . ' <select id="dkb-pkg-delivery"><option value="digikala">' . esc_html__( 'تحویل به انبار دیجی‌کالا', 'dk-bridge' ) . '</option><option value="seller">' . esc_html__( 'جمع‌آوری از فروشنده', 'dk-bridge' ) . '</option><option value="mobile_hub">' . esc_html__( 'انبار سیار', 'dk-bridge' ) . '</option></select></label> <button type="button" class="button button-primary" id="dkb-pkg-create" data-type="consignment">' . esc_html__( 'ساخت محمولهٔ انبارش از تعدادهای زیر', 'dk-bridge' ) . '</button> <span class="dkb-result" id="dkb-pkg-result"></span></div>';
		echo '<table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'تنوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'فوریت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'فروش روزانه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'انبار + در راه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'پوشش (روز)', 'dk-bridge' ) . '</th><th>' . esc_html__( 'سقف مجاز', 'dk-bridge' ) . '</th><th>' . esc_html__( 'موجودی شما', 'dk-bridge' ) . '</th><th>' . esc_html__( 'تعداد ارسال', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $plan as $p ) {
			echo '<tr><td>' . esc_html( $p['title'] ) . '<div class="dkb-muted">DKPC-' . (int) $p['variant_id'] . ' · ' . esc_html( $p['nature'] ) . '</div></td>';
			echo '<td><span class="dkb-badge dkb-urgency-' . esc_attr( $p['urgency'] ) . '">' . esc_html( LogisticsService::URGENCY[ $p['urgency'] ] ) . '</span><div class="dkb-muted">' . esc_html( $p['reason'] ) . '</div></td>';
			echo '<td>' . esc_html( (string) $p['velocity'] ) . '</td><td>' . (int) $p['dk_stock'] . ' + ' . (int) $p['on_the_way'] . '</td><td>' . esc_html( null === $p['cover'] ? '—' : (string) $p['cover'] ) . '</td>';
			echo '<td>' . esc_html( null === $p['allowed'] ? '—' : (string) $p['allowed'] ) . '</td><td>' . esc_html( null === $p['available'] ? '—' : (string) $p['available'] ) . '</td>';
			echo '<td><input type="number" min="0" class="small-text dkb-pkg-qty" data-variant="' . (int) $p['variant_id'] . '" value="' . (int) $p['qty'] . '"' . ( null !== $p['allowed'] ? ' max="' . (int) $p['allowed'] . '"' : '' ) . '></td></tr>';
		}
		if ( ! $plan ) {
			echo '<tr><td colspan="8">' . esc_html__( 'کالایی برای انبارش پیدا نشد.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Commitments tab.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	private static function logistics_commitments( Plugin $plugin ) {
		$c    = $plugin->logistics()->commitments();
		$meta = $c['meta'];
		if ( ! empty( $meta['summary_statistics'] ) ) {
			$s = $meta['summary_statistics'];
			echo '<div class="dkb-kpis"><div class="dkb-kpi"><span>' . esc_html__( 'کل تعهدها', 'dk-bridge' ) . '</span><b>' . esc_html( number_format_i18n( (int) ( isset( $s['totalCommitments'] ) ? $s['totalCommitments'] : 0 ) ) ) . '</b></div><div class="dkb-kpi"><span>' . esc_html__( 'تعهدهای مؤثر', 'dk-bridge' ) . '</span><b>' . esc_html( number_format_i18n( (int) ( isset( $s['effectiveCommitments'] ) ? $s['effectiveCommitments'] : 0 ) ) ) . '</b></div><div class="dkb-kpi dkb-health-out"><span>' . esc_html__( 'جریمهٔ تا امروز', 'dk-bridge' ) . '</span><b>' . esc_html( self::money( isset( $s['totalPenalty'] ) ? $s['totalPenalty'] : 0 ) ) . '</b></div></div>';
		}
		if ( ! empty( $meta['package_config'] ) ) {
			echo '<p class="dkb-muted">' . esc_html__( 'مهلت ساخت محموله:', 'dk-bridge' ) . ' ';
			foreach ( (array) $meta['package_config'] as $k => $d ) {
				echo esc_html( $k . ': ' . $d ) . ' · ';
			}
			echo '</p>';
		}
		if ( ! empty( $meta['error'] ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $meta['error'] ) . '</p></div>';
		}
		echo '<p class="dkb-muted">' . esc_html__( 'قاعدهٔ بازهٔ ارسال: سفارش‌های قبل از ساعت ۸ صبح روز جاری را هم شامل می‌شوند؛ جمعه‌ها و تعطیلات رسمی شمرده نمی‌شوند.', 'dk-bridge' ) . '</p>';
		echo '<table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'تنوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'با تأخیر', 'dk-bridge' ) . '</th><th>' . esc_html__( 'امروز', 'dk-bridge' ) . '</th><th>' . esc_html__( 'روزهای بعد', 'dk-bridge' ) . '</th><th>' . esc_html__( 'موجودی انبار / در راه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'سایز', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $c['items'], 0, 200 ) as $row ) {
			$cm = isset( $row['commitment'] ) ? $row['commitment'] : array();
			$st = isset( $row['stock'] ) ? $row['stock'] : array();
			echo '<tr><td>' . esc_html( isset( $row['titleFa'] ) ? $row['titleFa'] : '' ) . '<div class="dkb-muted">DKPC-' . (int) ( isset( $row['variantId'] ) ? $row['variantId'] : 0 ) . '</div></td><td class="' . ( ! empty( $cm['delayed'] ) ? 'dkb-bad' : '' ) . '">' . (int) ( isset( $cm['delayed'] ) ? $cm['delayed'] : 0 ) . '</td><td>' . (int) ( isset( $cm['today'] ) ? $cm['today'] : 0 ) . '</td><td>' . (int) ( isset( $cm['nextDays'] ) ? $cm['nextDays'] : 0 ) . '</td><td>' . (int) ( isset( $st['warehouse'] ) ? $st['warehouse'] : 0 ) . ' / ' . (int) ( isset( $st['onTheWay'] ) ? $st['onTheWay'] : 0 ) . '</td><td>' . esc_html( isset( $row['natureLabel'] ) ? $row['natureLabel'] : '' ) . '</td></tr>';
		}
		if ( ! $c['items'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'تعهد فعالی نیست.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Packages tab.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	private static function logistics_packages( Plugin $plugin ) {
		$rows = $plugin->logistics()->packages();
		echo '<table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'شماره', 'dk-bridge' ) . '</th><th>' . esc_html__( 'نوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'وضعیت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'تحویل', 'dk-bridge' ) . '</th><th>' . esc_html__( 'انبار', 'dk-bridge' ) . '</th><th>' . esc_html__( 'ایجاد', 'dk-bridge' ) . '</th><th>' . esc_html__( 'لیبل', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $p ) {
			$label = ! empty( $p['package_label_pdf_uri'] ) ? '<a target="_blank" rel="noopener noreferrer" href="' . esc_url( 'https://seller.digikala.com' . $p['package_label_pdf_uri'] ) . '">PDF</a>' : '—';
			echo '<tr><td>' . esc_html( isset( $p['package_number'] ) ? $p['package_number'] : '' ) . '</td><td>' . esc_html( isset( $p['type']['title'] ) ? $p['type']['title'] : '' ) . '</td><td>' . esc_html( isset( $p['status']['title'] ) ? $p['status']['title'] : '' ) . '</td><td>' . esc_html( isset( $p['delivery_type']['title'] ) ? $p['delivery_type']['title'] : '' ) . '</td><td>' . esc_html( isset( $p['warehouse']['title'] ) ? $p['warehouse']['title'] : '' ) . '</td><td>' . esc_html( isset( $p['created_at'] ) ? substr( $p['created_at'], 0, 10 ) : '' ) . '</td><td>' . $label . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="7">' . esc_html__( 'محموله‌ای نیست.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table><p class="dkb-muted">' . esc_html__( 'یادآوری انبار: روی هر کالا لیبل نارنجی خودش و روی جعبهٔ مادر فقط لیبل شمارهٔ محموله. بروشور تبلیغاتی یا اطلاعات تماس داخل بسته تخلف است.', 'dk-bridge' ) . '</p>';
	}

	/**
	 * Ship-by-seller tab.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	private static function logistics_sbs( Plugin $plugin ) {
		$rows = $plugin->logistics()->sbs_orders( array( 'status' => 'pending' ) );
		echo '<p class="dkb-muted">' . esc_html__( 'اطلاعات مشتری فقط نمایش داده می‌شود و در سایت شما ذخیره نمی‌شود. چاپ شمارهٔ تماس فروشگاه روی بسته تخلف است.', 'dk-bridge' ) . '</p>';
		echo '<table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'محموله', 'dk-bridge' ) . '</th><th>' . esc_html__( 'اقلام', 'dk-bridge' ) . '</th><th>' . esc_html__( 'تعهد', 'dk-bridge' ) . '</th><th>' . esc_html__( 'مقصد', 'dk-bridge' ) . '</th><th>' . esc_html__( 'وضعیت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'اقدام', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $o ) {
			$sid   = (int) $o['shipmentId'];
			$items = array();
			foreach ( isset( $o['variants'] ) ? (array) $o['variants'] : array() as $v ) {
				$items[] = $v['title'] . ' × ' . (int) $v['count'];
			}
			$next = isset( $o['nextStatus'] ) ? (string) $o['nextStatus'] : '';
			echo '<tr><td>#' . $sid . '<div class="dkb-muted">' . esc_html__( 'سفارش', 'dk-bridge' ) . ' ' . (int) $o['orderId'] . '</div></td><td>' . esc_html( implode( '، ', $items ) ) . '</td><td class="' . ( ! empty( $o['isDelayed'] ) ? 'dkb-bad' : '' ) . '">' . esc_html( isset( $o['promiseDate'] ) ? $o['promiseDate'] : '' ) . '</td><td>' . esc_html( trim( ( isset( $o['address']['city'] ) ? $o['address']['city'] : '' ) . ' ' . ( isset( $o['address']['district'] ) ? $o['address']['district'] : '' ) ) ) . '</td><td>' . esc_html( isset( $o['status']['text_fa'] ) ? $o['status']['text_fa'] : '' ) . '</td><td class="dkb-row-fields">';
			if ( 'full_delivered_to_customer' === $next ) {
				echo '<input type="text" class="small-text" placeholder="' . esc_attr__( 'کد تحویل', 'dk-bridge' ) . '" data-field="code"> ';
			}
			if ( $next ) {
				echo '<button type="button" class="button button-small" data-dkb-call="m/logistics/sbs/status" data-body="' . esc_attr( wp_json_encode( array( 'shipment_id' => $sid, 'status' => $next ) ) ) . '" data-fields="row" data-reload="1">' . esc_html( 'processing' === $next ? __( 'در حال آماده‌سازی', 'dk-bridge' ) : ( 'processed' === $next ? __( 'ارسال شد', 'dk-bridge' ) : __( 'تحویل شد', 'dk-bridge' ) ) ) . '</button> ';
			}
			if ( isset( $o['shippingProvider'] ) && 'seller_post' === $o['shippingProvider'] ) {
				echo '<input type="text" class="small-text" placeholder="' . esc_attr__( 'کد رهگیری پست', 'dk-bridge' ) . '" data-field="code"> <button type="button" class="button button-small" data-dkb-call="m/logistics/sbs/tracking" data-body="' . esc_attr( wp_json_encode( array( 'shipment_id' => $sid ) ) ) . '" data-fields="row">' . esc_html__( 'ثبت رهگیری', 'dk-bridge' ) . '</button>';
			}
			echo '<span class="dkb-result"></span></td></tr>';
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'سفارش در انتظاری نیست.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	// ----------------------------------------------------------------- Pricing.

	/**
	 * Smart pricing.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function pricing( Plugin $plugin ) {
		self::header( __( 'قیمت‌گذاری هوشمند', 'dk-bridge' ), __( 'قیمت هر تنوع را برای برنده شدن بای‌باکس تنظیم می‌کند، اما هرگز زیر «کف سود» (قیمت پایه + حداقل سود + کمیسیون) نمی‌رود. نوسان قیمت در ۷ روز محدود می‌شود تا شرط ورود به «پیشنهاد شگفت‌انگیز» از دست نرود و تخلف «تخفیف کاذب» رخ ندهد.', 'dk-bridge' ) );
		if ( ! self::require_connection( $plugin ) ) {
			return;
		}
		$s = $plugin->settings();
		echo '<div class="dkb-card"><p>' . esc_html__( 'وضعیت:', 'dk-bridge' ) . ' <b class="' . ( $s->get( 'repricer_enabled' ) ? 'dkb-good' : 'dkb-bad' ) . '">' . ( $s->get( 'repricer_enabled' ) ? esc_html__( 'فعال', 'dk-bridge' ) : esc_html__( 'غیرفعال', 'dk-bridge' ) ) . '</b> · ' . esc_html__( 'راهبرد پیش‌فرض:', 'dk-bridge' ) . ' <b>' . esc_html( RepricingEngine::STRATEGIES[ $s->get( 'repricer_strategy', 'fixed' ) ] ) . '</b> · ' . esc_html( sprintf( __( 'حداکثر نوسان هفتگی: %s٪ · حداقل سود: %s٪', 'dk-bridge' ), $s->get( 'repricer_max_weekly_change' ), $s->get( 'min_profit_percent' ) ) ) . ' · <a href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-settings&tab=smart' ) ) . '">' . esc_html__( 'تنظیمات', 'dk-bridge' ) . '</a></p>';
		echo '<p>' . esc_html__( 'راهبرد اختصاصی، کف و سقف هر محصول را در جعبهٔ «دیجی‌کالا» صفحهٔ ویرایش محصول تعیین کنید.', 'dk-bridge' ) . '</p>';
		echo '<button type="button" class="button" data-dkb-call="m/pricing/run" data-body="{&quot;apply&quot;:false}" data-modal="decisions">' . esc_html__( 'شبیه‌سازی (بدون اعمال)', 'dk-bridge' ) . '</button> <button type="button" class="button button-primary" data-dkb-call="m/pricing/run" data-body="{&quot;apply&quot;:true}" data-confirm="1" data-modal="decisions">' . esc_html__( 'اجرا و اعمال قیمت‌ها', 'dk-bridge' ) . '</button> <span class="dkb-result"></span></div>';

		$res = $plugin->variants()->search(
			array(
				'linked'   => '1',
				'archived' => 0,
			),
			1,
			200
		);
		echo '<h2>' . esc_html__( 'تنوع‌های متصل', 'dk-bridge' ) . '</h2><table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'تنوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'راهبرد', 'dk-bridge' ) . '</th><th>' . esc_html__( 'قیمت شما', 'dk-bridge' ) . '</th><th>' . esc_html__( 'بای‌باکس', 'dk-bridge' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $res['rows'] as $v ) {
			$strategy = \DKBridge\Modules\RepricerService::strategy_for( $s, (int) $v['wc_product_id'] );
			echo '<tr><td>' . esc_html( (string) $v['title'] ) . '<div class="dkb-muted">DKPC-' . (int) $v['dk_variant_id'] . '</div></td><td>' . esc_html( RepricingEngine::STRATEGIES[ $strategy ] ) . '</td><td>' . esc_html( self::money( $v['price_sale'] ) ) . '</td><td>' . ( (int) $v['is_buy_box_winner'] ? '<span class="dkb-good">' . esc_html__( 'برنده', 'dk-bridge' ) . '</span>' : esc_html( self::money( $v['buy_box_price'] ) ) ) . '</td><td><button type="button" class="button button-small" data-dkb-call="m/pricing/preview/' . (int) $v['dk_variant_id'] . '" data-method="GET" data-modal="decision">' . esc_html__( 'پیشنهاد قیمت', 'dk-bridge' ) . '</button> <button type="button" class="button-link" data-dkb-call="m/pricing/history/' . (int) $v['dk_variant_id'] . '" data-method="GET" data-modal="history">' . esc_html__( 'تاریخچه', 'dk-bridge' ) . '</button></td></tr>';
		}
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'تنوع متصلی نیست؛ ابتدا کاتالوگ را همگام کنید.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		self::footer();
	}

	// -------------------------------------------------------------- Promotions.

	/**
	 * Promotions.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function promotions( Plugin $plugin ) {
		self::header( __( 'مدیریت پروموشن‌ها', 'dk-bridge' ), __( 'برای هر پروموشن، افزونه کالاهای واجد شرایط را با قیمت سودآور (حداقل تخفیف، قیمت مضرب ۱۰۰ ریال، کف سود) پیشنهاد می‌دهد. کالای غیراصل در پیشنهاد شگفت‌انگیز شرکت داده نمی‌شود. پیش از شروع، موجودی را به‌روز نگه دارید؛ هر تغییر بعد از شروع، کالا را دوباره به صف بررسی می‌برد.', 'dk-bridge' ) );
		if ( ! self::require_connection( $plugin ) ) {
			return;
		}
		try {
			$eligible = $plugin->promotions()->eligible();
			echo '<h2>' . esc_html__( 'پروموشن‌های قابل پیوستن', 'dk-bridge' ) . '</h2><table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'پروموشن', 'dk-bridge' ) . '</th><th>' . esc_html__( 'نوع', 'dk-bridge' ) . '</th><th>' . esc_html__( 'حداقل تخفیف', 'dk-bridge' ) . '</th><th>' . esc_html__( 'مهلت پیوستن', 'dk-bridge' ) . '</th><th>' . esc_html__( 'بازه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'کالاهای بالقوه', 'dk-bridge' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $eligible as $p ) {
				$deadline = isset( $p['join_deadline'] ) ? strtotime( $p['join_deadline'] ) : 0;
				$soon     = $deadline && $deadline < time() + 2 * DAY_IN_SECONDS;
				echo '<tr><td>' . esc_html( isset( $p['title'] ) ? $p['title'] : '' ) . ( ! empty( $p['has_already_joined'] ) ? ' <span class="dkb-badge dkb-state-live">' . esc_html__( 'عضو', 'dk-bridge' ) . '</span>' : '' ) . ( ! empty( $p['conditions'] ) ? '<div class="dkb-muted">' . esc_html( is_array( $p['conditions'] ) ? implode( '، ', $p['conditions'] ) : $p['conditions'] ) . '</div>' : '' ) . '</td>';
				echo '<td>' . esc_html( isset( PromotionPlanner::NATURES[ $p['nature'] ] ) ? PromotionPlanner::NATURES[ $p['nature'] ] : (string) $p['nature'] ) . '</td><td>' . esc_html( isset( $p['promotion_min_discount'] ) ? $p['promotion_min_discount'] . '٪' : '—' ) . '</td>';
				echo '<td class="' . ( $soon ? 'dkb-bad' : '' ) . '">' . esc_html( $deadline ? wp_date( 'Y/m/d H:i', $deadline ) : '—' ) . '</td><td>' . esc_html( ( isset( $p['start_at'] ) ? wp_date( 'm/d', strtotime( $p['start_at'] ) ) : '' ) . ' → ' . ( isset( $p['end_at'] ) ? wp_date( 'm/d', strtotime( $p['end_at'] ) ) : '' ) ) . '</td><td>' . (int) ( isset( $p['number_of_potential_variants'] ) ? $p['number_of_potential_variants'] : 0 ) . '</td>';
				echo '<td><button type="button" class="button button-primary button-small dkb-promo-plan" data-id="' . (int) $p['id'] . '">' . esc_html__( 'برنامهٔ سودآور', 'dk-bridge' ) . '</button></td></tr>';
			}
			if ( ! $eligible ) {
				echo '<tr><td colspan="7">' . esc_html__( 'پروموشن فعالی برای پیوستن نیست.', 'dk-bridge' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p><button type="button" class="button" data-dkb-call="m/promotions/auto" data-confirm="1">' . esc_html__( 'پیوستن خودکار سودآور (همین حالا)', 'dk-bridge' ) . '</button> <span class="dkb-result"></span></p>';

			$mine = $plugin->promotions()->mine();
			echo '<h2>' . esc_html__( 'پروموشن‌های من', 'dk-bridge' ) . '</h2><table class="widefat striped dkb-table"><thead><tr><th>' . esc_html__( 'پروموشن', 'dk-bridge' ) . '</th><th>' . esc_html__( 'وضعیت', 'dk-bridge' ) . '</th><th>' . esc_html__( 'تأییدشده / پیش‌نویس / ردشده', 'dk-bridge' ) . '</th><th>' . esc_html__( 'بازدید / فروش', 'dk-bridge' ) . '</th><th>' . esc_html__( 'بازه', 'dk-bridge' ) . '</th></tr></thead><tbody>';
			foreach ( $mine as $p ) {
				$st = isset( $p['variants_stats'] ) ? $p['variants_stats'] : array();
				echo '<tr><td>' . esc_html( isset( $p['title'] ) ? $p['title'] : '' ) . '</td><td>' . esc_html( isset( $p['status'] ) ? $p['status'] : '' ) . '</td><td>' . (int) ( isset( $st['approved'] ) ? $st['approved'] : 0 ) . ' / ' . (int) ( isset( $st['draft'] ) ? $st['draft'] : 0 ) . ' / ' . (int) ( isset( $st['rejected'] ) ? $st['rejected'] : 0 ) . '</td><td>' . (int) ( isset( $p['total_views'] ) ? $p['total_views'] : 0 ) . ' / ' . (int) ( isset( $p['total_sales'] ) ? $p['total_sales'] : 0 ) . '</td><td>' . esc_html( ( isset( $p['start_at'] ) ? substr( $p['start_at'], 0, 10 ) : '' ) . ' → ' . ( isset( $p['end_at'] ) ? substr( $p['end_at'], 0, 10 ) : '' ) ) . '</td></tr>';
			}
			if ( ! $mine ) {
				echo '<tr><td colspan="5">—</td></tr>';
			}
			echo '</tbody></table>';
		} catch ( \Throwable $e ) {
			self::error( $e );
		}

		echo '<h2>' . esc_html__( 'تخفیف هوشمند (زمان‌دار)', 'dk-bridge' ) . '</h2><div class="dkb-card"><p class="dkb-muted">' . esc_html__( 'برای تنوع‌های انتخاب‌شده در «مدیریت کالا» یا با وارد کردن DKPCها (با کاما) تخفیف زمان‌دار بسازید. تخفیف‌های زیان‌ده رد می‌شوند.', 'dk-bridge' ) . '</p><div class="dkb-row-fields"><input type="text" class="regular-text" data-field="ids" placeholder="DKPC: 123,456"> <label>' . esc_html__( 'درصد', 'dk-bridge' ) . ' <input type="number" class="small-text" data-field="discount" value="10" min="1" max="80"></label> <label>' . esc_html__( 'مدت (ساعت)', 'dk-bridge' ) . ' <input type="number" class="small-text" data-field="hours" value="72" min="1"></label> <button type="button" class="button" data-dkb-call="m/promotions/smart-discount" data-fields="row" data-modal="json">' . esc_html__( 'ساخت تخفیف هوشمند', 'dk-bridge' ) . '</button><span class="dkb-result"></span></div></div>';
		self::footer();
	}

	// --------------------------------------------------------------- Analytics.

	/**
	 * Analytics.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function analytics( Plugin $plugin ) {
		self::header( __( 'تحلیل عملکرد', 'dk-bridge' ), __( 'فروش، بازدید و نرخ تبدیل در دیجی‌کالا، مقایسهٔ کانال‌ها با ووکامرس، فروش ازدست‌رفته به‌خاطر ناموجودی و شاخص‌هایی که رتبهٔ فروشنده را تعیین می‌کنند (تأخیر، لغو، مرجوعی).', 'dk-bridge' ) );
		if ( ! self::require_connection( $plugin ) ) {
			return;
		}
		$range = isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : 'last_30_days'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$r     = $plugin->analytics()->report( $range );
		echo '<p class="dkb-range">';
		foreach ( AnalyticsService::RANGES as $k => $l ) {
			echo '<a class="button ' . ( $k === $r['range'] ? 'button-primary' : '' ) . '" href="' . esc_url( add_query_arg( 'range', $k ) ) . '">' . esc_html( $l ) . '</a> ';
		}
		echo '</p>';

		if ( $r['alerts'] ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'شاخص‌های زیر هدف:', 'dk-bridge' ) . '</strong> ' . esc_html( implode( ' ', $r['alerts'] ) ) . '</p></div>';
		}
		if ( $r['overview']['error'] ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $r['overview']['error'] ) . '</p></div>';
		}
		echo '<div class="dkb-kpis dkb-kpis-wide">';
		foreach ( $r['kpis'] as $k ) {
			$value = 'gross_sales_amount' === $k['key'] ? self::money( $k['value'] ) : number_format_i18n( (float) $k['value'], 'conversion_rate' === $k['key'] ? 2 : 0 );
			$delta = null === $k['change'] ? '' : '<em class="' . ( $k['good'] ? 'dkb-good' : 'dkb-bad' ) . '">' . ( $k['change'] >= 0 ? '▲' : '▼' ) . ' ' . esc_html( abs( round( $k['change'], 1 ) ) ) . '٪</em>';
			echo '<div class="dkb-kpi"><span>' . esc_html( $k['label'] ) . '</span><b>' . esc_html( $value ) . '</b>' . $delta . Charts::sparkline( array_values( $k['series'] ), $k['label'] ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( is_array( $r['performance']['data'] ) ) {
			$p = $r['performance']['data'];
			foreach ( AnalyticsService::PERFORMANCE_TARGETS as $key => $meta ) {
				if ( isset( $p['summary'][ $key ] ) ) {
					$ok = (float) $p['summary'][ $key ] >= $meta[1];
					echo '<div class="dkb-kpi"><span>' . esc_html( $meta[0] ) . '</span><b class="' . ( $ok ? 'dkb-good' : 'dkb-bad' ) . '">' . esc_html( round( (float) $p['summary'][ $key ], 1 ) ) . '٪</b><em class="dkb-muted">' . esc_html( sprintf( __( 'هدف %d٪', 'dk-bridge' ), $meta[1] ) ) . '</em></div>';
				}
			}
			if ( isset( $p['five_start_rate'] ) ) {
				echo '<div class="dkb-kpi"><span>' . esc_html__( 'امتیاز فروشنده', 'dk-bridge' ) . '</span><b>' . esc_html( $p['five_start_rate'] ) . ' / 5</b></div>';
			}
		}
		echo '</div>';

		$ch = $r['channels'];
		echo '<div class="dkb-card">' . Charts::lines( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			$ch['dates'],
			array(
				__( 'دیجی‌کالا', 'dk-bridge' )  => $ch['digikala'],
				__( 'ووکامرس', 'dk-bridge' ) => $ch['woocommerce'],
			),
			__( 'درآمد روزانه به تفکیک کانال (ریال)', 'dk-bridge' ),
			__( 'ریال', 'dk-bridge' )
		);
		$total = $ch['totals']['digikala'] + $ch['totals']['woocommerce'];
		if ( $total > 0 ) {
			echo '<p>' . esc_html( sprintf( __( 'سهم دیجی‌کالا از درآمد: %s٪ (%s از %s)', 'dk-bridge' ), round( 100 * $ch['totals']['digikala'] / $total, 1 ), self::money( $ch['totals']['digikala'] ), self::money( $total ) ) ) . '</p>';
		}
		echo '<p class="dkb-muted">' . esc_html__( 'درآمد دیجی‌کالا از سفارش‌های دریافت‌شده توسط افزونه محاسبه می‌شود.', 'dk-bridge' ) . '</p></div>';

		echo '<div class="dkb-grid2">';
		echo '<div class="dkb-card"><h3>' . esc_html__( 'پرفروش‌ترین کالاها', 'dk-bridge' ) . '</h3>';
		if ( is_array( $r['top']['data'] ) && $r['top']['data'] ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $r['top']['data'] as $t ) {
				echo '<tr><td>' . esc_html( $t['title'] ) . '</td><td>' . esc_html( self::money( $t['net_sales_amount'] ) ) . '</td><td>' . (int) $t['net_item_sold'] . '</td><td>' . esc_html( round( (float) $t['conversion_rate'], 1 ) ) . '٪</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="dkb-muted">' . esc_html( $r['top']['error'] ? $r['top']['error'] : '—' ) . '</p>';
		}
		echo '</div><div class="dkb-card"><h3>' . esc_html__( 'فروش ازدست‌رفته به‌خاطر ناموجودی', 'dk-bridge' ) . '</h3>';
		if ( is_array( $r['missed']['data'] ) && $r['missed']['data'] ) {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $r['missed']['data'] as $t ) {
				echo '<tr><td>' . esc_html( $t['product_title'] ) . '</td><td class="dkb-bad">' . esc_html( self::money( $t['gross_amount'] ) ) . '</td><td>' . esc_html( sprintf( __( '%d نفر منتظر', 'dk-bridge' ), (int) $t['notification_count'] ) ) . '</td></tr>';
			}
			echo '</tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-logistics' ) ) . '">' . esc_html__( 'برنامهٔ تأمین موجودی', 'dk-bridge' ) . '</a></p>';
		} else {
			echo '<p class="dkb-muted">' . esc_html( $r['missed']['error'] ? $r['missed']['error'] : __( 'موردی نیست.', 'dk-bridge' ) ) . '</p>';
		}
		echo '</div></div>';

		if ( is_array( $r['commissions']['data'] ) && $r['commissions']['data'] ) {
			echo '<details class="dkb-card"><summary><strong>' . esc_html__( 'جدول کمیسیون گروه‌های شما', 'dk-bridge' ) . '</strong></summary><table class="widefat striped"><thead><tr><th>' . esc_html__( 'گروه', 'dk-bridge' ) . '</th><th>' . esc_html__( 'برند', 'dk-bridge' ) . '</th><th>' . esc_html__( 'کمیسیون', 'dk-bridge' ) . '</th><th>' . esc_html__( 'کمیسیون اضافهٔ اقساطی', 'dk-bridge' ) . '</th></tr></thead><tbody>';
			foreach ( $r['commissions']['data'] as $c ) {
				echo '<tr><td>' . esc_html( $c['category_title'] ) . '</td><td>' . esc_html( isset( $c['brand_title'] ) ? $c['brand_title'] : '' ) . '</td><td>' . esc_html( round( 100 * (float) $c['commission'], 2 ) ) . '٪</td><td>' . esc_html( round( 100 * (float) ( isset( $c['extra_commission'] ) ? $c['extra_commission'] : 0 ), 2 ) ) . '٪</td></tr>';
			}
			echo '</tbody></table></details>';
		}
		self::footer();
	}

	// ---------------------------------------------------------------- Rulebook.

	/**
	 * Digikala rulebook summary.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function rules( Plugin $plugin ) {
		self::header( __( 'قوانین دیجی‌کالا در یک نگاه', 'dk-bridge' ), __( 'خلاصهٔ قواعدی که افزونه رعایت می‌کند، با منبع (پرسش‌های متداول رسمی دیجی‌کالا). قوانین دیجی‌کالا تغییر می‌کنند؛ پیش از تصمیم‌های مهم، منبع را دوباره ببینید.', 'dk-bridge' ) );
		$sections = Rulebook::sections();
		foreach ( $sections as $title => $rules ) {
			echo '<div class="dkb-card"><h3>' . esc_html( $title ) . '</h3><ul class="dkb-rules">';
			foreach ( $rules as $rule ) {
				echo '<li>' . esc_html( $rule[0] ) . ( $rule[1] ? ' <a class="dkb-muted" target="_blank" rel="noopener noreferrer" href="' . esc_url( $rule[1] ) . '">' . esc_html__( 'منبع', 'dk-bridge' ) . '</a>' : '' ) . ( $rule[2] ? ' <span class="dkb-badge dkb-state-live">' . esc_html( $rule[2] ) . '</span>' : '' ) . '</li>';
			}
			echo '</ul></div>';
		}
		self::footer();
	}
}
