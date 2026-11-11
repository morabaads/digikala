<?php
/**
 * Admin page renderers.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Api\TokenStore;
use DKBridge\Data\ItemRepository;
use DKBridge\Plugin;
use DKBridge\Security\Crypto;
use DKBridge\Sync\Queue;
use DKBridge\Sync\WebhookController;

defined( 'ABSPATH' ) || exit;

/**
 * Pages.
 */
class Pages {

	/**
	 * Page header.
	 *
	 * @param string $title Title.
	 * @param string $sub   Subtitle.
	 */
	private static function header( $title, $sub = '' ) {
		echo '<div class="wrap dkb-wrap" dir="rtl"><h1 class="dkb-title"><span class="dkb-logo">DK</span> ' . esc_html( $title ) . '</h1>';
		if ( $sub ) {
			echo '<p class="dkb-sub">' . esc_html( $sub ) . '</p>';
		}
	}

	/**
	 * Page footer.
	 */
	private static function footer() {
		echo '</div>';
	}

	// --------------------------------------------------------------- Dashboard.

	/**
	 * Dashboard.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function dashboard( Plugin $plugin ) {
		self::header( __( 'DK Bridge — پل ووکامرس و دیجی‌کالا', 'dk-bridge' ), __( 'انتقال، اصلاح هوشمند و همگام‌سازی محصولات با پنل فروشندگان دیجی‌کالا', 'dk-bridge' ) );
		$connected  = $plugin->is_connected();
		$counts     = $plugin->items()->counts();
		$unmapped   = $plugin->categories()->unmapped_count();
		$mapped     = count( $plugin->categories()->all() );
		$conn       = (array) get_option( 'dkb_last_connection', array() );
		$rate       = $plugin->api()->client()->rate_limit();
		$total      = array_sum( $counts );
		$last_pull  = (int) get_option( 'dkb_last_order_pull' );

		// Onboarding steps.
		$steps = array(
			array( $connected, __( 'اتصال به پنل فروشنده', 'dk-bridge' ), admin_url( 'admin.php?page=dk-bridge-settings' ) ),
			array( $mapped > 0, __( 'نگاشت دسته‌های ووکامرس به گروه‌های دیجی‌کالا', 'dk-bridge' ), admin_url( 'admin.php?page=dk-bridge-categories' ) ),
			array( (bool) $plugin->settings()->get( 'onboarded' ) || $total > 0, __( 'بررسی قوانین و قیمت‌گذاری', 'dk-bridge' ), admin_url( 'admin.php?page=dk-bridge-settings&tab=pricing' ) ),
			array( $counts['in_review'] + $counts['live'] + $counts['approved'] > 0, __( 'ارسال اولین محصول', 'dk-bridge' ), admin_url( 'admin.php?page=dk-bridge-products' ) ),
		);
		$done = count( array_filter( wp_list_pluck( $steps, 0 ) ) );
		if ( $done < count( $steps ) ) {
			echo '<div class="dkb-card dkb-onboarding"><h2>' . esc_html__( 'راه‌اندازی در ۴ قدم', 'dk-bridge' ) . ' <small>' . esc_html( sprintf( '%d/%d', $done, count( $steps ) ) ) . '</small></h2><ol class="dkb-steps">';
			foreach ( $steps as $i => $s ) {
				echo '<li class="' . ( $s[0] ? 'done' : '' ) . '"><span class="dkb-step-n">' . ( $s[0] ? '✓' : esc_html( (string) ( $i + 1 ) ) ) . '</span> <a href="' . esc_url( $s[2] ) . '">' . esc_html( $s[1] ) . '</a></li>';
			}
			echo '</ol></div>';
		}

		echo '<div class="dkb-grid">';
		// Connection card.
		echo '<div class="dkb-card"><h3>' . esc_html__( 'اتصال', 'dk-bridge' ) . '</h3>';
		echo '<p class="dkb-big ' . ( $connected ? 'dkb-good' : 'dkb-bad' ) . '">' . ( $connected ? esc_html__( 'متصل', 'dk-bridge' ) : esc_html__( 'متصل نیست', 'dk-bridge' ) ) . '</p>';
		if ( ! empty( $conn['at'] ) ) {
			echo '<p class="description">' . esc_html( sprintf( __( 'آخرین تست: %s', 'dk-bridge' ), human_time_diff( (int) $conn['at'] ) . ' ' . __( 'پیش', 'dk-bridge' ) ) ) . '</p>';
		}
		echo '<button type="button" class="button" data-dkb-action="connection-test">' . esc_html__( 'تست اتصال', 'dk-bridge' ) . '</button> <span class="dkb-result" data-dkb-result="connection-test"></span></div>';

		// Products card.
		echo '<div class="dkb-card"><h3>' . esc_html__( 'محصولات', 'dk-bridge' ) . '</h3><ul class="dkb-stats">';
		foreach ( array( 'live', 'in_review', 'approved', 'ready', 'blocked', 'rejected', 'match_review', 'error', 'queued' ) as $state ) {
			if ( ! $counts[ $state ] && ! in_array( $state, array( 'live', 'in_review', 'blocked' ), true ) ) {
				continue;
			}
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-products&dkb_state=' . $state ) ) . '"><span class="dkb-badge dkb-state-' . esc_attr( $state ) . '">' . esc_html( ItemRepository::STATES[ $state ] ) . '</span> <b>' . esc_html( number_format_i18n( $counts[ $state ] ) ) . '</b></a></li>';
		}
		echo '</ul></div>';

		// Mapping card.
		echo '<div class="dkb-card"><h3>' . esc_html__( 'نگاشت دسته‌ها', 'dk-bridge' ) . '</h3><p class="dkb-big">' . esc_html( number_format_i18n( $mapped ) ) . '</p><p>' . esc_html( sprintf( __( '%s دستهٔ دارای محصول هنوز نگاشت نشده است.', 'dk-bridge' ), number_format_i18n( $unmapped ) ) ) . '</p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-categories' ) ) . '">' . esc_html__( 'مدیریت نگاشت', 'dk-bridge' ) . '</a></div>';

		// Sync card.
		echo '<div class="dkb-card"><h3>' . esc_html__( 'همگام‌سازی', 'dk-bridge' ) . '</h3>';
		echo '<p>' . esc_html__( 'صف:', 'dk-bridge' ) . ' ' . ( Queue::has_action_scheduler() ? 'Action Scheduler' : 'WP-Cron' ) . '</p>';
		if ( $rate ) {
			echo '<p>' . esc_html( sprintf( __( 'سهمیهٔ API: %1$s از %2$s', 'dk-bridge' ), isset( $rate['current'] ) ? $rate['current'] : '?', isset( $rate['max'] ) ? $rate['max'] : '?' ) ) . '</p>';
		}
		if ( $last_pull ) {
			echo '<p>' . esc_html( sprintf( __( 'آخرین دریافت سفارش: %s پیش', 'dk-bridge' ), human_time_diff( $last_pull ) ) ) . '</p>';
		}
		echo '<div class="dkb-actions">';
		foreach ( array(
			'stock'      => __( 'ارسال موجودی', 'dk-bridge' ),
			'prices'     => __( 'ارسال قیمت', 'dk-bridge' ),
			'orders'     => __( 'دریافت سفارش‌ها', 'dk-bridge' ),
			'moderation' => __( 'بررسی وضعیت تأیید', 'dk-bridge' ),
		) as $what => $label ) {
			echo '<button type="button" class="button" data-dkb-action="sync" data-what="' . esc_attr( $what ) . '">' . esc_html( $label ) . '</button> ';
		}
		echo '<span class="dkb-result" data-dkb-result="sync"></span></div></div>';
		echo '</div>';

		// Recent errors.
		global $wpdb;
		$errors = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}dkb_logs WHERE level IN ('error','warning') ORDER BY id DESC LIMIT 8", ARRAY_A );
		echo '<div class="dkb-card"><h3>' . esc_html__( 'آخرین خطاها و هشدارها', 'dk-bridge' ) . '</h3>';
		if ( ! $errors ) {
			echo '<p class="dkb-ok">' . esc_html__( 'همه‌چیز مرتب است.', 'dk-bridge' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><tbody>';
			foreach ( $errors as $e ) {
				echo '<tr><td>' . Views::severity( $e['level'] ) . '</td><td>' . esc_html( $e['channel'] ) . '</td><td>' . ( $e['wc_product_id'] ? '<a href="' . esc_url( get_edit_post_link( (int) $e['wc_product_id'] ) ) . '">#' . (int) $e['wc_product_id'] . '</a>' : '' ) . '</td><td>' . esc_html( wp_trim_words( $e['message'], 30 ) ) . '</td><td>' . esc_html( human_time_diff( strtotime( $e['created_at'] . ' UTC' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			echo '</tbody></table>';
		}
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-logs' ) ) . '">' . esc_html__( 'همهٔ گزارش‌ها', 'dk-bridge' ) . '</a></p></div>';
		self::footer();
	}

	// ---------------------------------------------------------------- Products.

	/**
	 * Products.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function products( Plugin $plugin ) {
		self::header( __( 'محصولات و آمادگی دیجی‌کالا', 'dk-bridge' ), __( 'امتیاز آمادگی هر محصول با همان منطق امتیاز محتوای دیجی‌کالا محاسبه می‌شود. ابتدا «بررسی»، سپس «اصلاح خودکار» و در نهایت «ارسال» را بزنید.', 'dk-bridge' ) );
		$table = new ProductsTable( $plugin );
		$table->prepare_items();
		echo '<form method="get" id="dkb-products-form"><input type="hidden" name="page" value="dk-bridge-products">';
		$table->views();
		$table->search_box( __( 'جستجو', 'dk-bridge' ), 'dkb-search' );
		$table->display();
		echo '</form><div id="dkb-modal" class="dkb-modal" hidden><div class="dkb-modal-box"><button type="button" class="dkb-modal-close" aria-label="close">×</button><div class="dkb-modal-body"></div></div></div>';
		self::footer();
	}

	// -------------------------------------------------------------- Categories.

	/**
	 * Category mapping.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function categories( Plugin $plugin ) {
		self::header( __( 'نگاشت دسته‌ها', 'dk-bridge' ), __( 'هر دستهٔ ووکامرس را به یک گروه «برگ» دیجی‌کالا وصل کنید. محصولات زیرمجموعه هم از نگاشت والد استفاده می‌کنند. دکمهٔ «پیشنهاد» بر اساس نام محصولات دسته، گروه مناسب را از دیجی‌کالا پیشنهاد می‌دهد.', 'dk-bridge' ) );
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			echo '<p>' . esc_html__( 'دسته‌ای پیدا نشد.', 'dk-bridge' ) . '</p>';
			self::footer();
			return;
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$maps = $plugin->categories()->all();
		echo '<table class="widefat dkb-catmap"><thead><tr><th>' . esc_html__( 'دستهٔ ووکامرس', 'dk-bridge' ) . '</th><th>' . esc_html__( 'محصولات', 'dk-bridge' ) . '</th><th>' . esc_html__( 'گروه دیجی‌کالا', 'dk-bridge' ) . '</th><th></th></tr></thead><tbody>';
		$render = function ( $parent, $depth ) use ( &$render, $by_parent, $maps ) {
			if ( empty( $by_parent[ $parent ] ) ) {
				return;
			}
			foreach ( $by_parent[ $parent ] as $t ) {
				$map = isset( $maps[ (int) $t->term_id ] ) ? $maps[ (int) $t->term_id ] : null;
				echo '<tr data-term="' . (int) $t->term_id . '"><td style="padding-right:' . (int) ( 10 + 22 * $depth ) . 'px">' . ( $depth ? '<span class="dkb-tree">└</span> ' : '' ) . esc_html( $t->name ) . '</td><td>' . (int) $t->count . '</td><td class="dkb-cat-cell">';
				echo '<div class="dkb-cat-current">' . ( $map ? '<span class="dkb-chip">' . esc_html( $map['dk_category_title'] ) . ' <code>#' . (int) $map['dk_category_id'] . '</code>' . ( $map['theme'] ? ' <em>' . esc_html( self::theme_label( $map['theme'] ) ) . '</em>' : '' ) . '</span>' : '<span class="dkb-muted">' . esc_html__( 'نگاشت نشده', 'dk-bridge' ) . '</span>' ) . '</div>';
				echo '<div class="dkb-autocomplete"><input type="search" class="regular-text dkb-cat-search" placeholder="' . esc_attr__( 'نام یا شناسهٔ گروه دیجی‌کالا…', 'dk-bridge' ) . '"><ul class="dkb-ac-list" hidden></ul></div>';
				echo '</td><td class="dkb-nowrap"><button type="button" class="button dkb-cat-suggest">' . esc_html__( 'پیشنهاد', 'dk-bridge' ) . '</button> ';
				if ( $map ) {
					echo '<button type="button" class="button-link-delete dkb-cat-delete">' . esc_html__( 'حذف', 'dk-bridge' ) . '</button>';
				}
				echo '<div class="dkb-result"></div></td></tr>';
				$render( (int) $t->term_id, $depth + 1 );
			}
		};
		$render( 0, 0 );
		echo '</tbody></table>';
		self::footer();
	}

	/**
	 * Theme label.
	 *
	 * @param string $theme Theme.
	 * @return string
	 */
	public static function theme_label( $theme ) {
		$map = array(
			'colored'          => __( 'تنوع رنگی', 'dk-bridge' ),
			'sized'            => __( 'تنوع سایزی', 'dk-bridge' ),
			'no_color_no_size' => __( 'بدون رنگ و سایز', 'dk-bridge' ),
		);
		return isset( $map[ $theme ] ) ? $map[ $theme ] : $theme;
	}

	// -------------------------------------------------------------- Attributes.

	/**
	 * Attribute mapping.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function attributes( Plugin $plugin ) {
		self::header( __( 'نگاشت ویژگی‌ها', 'dk-bridge' ), __( 'برای هر گروه دیجی‌کالا مشخص کنید مقدار هر ویژگی از کجای ووکامرس خوانده شود. ویژگی‌هایی که نامشان شبیه ویژگی‌های ووکامرس است خودکار تطبیق داده می‌شوند؛ این صفحه برای موارد خاص است.', 'dk-bridge' ) );
		$cats = array();
		foreach ( $plugin->categories()->all() as $m ) {
			$cats[ (int) $m['dk_category_id'] ] = $m['dk_category_title'];
		}
		if ( ! $cats ) {
			echo '<p>' . wp_kses_post( sprintf( __( 'ابتدا <a href="%s">دسته‌ها را نگاشت کنید</a>.', 'dk-bridge' ), esc_url( admin_url( 'admin.php?page=dk-bridge-categories' ) ) ) ) . '</p>';
			self::footer();
			return;
		}
		echo '<p><label>' . esc_html__( 'گروه دیجی‌کالا:', 'dk-bridge' ) . ' <select id="dkb-attr-category"><option value="">—</option>';
		foreach ( $cats as $id => $title ) {
			echo '<option value="' . (int) $id . '">' . esc_html( $title . ' (#' . $id . ')' ) . '</option>';
		}
		echo '</select></label> <span class="dkb-result" id="dkb-attr-status"></span></p>';
		echo '<div id="dkb-attr-table"></div>';
		echo '<p><button type="button" class="button button-primary" id="dkb-attr-save" hidden>' . esc_html__( 'ذخیرهٔ نگاشت ویژگی‌ها', 'dk-bridge' ) . '</button></p>';
		self::footer();
	}

	// ---------------------------------------------------------------- Settings.

	/**
	 * Settings.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function settings( Plugin $plugin ) {
		$s    = $plugin->settings();
		$tabs = array(
			'connection' => __( 'اتصال', 'dk-bridge' ),
			'pricing'    => __( 'قیمت‌گذاری', 'dk-bridge' ),
			'inventory'  => __( 'موجودی و سفارش', 'dk-bridge' ),
			'fulfilment' => __( 'ارسال و گارانتی', 'dk-bridge' ),
			'content'    => __( 'قوانین محتوا', 'dk-bridge' ),
			'images'     => __( 'تصاویر', 'dk-bridge' ),
			'smart'      => __( 'ماژول‌های هوشمند', 'dk-bridge' ),
			'webhook'    => __( 'وب‌هوک', 'dk-bridge' ),
			'advanced'   => __( 'پیشرفته', 'dk-bridge' ),
		);
		$tab  = isset( $_GET['tab'] ) && isset( $tabs[ sanitize_key( wp_unslash( $_GET['tab'] ) ) ] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		self::header( __( 'تنظیمات', 'dk-bridge' ) );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			echo '<a class="nav-tab ' . ( $key === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=dk-bridge-settings&tab=' . $key ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';

		$booleans = array();
		$rows     = '';
		$f        = function ( $key, $label, $type, $args = array() ) use ( $s, &$booleans, &$rows ) {
			if ( 'checkbox' === $type ) {
				$booleans[] = $key;
			}
			$rows .= Views::field( $key, $label, $type, $s->get( $key ), $args );
		};

		switch ( $tab ) {
			case 'connection':
				$tokens = new TokenStore();
				$f(
					'auth_mode',
					__( 'روش اتصال', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'self_token' => __( 'توکن شخصی از پنل فروشنده (پیشنهادی)', 'dk-bridge' ),
							'oauth'      => __( 'اپلیکیشن ثبت‌شده (Authorization Code)', 'dk-bridge' ),
						),
					)
				);
				$f( 'seller_code', __( 'کد فروشنده', 'dk-bridge' ), 'text', array( 'help' => __( 'برای توکن شخصی لازم است (در هدر User-Agent ارسال می‌شود). کد فروشنده را در پروفایل پنل فروشندگان ببینید.', 'dk-bridge' ) ) );
				$rows .= '<tr><th><label for="dkb_access_token">' . esc_html__( 'Access Token', 'dk-bridge' ) . '</label></th><td><input type="password" autocomplete="off" id="dkb_access_token" name="dkb_access_token" class="large-text" placeholder="' . esc_attr( $tokens->has_token() ? Crypto::mask( $tokens->access_token() ) : '' ) . '"><p class="description">' . esc_html__( 'توکن به‌صورت رمزنگاری‌شده ذخیره می‌شود. برای نگه داشتن توکن فعلی خالی بگذارید.', 'dk-bridge' ) . '</p></td></tr>';
				$rows .= '<tr><th><label for="dkb_refresh_token">' . esc_html__( 'Refresh Token', 'dk-bridge' ) . '</label></th><td><input type="password" autocomplete="off" id="dkb_refresh_token" name="dkb_refresh_token" class="large-text"><p class="description">' . esc_html__( 'اختیاری؛ برای تمدید خودکار توکن.', 'dk-bridge' ) . '</p></td></tr>';
				$rows .= '<tr><th><label for="dkb_authorization_code">' . esc_html__( 'Authorization Code', 'dk-bridge' ) . '</label></th><td><input type="text" autocomplete="off" id="dkb_authorization_code" name="dkb_authorization_code" class="large-text"><p class="description">' . esc_html__( 'فقط در حالت اپلیکیشن ثبت‌شده: کد دریافتی پس از تأیید فروشنده.', 'dk-bridge' ) . '</p></td></tr>';
				if ( $tokens->expires_at() ) {
					$rows .= '<tr><th>' . esc_html__( 'انقضای توکن', 'dk-bridge' ) . '</th><td>' . esc_html( wp_date( 'Y/m/d H:i', $tokens->expires_at() ) ) . '</td></tr>';
				}
				$f( 'dry_run', __( 'اجرای آزمایشی', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'هیچ درخواست تغییردهنده‌ای ارسال نشود (فقط خواندن و پیش‌نمایش).', 'dk-bridge' ) ) );
				break;

			case 'pricing':
				$f(
					'currency',
					__( 'واحد قیمت ووکامرس', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'auto'  => sprintf( __( 'خودکار (%s)', 'dk-bridge' ), function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '' ),
							'toman' => __( 'تومان', 'dk-bridge' ),
							'rial'  => __( 'ریال', 'dk-bridge' ),
						),
						'help'    => __( 'قیمت‌ها در دیجی‌کالا ریالی‌اند؛ تبدیل تومان به ریال خودکار انجام می‌شود.', 'dk-bridge' ),
					)
				);
				$f(
					'price_source',
					__( 'مبنای قیمت', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'active'  => __( 'قیمت فعلی (با احتساب حراج)', 'dk-bridge' ),
							'regular' => __( 'قیمت عادی', 'dk-bridge' ),
						),
					)
				);
				$f( 'markup_percent', __( 'افزایش درصدی', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '0.1' ), 'help' => __( 'مثلاً ۸ یعنی قیمت دیجی‌کالا ۸٪ بیشتر از سایت.', 'dk-bridge' ) ) );
				$f( 'markup_fixed', __( 'افزایش ثابت (ریال)', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '1000' ) ) );
				$f( 'commission_compensation', __( 'جبران کمیسیون', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'قیمت طوری محاسبه شود که بعد از کسر کمیسیون دیجی‌کالا، همان مبلغ به شما برسد.', 'dk-bridge' ) ) );
				$f( 'default_commission', __( 'کمیسیون پیش‌فرض (٪)', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '0.1' ), 'help' => __( 'تا زمانی که کمیسیون واقعی کالا از دیجی‌کالا دریافت نشده، از این مقدار استفاده می‌شود.', 'dk-bridge' ) ) );
				$f( 'min_profit_percent', __( 'حداقل سود خالص (٪)', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '0.1' ), 'help' => __( 'اگر قیمت بعد از کمیسیون کمتر از این سود باشد، هشدار داده می‌شود.', 'dk-bridge' ) ) );
				$f( 'rounding_step', __( 'گام رُند کردن (ریال)', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '1000' ) ) );
				$f(
					'rounding_mode',
					__( 'روش رُند کردن', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'nearest'       => __( 'نزدیک‌ترین', 'dk-bridge' ),
							'up'            => __( 'رو به بالا', 'dk-bridge' ),
							'down'          => __( 'رو به پایین', 'dk-bridge' ),
							'psychological' => __( 'روانی (۱۲٬۳۹۰٬۰۰۰ به‌جای ۱۲٬۴۰۰٬۰۰۰)', 'dk-bridge' ),
						),
					)
				);
				$f( 'clamp_to_boundary', __( 'بازهٔ مجاز قیمت', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'اگر قیمت خارج از بازهٔ مجاز دیجی‌کالا بود، به نزدیک‌ترین مقدار مجاز تنظیم شود.', 'dk-bridge' ) ) );
				break;

			case 'inventory':
				$f( 'sync_stock', __( 'همگام‌سازی موجودی', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'تغییر موجودی ووکامرس به دیجی‌کالا ارسال شود (هر ۵ دقیقه، با Batch API).', 'dk-bridge' ) ) );
				$f( 'sync_price', __( 'همگام‌سازی قیمت', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'تغییر قیمت ووکامرس به دیجی‌کالا ارسال شود.', 'dk-bridge' ) ) );
				$f( 'stock_buffer', __( 'بافر اطمینان موجودی', 'dk-bridge' ), 'number', array( 'help' => __( 'این تعداد از موجودی واقعی کم و بعد ارسال می‌شود تا فروش هم‌زمان در دو کانال باعث فروش بیش از موجودی نشود.', 'dk-bridge' ) ) );
				$f( 'stock_max', __( 'سقف موجودی اعلامی', 'dk-bridge' ), 'number', array( 'help' => __( '۰ یعنی بدون سقف.', 'dk-bridge' ) ) );
				$f( 'unmanaged_stock', __( 'موجودی محصولات بدون مدیریت انبار', 'dk-bridge' ), 'number' );
				$f( 'max_per_order', __( 'حداکثر تعداد در هر سفارش', 'dk-bridge' ), 'number' );
				$f( 'pull_orders', __( 'دریافت سفارش‌ها', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'سفارش‌های دیجی‌کالا هر ۵ دقیقه دریافت شوند.', 'dk-bridge' ) ) );
				$f( 'reduce_wc_stock', __( 'کسر موجودی ووکامرس', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'با هر سفارش دیجی‌کالا، موجودی ووکامرس کم شود (و با لغو یا مرجوعی برگردد).', 'dk-bridge' ) ) );
				break;

			case 'fulfilment':
				$f(
					'shipping_type',
					__( 'روش ارسال', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'digikala' => __( 'ارسال توسط دیجی‌کالا (انبار دیجی‌کالا)', 'dk-bridge' ),
							'seller'   => __( 'ارسال توسط فروشنده', 'dk-bridge' ),
						),
					)
				);
				$f( 'lead_time', __( 'زمان آماده‌سازی (روز)', 'dk-bridge' ), 'number' );
				$f( 'seller_lead_time', __( 'زمان ارسال فروشنده (ساعت)', 'dk-bridge' ), 'number' );
				$f( 'warranty_id', __( 'شناسهٔ گارانتی پیش‌فرض', 'dk-bridge' ), 'number', array( 'help' => __( '۰ = انتخاب خودکار «گارانتی اصالت و سلامت فیزیکی کالا» از گزینه‌های مجاز هر کالا.', 'dk-bridge' ) ) );
				$f(
					'site',
					__( 'کانال فروش', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'digikala'  => __( 'دیجی‌کالا', 'dk-bridge' ),
							'digistyle' => __( 'دیجی‌استایل', 'dk-bridge' ),
						),
					)
				);
				break;

			case 'content':
				$f( 'auto_fix_on_push', __( 'اصلاح خودکار هنگام ارسال', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'قبل از ارسال، ایرادهای قابل اصلاح خودکار رفع شوند (محتوای سایت تغییر نمی‌کند).', 'dk-bridge' ) ) );
				$f( 'min_score', __( 'حداقل امتیاز آمادگی برای ارسال', 'dk-bridge' ), 'number', array( 'attrs' => array( 'min' => 0, 'max' => 100 ) ) );
				$f( 'title_min', __( 'حداقل طول عنوان', 'dk-bridge' ), 'number' );
				$f( 'title_max', __( 'حداکثر طول عنوان', 'dk-bridge' ), 'number' );
				$f( 'desc_min', __( 'حداقل طول توضیحات', 'dk-bridge' ), 'number' );
				$f( 'extra_forbidden_words', __( 'کلمات ممنوع اضافه', 'dk-bridge' ), 'textarea', array( 'help' => __( 'هر خط یک کلمه یا عبارت. علاوه بر فهرست داخلی (ارزان، تخفیف، اورجینال، بهترین، ارسال رایگان، …) از عنوان حذف می‌شوند. نام فروشگاه خود را هم اینجا بنویسید.', 'dk-bridge' ) ) );
				$f( 'default_disadvantage', __( 'نقطهٔ ضعف پیش‌فرض', 'dk-bridge' ), 'text', array( 'help' => __( 'اگر محصول نقطهٔ ضعف ثبت‌شده نداشت، این متن استفاده شود (اختیاری).', 'dk-bridge' ) ) );
				$f( 'brand_source', __( 'منبع برند', 'dk-bridge' ), 'text', array( 'help' => __( '<code>auto</code> (تشخیص خودکار از افزونه‌های برند و ویژگی «برند»)، یا <code>taxonomy:product_brand</code>، <code>attribute:pa_brand</code>، <code>meta:_brand</code>', 'dk-bridge' ) ) );
				$f( 'model_source', __( 'منبع مدل', 'dk-bridge' ), 'text', array( 'help' => __( '<code>sku</code> (ویژگی «مدل» یا SKU)، یا <code>attribute:pa_model</code>، <code>meta:_model</code>', 'dk-bridge' ) ) );
				$f( 'default_is_iranian', __( 'تولید ایران', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'محصولات به‌طور پیش‌فرض تولید داخل هستند.', 'dk-bridge' ) ) );
				$f( 'smart_match', __( 'تشخیص کالای تکراری', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'قبل از ساخت کالای جدید، کالای مشابه در دیجی‌کالا جستجو شود.', 'dk-bridge' ) ) );
				$f( 'match_threshold', __( 'آستانهٔ شباهت', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '0.01', 'min' => '0.5', 'max' => '1' ) ) );
				$f( 'auto_use_matches', __( 'استفادهٔ خودکار از کالای مشابه', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'اگر کالای یکسان پیدا شد، بدون سؤال روی همان کالا تنوع ساخته شود.', 'dk-bridge' ) ) );
				$f(
					'dimension_unit_override',
					__( 'واحد ابعاد', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							''   => sprintf( __( 'تنظیمات ووکامرس (%s)', 'dk-bridge' ), get_option( 'woocommerce_dimension_unit', 'cm' ) ),
							'cm' => 'cm',
							'mm' => 'mm',
							'm'  => 'm',
						),
					)
				);
				break;

			case 'images':
				$f( 'img_min', __( 'حداقل ابعاد (پیکسل)', 'dk-bridge' ), 'number' );
				$f( 'img_max', __( 'حداکثر ابعاد (پیکسل)', 'dk-bridge' ), 'number' );
				$f( 'img_fill', __( 'نسبت پرشدن قاب', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '0.01', 'min' => '0.5', 'max' => '1' ), 'help' => __( 'کالا چه سهمی از قاب مربعی را پر کند (پیش‌فرض ۰٫۸۸).', 'dk-bridge' ) ) );
				$f( 'img_quality', __( 'کیفیت JPG', 'dk-bridge' ), 'number' );
				$f( 'img_max_bytes', __( 'حداکثر حجم (بایت)', 'dk-bridge' ), 'number' );
				$f( 'img_max_count', __( 'حداکثر تعداد تصاویر', 'dk-bridge' ), 'number' );
				$f( 'use_ai_check', __( 'بررسی هوشمند دیجی‌کالا', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'هر تصویر پس از آپلود با هوش مصنوعی دیجی‌کالا بررسی و توصیه‌ها ذخیره شوند.', 'dk-bridge' ) ) );
				break;

			case 'smart':
				$rows .= '<tr><th colspan="2"><h3>' . esc_html__( 'مدیریت کالا', 'dk-bridge' ) . '</h3></th></tr>';
				$f( 'catalog_sync', __( 'همگام‌سازی کاتالوگ', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'هر ساعت همهٔ تنوع‌های دیجی‌کالا خوانده شوند.', 'dk-bridge' ) ) );
				$f( 'catalog_auto_link', __( 'اتصال خودکار', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'تنوع‌ها با SKU یا کد تأمین‌کننده به محصولات ووکامرس وصل شوند.', 'dk-bridge' ) ) );
				$f( 'deactivate_unpublished', __( 'هماهنگی وضعیت', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'اگر محصول در ووکامرس پیش‌نویس یا حذف شد، تنوع دیجی‌کالا غیرفعال و با انتشار دوباره فعال شود.', 'dk-bridge' ) ) );
				$rows .= '<tr><th colspan="2"><h3>' . esc_html__( 'مدیریت موجودی', 'dk-bridge' ) . '</h3></th></tr>';
				$f( 'velocity_days', __( 'بازهٔ محاسبهٔ سرعت فروش (روز)', 'dk-bridge' ), 'number' );
				$f( 'low_stock_days', __( 'آستانهٔ «رو به اتمام» (روز پوشش)', 'dk-bridge' ), 'number' );
				$f( 'alert_email', __( 'ایمیل هشدار موجودی', 'dk-bridge' ), 'email' );
				$f( 'auto_deactivate_oos', __( 'غیرفعال‌سازی ناموجودها', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'تنوع فعالی که در سایت موجودی ندارد خودکار غیرفعال شود.', 'dk-bridge' ) ) );
				$rows .= '<tr><th colspan="2"><h3>' . esc_html__( 'لجستیک هوشمند', 'dk-bridge' ) . '</h3></th></tr>';
				$f( 'cover_days', __( 'پوشش هدف انبار (روز)', 'dk-bridge' ), 'number' );
				$f( 'safety_days', __( 'ذخیرهٔ اطمینان (روز)', 'dk-bridge' ), 'number' );
				$f( 'inbound_days', __( 'زمان رسیدن محموله به انبار (روز)', 'dk-bridge' ), 'number' );
				$f( 'replenish_min', __( 'حداقل تعداد ارسال', 'dk-bridge' ), 'number' );
				$rows .= '<tr><th colspan="2"><h3>' . esc_html__( 'قیمت‌گذاری هوشمند', 'dk-bridge' ) . '</h3></th></tr>';
				$f( 'repricer_enabled', __( 'فعال‌سازی', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'هر ۳۰ دقیقه قیمت تنوع‌های متصل بازبینی شود.', 'dk-bridge' ) ) );
				$f( 'repricer_strategy', __( 'راهبرد پیش‌فرض', 'dk-bridge' ), 'select', array( 'options' => \DKBridge\Intelligence\RepricingEngine::STRATEGIES ) );
				$f( 'repricer_rank', __( 'رتبهٔ هدف (راهبرد رتبه)', 'dk-bridge' ), 'number' );
				$f( 'repricer_step', __( 'گام ارزان‌تر شدن (ریال)', 'dk-bridge' ), 'number' );
				$f( 'repricer_min_change', __( 'حداقل تغییر قابل اعمال (ریال)', 'dk-bridge' ), 'number' );
				$f( 'repricer_max_markup', __( 'سقف قیمت (٪ بالاتر از قیمت پایه)', 'dk-bridge' ), 'number' );
				$f( 'repricer_max_weekly_change', __( 'حداکثر نوسان ۷ روزه (٪)', 'dk-bridge' ), 'number', array( 'attrs' => array( 'step' => '0.5' ), 'help' => __( 'نوسان زیاد قیمت مانع ورود به پیشنهاد شگفت‌انگیز می‌شود و افزایش قیمت پیش از تخفیف «تخفیف کاذب» است.', 'dk-bridge' ) ) );
				$f( 'repricer_raise_when_alone', __( 'افزایش قیمت بدون رقیب', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'اگر رقیبی نیست، قیمت تا سقف بالا برود.', 'dk-bridge' ) ) );
				$rows .= '<tr><th colspan="2"><h3>' . esc_html__( 'پروموشن‌ها', 'dk-bridge' ) . '</h3></th></tr>';
				$f( 'promo_auto_join', __( 'پیوستن خودکار', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'هر ۶ ساعت به پروموشن‌های جدید، فقط با کالاهای متصل و سودآور، بپیوندد.', 'dk-bridge' ) ) );
				$f( 'promo_max_discount', __( 'حداکثر تخفیف مجاز (٪)', 'dk-bridge' ), 'number' );
				$f( 'promo_stock_share', __( 'سهم موجودی در پروموشن (٪)', 'dk-bridge' ), 'number' );
				$f( 'promo_order_limit', __( 'حداکثر تعداد در سبد', 'dk-bridge' ), 'number' );
				$natures = (array) $s->get( 'promo_natures' );
				$boxes   = '';
				foreach ( \DKBridge\Intelligence\PromotionPlanner::NATURES as $key => $label ) {
					$boxes .= '<label class="dkb-inline"><input type="checkbox" name="dkb[promo_natures][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $natures, true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
				}
				$rows .= '<tr><th>' . esc_html__( 'انواع پروموشن', 'dk-bridge' ) . '</th><td>' . $boxes . '</td></tr>';
				break;

			case 'webhook':
				$rows .= '<tr><th>' . esc_html__( 'آدرس وب‌هوک', 'dk-bridge' ) . '</th><td><code class="dkb-copy">' . esc_html( WebhookController::url( $s ) ) . '</code><p class="description">' . esc_html__( 'این آدرس را برای اپلیکیشن خود در دیجی‌کالا ثبت کنید. حتی بدون وب‌هوک، وضعیت کالاها و سفارش‌ها به‌صورت دوره‌ای بررسی می‌شوند.', 'dk-bridge' ) . '</p></td></tr>';
				$events = array(
					'product_moderation'            => __( 'تأیید/رد محتوای کالا', 'dk-bridge' ),
					'product_variant_status_change' => __( 'تغییر وضعیت تنوع', 'dk-bridge' ),
					'order_processed'               => __( 'سفارش جدید', 'dk-bridge' ),
					'order_item_cancellation'       => __( 'لغو سفارش', 'dk-bridge' ),
					'order_return'                  => __( 'مرجوعی', 'dk-bridge' ),
					'order_shipment'                => __( 'ارسال سفارش', 'dk-bridge' ),
					'commission_change'             => __( 'تغییر کمیسیون', 'dk-bridge' ),
				);
				$current = (array) $s->get( 'webhook_events' );
				$boxes   = '';
				foreach ( $events as $key => $label ) {
					$boxes .= '<label class="dkb-inline"><input type="checkbox" name="dkb[webhook_events][]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $current, true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
				}
				$rows .= '<tr><th>' . esc_html__( 'رویدادها', 'dk-bridge' ) . '</th><td>' . $boxes . '<p><button type="button" class="button" data-dkb-action="webhook-subscribe">' . esc_html__( 'ثبت رویدادها در دیجی‌کالا', 'dk-bridge' ) . '</button> <span class="dkb-result" data-dkb-result="webhook-subscribe"></span></p></td></tr>';
				break;

			case 'advanced':
				$f( 'base_url', __( 'آدرس API', 'dk-bridge' ), 'text', array( 'help' => __( 'فقط دامنه‌های digikala.com یا localhost (برای Sandbox رسمی دیجی‌کالا) پذیرفته می‌شوند.', 'dk-bridge' ) ) );
				$f( 'rate_limit', __( 'سقف درخواست در هر پنجره', 'dk-bridge' ), 'number' );
				$f( 'rate_window', __( 'طول پنجره (ثانیه)', 'dk-bridge' ), 'number' );
				$f( 'timeout', __( 'Timeout درخواست (ثانیه)', 'dk-bridge' ), 'number' );
				$f( 'notify_email', __( 'ایمیل اعلان', 'dk-bridge' ), 'email', array( 'help' => __( 'تأیید یا رد کالاها به این ایمیل خبر داده شود.', 'dk-bridge' ) ) );
				$f(
					'log_level',
					__( 'سطح گزارش', 'dk-bridge' ),
					'select',
					array(
						'options' => array(
							'debug'   => 'debug',
							'info'    => 'info',
							'warning' => 'warning',
							'error'   => 'error',
						),
					)
				);
				$f( 'log_retention_days', __( 'نگهداری گزارش (روز)', 'dk-bridge' ), 'number' );
				$f( 'delete_data_on_uninstall', __( 'حذف داده‌ها', 'dk-bridge' ), 'checkbox', array( 'label' => __( 'با حذف افزونه، همهٔ جداول و تنظیمات پاک شوند.', 'dk-bridge' ) ) );
				$rows .= '<tr><th>' . esc_html__( 'کش', 'dk-bridge' ) . '</th><td><button type="button" class="button" data-dkb-action="cache-flush">' . esc_html__( 'پاک کردن کش دیجی‌کالا', 'dk-bridge' ) . '</button> <span class="dkb-result" data-dkb-result="cache-flush"></span></td></tr>';
				break;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dkb-settings">';
		wp_nonce_field( 'dkb_settings' );
		echo '<input type="hidden" name="action" value="dkb_save_settings"><input type="hidden" name="dkb_tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="dkb_booleans" value="' . esc_attr( implode( ',', $booleans ) ) . '">';
		if ( 'pricing' === $tab ) {
			echo '<input type="hidden" name="dkb[onboarded]" value="1">';
		}
		echo '<table class="form-table" role="presentation">' . $rows . '</table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped Views::field().
		submit_button( __( 'ذخیره', 'dk-bridge' ) );
		echo '</form>';

		if ( 'connection' === $tab && $plugin->is_connected() ) {
			echo '<div class="dkb-card"><button type="button" class="button" data-dkb-action="connection-test">' . esc_html__( 'تست اتصال', 'dk-bridge' ) . '</button> <span class="dkb-result" data-dkb-result="connection-test"></span>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
			wp_nonce_field( 'dkb_disconnect' );
			echo '<input type="hidden" name="action" value="dkb_disconnect"><button class="button button-link-delete" onclick="return confirm(\'' . esc_js( __( 'توکن‌ها حذف شوند؟', 'dk-bridge' ) ) . '\')">' . esc_html__( 'قطع اتصال', 'dk-bridge' ) . '</button></form></div>';
			echo '<div class="dkb-card dkb-help"><h3>' . esc_html__( 'نکته', 'dk-bridge' ) . '</h3><p>' . esc_html__( 'Open API دیجی‌کالا فقط از سرورهایی پاسخ می‌دهد که به شبکهٔ دیجی‌کالا دسترسی دارند (معمولاً IP ایران). اگر تست اتصال با خطای شبکه مواجه شد، میزبانی سایت را بررسی کنید.', 'dk-bridge' ) . '</p></div>';
		}
		self::footer();
	}

	// -------------------------------------------------------------------- Logs.

	/**
	 * Logs.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public static function logs( Plugin $plugin ) {
		global $wpdb;
		self::header( __( 'گزارش‌ها', 'dk-bridge' ) );
		$level   = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$channel = isset( $_GET['channel'] ) ? sanitize_key( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$product = isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$where   = array( '1=1' );
		$params  = array();
		if ( $level ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}
		if ( $channel ) {
			$where[]  = 'channel = %s';
			$params[] = $channel;
		}
		if ( $product ) {
			$where[]  = 'wc_product_id = %d';
			$params[] = $product;
		}
		$per    = 50;
		$sql    = "SELECT * FROM {$wpdb->prefix}dkb_logs WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params = array_merge( $params, array( $per, ( $paged - 1 ) * $per ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$chans  = $wpdb->get_col( "SELECT DISTINCT channel FROM {$wpdb->prefix}dkb_logs" );

		echo '<form method="get" class="dkb-filters"><input type="hidden" name="page" value="dk-bridge-logs"><select name="level"><option value="">' . esc_html__( 'همهٔ سطوح', 'dk-bridge' ) . '</option>';
		foreach ( array( 'error', 'warning', 'info', 'debug' ) as $l ) {
			echo '<option value="' . esc_attr( $l ) . '" ' . selected( $level, $l, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <select name="channel"><option value="">' . esc_html__( 'همهٔ بخش‌ها', 'dk-bridge' ) . '</option>';
		foreach ( (array) $chans as $c ) {
			echo '<option value="' . esc_attr( $c ) . '" ' . selected( $channel, $c, false ) . '>' . esc_html( $c ) . '</option>';
		}
		echo '</select> <input type="number" name="product" placeholder="' . esc_attr__( 'شناسهٔ محصول', 'dk-bridge' ) . '" value="' . ( $product ? (int) $product : '' ) . '" class="small-text"> <button class="button">' . esc_html__( 'فیلتر', 'dk-bridge' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dkb_export_logs' ), 'dkb_export_logs' ) ) . '">' . esc_html__( 'خروجی CSV', 'dk-bridge' ) . '</a></form>';

		echo '<table class="widefat striped dkb-logs"><thead><tr><th>' . esc_html__( 'زمان', 'dk-bridge' ) . '</th><th>' . esc_html__( 'سطح', 'dk-bridge' ) . '</th><th>' . esc_html__( 'بخش', 'dk-bridge' ) . '</th><th>' . esc_html__( 'محصول', 'dk-bridge' ) . '</th><th>' . esc_html__( 'پیام', 'dk-bridge' ) . '</th></tr></thead><tbody>';
		foreach ( (array) $rows as $r ) {
			$ctx = $r['context'] ? '<details><summary>' . esc_html__( 'جزئیات', 'dk-bridge' ) . '</summary><pre dir="ltr">' . esc_html( wp_json_encode( json_decode( $r['context'], true ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>' : '';
			echo '<tr><td class="dkb-nowrap">' . esc_html( get_date_from_gmt( $r['created_at'], 'Y/m/d H:i:s' ) ) . '</td><td>' . Views::severity( $r['level'] ) . '</td><td>' . esc_html( $r['channel'] ) . '</td><td>' . ( $r['wc_product_id'] ? '<a href="' . esc_url( get_edit_post_link( (int) $r['wc_product_id'] ) ) . '">#' . (int) $r['wc_product_id'] . '</a>' : '' ) . '</td><td>' . esc_html( $r['message'] ) . $ctx . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'گزارشی نیست.', 'dk-bridge' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$base = remove_query_arg( 'paged' );
		echo '<p class="dkb-pager">';
		if ( $paged > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">' . esc_html__( 'قبلی', 'dk-bridge' ) . '</a> ';
		}
		if ( count( (array) $rows ) === $per ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">' . esc_html__( 'بعدی', 'dk-bridge' ) . '</a>';
		}
		echo '</p>';
		self::footer();
	}
}
