<?php
/**
 * Admin bootstrap: menus, assets, settings form, product list integration.
 *
 * @package DKBridge
 */

namespace DKBridge\Admin;

use DKBridge\Api\TokenStore;
use DKBridge\Plugin;
use DKBridge\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin.
 */
class Admin {

	const SLUG = 'dk-bridge';

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
	 * Hooks.
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
		add_action( 'admin_post_dkb_save_settings', array( $this, 'save_settings' ) );
		add_action( 'admin_post_dkb_disconnect', array( $this, 'disconnect' ) );
		add_action( 'admin_post_dkb_keys', array( $this, 'keys' ) );
		add_action( 'admin_post_dkb_export_logs', array( $this, 'export_logs' ) );
		add_filter( 'plugin_action_links_' . DKB_BASENAME, array( $this, 'action_links' ) );

		( new MetaBox( $this->plugin ) )->register();

		// WooCommerce products list integration.
		add_filter( 'manage_edit-product_columns', array( $this, 'product_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( $this, 'product_column_value' ), 10, 2 );
		add_filter( 'bulk_actions-edit-product', array( $this, 'product_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( $this, 'handle_product_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Menus.
	 */
	public function menu() {
		$cap  = 'manage_woocommerce';
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="#a7aaad" d="M3 4h6a6 6 0 0 1 0 12H3V4zm3 3v6h3a3 3 0 0 0 0-6H6zm8.5-3H18l-4 6 4 6h-3.5l-4-6z"/></svg>' );
		add_menu_page( __( 'دیجی‌کالا', 'dk-bridge' ), __( 'دیجی‌کالا', 'dk-bridge' ), $cap, self::SLUG, array( $this, 'page_dashboard' ), $icon, 56 );
		add_submenu_page( self::SLUG, __( 'داشبورد', 'dk-bridge' ), __( 'داشبورد', 'dk-bridge' ), $cap, self::SLUG, array( $this, 'page_dashboard' ) );
		add_submenu_page( self::SLUG, __( 'دستیار امروز', 'dk-bridge' ), __( 'دستیار امروز', 'dk-bridge' ), $cap, self::SLUG . '-assistant', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'محتوا و ارسال', 'dk-bridge' ), __( 'محتوا و ارسال', 'dk-bridge' ), $cap, self::SLUG . '-products', array( $this, 'page_products' ) );
		add_submenu_page( self::SLUG, __( 'مدیریت کالا', 'dk-bridge' ), __( 'مدیریت کالا', 'dk-bridge' ), $cap, self::SLUG . '-catalog', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'مدیریت موجودی', 'dk-bridge' ), __( 'مدیریت موجودی', 'dk-bridge' ), $cap, self::SLUG . '-inventory', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'لجستیک هوشمند', 'dk-bridge' ), __( 'لجستیک هوشمند', 'dk-bridge' ), $cap, self::SLUG . '-logistics', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'قیمت‌گذاری هوشمند', 'dk-bridge' ), __( 'قیمت‌گذاری هوشمند', 'dk-bridge' ), $cap, self::SLUG . '-pricing', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'پروموشن‌ها', 'dk-bridge' ), __( 'پروموشن‌ها', 'dk-bridge' ), $cap, self::SLUG . '-promotions', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'تحلیل عملکرد', 'dk-bridge' ), __( 'تحلیل عملکرد', 'dk-bridge' ), $cap, self::SLUG . '-analytics', array( $this, 'page_module' ) );
		add_submenu_page( self::SLUG, __( 'نگاشت دسته‌ها', 'dk-bridge' ), __( 'نگاشت دسته‌ها', 'dk-bridge' ), $cap, self::SLUG . '-categories', array( $this, 'page_categories' ) );
		add_submenu_page( self::SLUG, __( 'نگاشت ویژگی‌ها', 'dk-bridge' ), __( 'نگاشت ویژگی‌ها', 'dk-bridge' ), $cap, self::SLUG . '-attributes', array( $this, 'page_attributes' ) );
		add_submenu_page( self::SLUG, __( 'تنظیمات', 'dk-bridge' ), __( 'تنظیمات', 'dk-bridge' ), $cap, self::SLUG . '-settings', array( $this, 'page_settings' ) );
		add_submenu_page( self::SLUG, __( 'گزارش‌ها', 'dk-bridge' ), __( 'گزارش‌ها', 'dk-bridge' ), $cap, self::SLUG . '-logs', array( $this, 'page_logs' ) );
		add_submenu_page( self::SLUG, __( 'قوانین دیجی‌کالا', 'dk-bridge' ), __( 'قوانین دیجی‌کالا', 'dk-bridge' ), $cap, self::SLUG . '-rules', array( $this, 'page_module' ) );
	}

	/**
	 * Whether the current screen belongs to the plugin.
	 *
	 * @param string $hook Hook suffix.
	 * @return bool
	 */
	private function is_plugin_screen( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return false !== strpos( (string) $hook, self::SLUG ) || ( $screen && 'product' === $screen->post_type );
	}

	/**
	 * Assets.
	 *
	 * @param string $hook Hook.
	 */
	public function assets( $hook ) {
		if ( ! $this->is_plugin_screen( $hook ) ) {
			return;
		}
		wp_enqueue_style( 'dkb-admin', DKB_URL . 'assets/admin.css', array(), DKB_VERSION );
		wp_enqueue_script( 'dkb-admin', DKB_URL . 'assets/admin.js', array(), DKB_VERSION, true );
		wp_localize_script(
			'dkb-admin',
			'DKB',
			array(
				'root'   => esc_url_raw( rest_url( 'dk-bridge/v1/admin/' ) ),
				'nonce'  => wp_create_nonce( 'wp_rest' ),
				'states' => \DKBridge\Data\ItemRepository::STATES,
				'i18n'   => array(
					'saving'    => __( 'در حال ذخیره…', 'dk-bridge' ),
					'saved'     => __( 'ذخیره شد', 'dk-bridge' ),
					'error'     => __( 'خطا', 'dk-bridge' ),
					'loading'   => __( 'در حال دریافت…', 'dk-bridge' ),
					'confirm'   => __( 'مطمئن هستید؟', 'dk-bridge' ),
					'noResults' => __( 'نتیجه‌ای پیدا نشد', 'dk-bridge' ),
					'queued'    => __( 'در صف ارسال قرار گرفت', 'dk-bridge' ),
				),
			)
		);
	}

	/**
	 * Redirect to the dashboard after activation.
	 */
	public function activation_redirect() {
		if ( get_transient( 'dkb_activation_redirect' ) ) {
			delete_transient( 'dkb_activation_redirect' );
			if ( ! isset( $_GET['activate-multi'] ) && current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
				exit;
			}
		}
	}

	/**
	 * Plugin row links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) . '">' . esc_html__( 'تنظیمات', 'dk-bridge' ) . '</a>' );
		return $links;
	}

	/**
	 * Save settings (admin-post).
	 */
	public function save_settings() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'dk-bridge' ) );
		}
		check_admin_referer( 'dkb_settings' );
		$raw      = isset( $_POST['dkb'] ) && is_array( $_POST['dkb'] ) ? wp_unslash( $_POST['dkb'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$defaults = Settings::defaults();
		$tab      = isset( $_POST['dkb_tab'] ) ? sanitize_key( wp_unslash( $_POST['dkb_tab'] ) ) : 'connection';

		// Unchecked checkboxes of the submitted tab.
		$booleans = isset( $_POST['dkb_booleans'] ) ? array_map( 'sanitize_key', explode( ',', sanitize_text_field( wp_unslash( $_POST['dkb_booleans'] ) ) ) ) : array();
		foreach ( $booleans as $key ) {
			if ( isset( $defaults[ $key ] ) && ! isset( $raw[ $key ] ) ) {
				$raw[ $key ] = false;
			}
		}
		if ( 'smart' === $tab && ! isset( $raw['promo_natures'] ) ) {
			$raw['promo_natures'] = array();
		}
		if ( isset( $raw['alert_email'] ) ) {
			$raw['alert_email'] = sanitize_email( $raw['alert_email'] );
		}
		if ( isset( $raw['webhook_events'] ) && is_array( $raw['webhook_events'] ) ) {
			$raw['webhook_events'] = array_map( 'sanitize_key', $raw['webhook_events'] );
		} elseif ( 'webhook' === $tab ) {
			$raw['webhook_events'] = array();
		}
		if ( isset( $raw['base_url'] ) ) {
			$url = esc_url_raw( trim( $raw['base_url'] ) );
			// Only Digikala hosts or local mock servers may receive the token.
			$host            = (string) wp_parse_url( $url, PHP_URL_HOST );
			$raw['base_url'] = ( preg_match( '/(^|\.)digikala\.com$/i', $host ) || in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) ? untrailingslashit( $url ) : 'https://seller.digikala.com';
		}
		if ( isset( $raw['notify_email'] ) ) {
			$raw['notify_email'] = sanitize_email( $raw['notify_email'] );
		}
		if ( isset( $raw['extra_forbidden_words'] ) ) {
			$raw['extra_forbidden_words'] = sanitize_textarea_field( $raw['extra_forbidden_words'] );
		}
		if ( isset( $raw['default_disadvantage'] ) ) {
			$raw['default_disadvantage'] = sanitize_text_field( $raw['default_disadvantage'] );
		}
		$this->plugin->settings()->update( $raw );

		// Tokens.
		$tokens = new TokenStore();
		$access = isset( $_POST['dkb_access_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['dkb_access_token'] ) ) ) : '';
		$refresh = isset( $_POST['dkb_refresh_token'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['dkb_refresh_token'] ) ) ) : '';
		$msg     = 'saved';
		if ( '' !== $access ) {
			try {
				// Values Digikala shows encrypted with our public key are decrypted here.
				if ( \DKBridge\Security\KeyPair::looks_encrypted( $access ) && \DKBridge\Security\KeyPair::stored() ) {
					$access = trim( \DKBridge\Security\KeyPair::decrypt_stored( $access ) );
				}
				if ( '' !== $refresh && \DKBridge\Security\KeyPair::looks_encrypted( $refresh ) && \DKBridge\Security\KeyPair::stored() ) {
					$refresh = trim( \DKBridge\Security\KeyPair::decrypt_stored( $refresh ) );
				}
				$tokens->save( $access, $refresh, TokenStore::jwt_expiry( $access ) );
				delete_transient( 'dkb_recurring_checked' );
			} catch ( \Exception $e ) {
				set_transient( 'dkb_admin_error', $e->getMessage(), 60 );
				$msg = 'error';
			}
		}
		$code = isset( $_POST['dkb_authorization_code'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['dkb_authorization_code'] ) ) ) : '';
		if ( '' !== $code && 'error' !== $msg && strlen( $code ) > 200 && ! \DKBridge\Security\KeyPair::looks_encrypted( $code ) ) {
			set_transient( 'dkb_admin_error', __( 'کد اعتبارسنجی کامل کپی نشده است؛ در پنل دوباره «کپی کردن» را بزنید و کل متن را جای‌گذاری کنید.', 'dk-bridge' ), 60 );
			$msg  = 'error';
			$code = '';
		}
		if ( '' !== $code && 'error' !== $msg ) {
			try {
				$this->plugin->api()->client()->exchange_code( $code );
				$msg = 'connected';
				if ( '' === trim( (string) $this->plugin->settings()->get( 'seller_code' ) ) ) {
					$found = TokenStore::seller_code_from_token( ( new TokenStore() )->access_token() );
					if ( '' !== $found ) {
						$this->plugin->settings()->update( array( 'seller_code' => $found ) );
					}
				}
			} catch ( \Exception $e ) {
				set_transient( 'dkb_admin_error', $e->getMessage(), 60 );
				$msg = 'error';
			}
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG . '-settings', 'tab' => $tab, 'dkb_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Key pair actions: generate, import, download public key, delete.
	 */
	public function keys() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'dk-bridge' ) );
		}
		check_admin_referer( 'dkb_keys' );
		$do  = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		$msg = 'keys_saved';
		try {
			switch ( $do ) {
				case 'generate':
					\DKBridge\Security\KeyPair::create();
					$this->plugin->logger()->info( 'auth', 'جفت‌کلید RSA جدید ساخته شد.' );
					break;
				case 'import':
					$pem = isset( $_POST['dkb_private_key'] ) ? sanitize_textarea_field( wp_unslash( $_POST['dkb_private_key'] ) ) : '';
					\DKBridge\Security\KeyPair::store( $pem );
					$this->plugin->logger()->info( 'auth', 'کلید خصوصی وارد شد.' );
					break;
				case 'delete':
					\DKBridge\Security\KeyPair::delete();
					$msg = 'keys_deleted';
					break;
				case 'download':
					$info = \DKBridge\Security\KeyPair::stored();
					if ( $info ) {
						nocache_headers();
						header( 'Content-Type: application/x-pem-file' );
						header( 'Content-Disposition: attachment; filename=digikala-public-key.pem' );
						echo $info['public']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						exit;
					}
					break;
			}
		} catch ( \Exception $e ) {
			set_transient( 'dkb_admin_error', $e->getMessage(), 60 );
			$msg = 'error';
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-settings&tab=connection&dkb_msg=' . $msg ) );
		exit;
	}

	/**
	 * Remove tokens.
	 */
	public function disconnect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'dk-bridge' ) );
		}
		check_admin_referer( 'dkb_disconnect' );
		( new TokenStore() )->clear();
		\DKBridge\Sync\Queue::unschedule_all();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-settings&dkb_msg=disconnected' ) );
		exit;
	}

	/**
	 * Export logs as CSV.
	 */
	public function export_logs() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'دسترسی ندارید.', 'dk-bridge' ) );
		}
		check_admin_referer( 'dkb_export_logs' );
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT created_at, level, channel, wc_product_id, message, context FROM {$wpdb->prefix}dkb_logs ORDER BY id DESC LIMIT 5000", ARRAY_A );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=dk-bridge-logs-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'created_at', 'level', 'channel', 'product', 'message', 'context' ) );
		foreach ( (array) $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Admin notices.
	 */
	public function notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( (string) $screen->id, self::SLUG ) ) {
			return;
		}
		if ( ! $this->plugin->is_connected() && false === strpos( (string) $screen->id, 'settings' ) ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post( sprintf( __( 'افزونه هنوز به پنل فروشنده متصل نیست. <a href="%s">اتصال به دیجی‌کالا</a>', 'dk-bridge' ), esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) ) ) ) . '</p></div>';
		}
		if ( $this->plugin->settings()->get( 'dry_run' ) ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'حالت اجرای آزمایشی فعال است: هیچ درخواست تغییردهنده‌ای به دیجی‌کالا ارسال نمی‌شود.', 'dk-bridge' ) . '</p></div>';
		}
		$msg = isset( $_GET['dkb_msg'] ) ? sanitize_key( wp_unslash( $_GET['dkb_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map = array(
			'saved'        => array( 'success', __( 'تنظیمات ذخیره شد.', 'dk-bridge' ) ),
			'connected'    => array( 'success', __( 'اتصال با موفقیت برقرار شد.', 'dk-bridge' ) ),
			'disconnected' => array( 'success', __( 'اتصال قطع و توکن‌ها حذف شدند.', 'dk-bridge' ) ),
			'error'        => array( 'error', (string) get_transient( 'dkb_admin_error' ) ),
			'bulk'         => array( 'success', __( 'عملیات گروهی انجام شد.', 'dk-bridge' ) ),
			'keys_saved'   => array( 'success', __( 'کلید ذخیره شد. کلید عمومی را در پنل فروشندگان ثبت کنید.', 'dk-bridge' ) ),
			'keys_deleted' => array( 'success', __( 'جفت‌کلید حذف شد.', 'dk-bridge' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	/**
	 * Column in the WooCommerce product list.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function product_column( $columns ) {
		$columns['dkb'] = __( 'دیجی‌کالا', 'dk-bridge' );
		return $columns;
	}

	/**
	 * Column value.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public function product_column_value( $column, $post_id ) {
		if ( 'dkb' !== $column ) {
			return;
		}
		$item = $this->plugin->items()->get( $post_id );
		echo Views::state_badge( $item ) . ' ' . Views::score_ring( $item ? $item['score'] : null, 'small' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Bulk actions in the product list.
	 *
	 * @param array $actions Actions.
	 * @return array
	 */
	public function product_bulk_actions( $actions ) {
		$actions['dkb_validate'] = __( 'دیجی‌کالا: بررسی قوانین', 'dk-bridge' );
		$actions['dkb_push']     = __( 'دیجی‌کالا: ارسال', 'dk-bridge' );
		$actions['dkb_sync']     = __( 'دیجی‌کالا: همگام‌سازی موجودی و قیمت', 'dk-bridge' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @param string $redirect Redirect.
	 * @param string $action   Action.
	 * @param int[]  $ids      Ids.
	 * @return string
	 */
	public function handle_product_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( $action, 'dkb_' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return $redirect;
		}
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				continue;
			}
			if ( 'dkb_validate' === $action ) {
				$this->plugin->publisher()->evaluate( $product, false );
			} elseif ( 'dkb_push' === $action ) {
				$this->plugin->publisher()->enqueue( $id );
			} elseif ( 'dkb_sync' === $action ) {
				$this->plugin->items()->mark_dirty( $id, -1, 'both' );
			}
		}
		return add_query_arg( 'dkb_msg', 'bulk', $redirect );
	}

	/** Page. */
	public function page_dashboard() {
		Pages::dashboard( $this->plugin );
	}

	/** Page. */
	public function page_products() {
		Pages::products( $this->plugin );
	}

	/** Page. */
	public function page_categories() {
		Pages::categories( $this->plugin );
	}

	/** Page. */
	public function page_attributes() {
		Pages::attributes( $this->plugin );
	}

	/** Page. */
	public function page_settings() {
		Pages::settings( $this->plugin );
	}

	/**
	 * Module pages dispatcher.
	 */
	public function page_module() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'dk-bridge-assistant'  => 'assistant',
			'dk-bridge-catalog'    => 'catalog',
			'dk-bridge-inventory'  => 'inventory',
			'dk-bridge-logistics'  => 'logistics',
			'dk-bridge-pricing'    => 'pricing',
			'dk-bridge-promotions' => 'promotions',
			'dk-bridge-analytics'  => 'analytics',
			'dk-bridge-rules'      => 'rules',
		);
		if ( isset( $map[ $page ] ) ) {
			try {
				call_user_func( array( ModulePages::class, $map[ $page ] ), $this->plugin );
			} catch ( \Throwable $e ) {
				echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html( $e->getMessage() ) . '</p></div></div>';
			}
		}
	}

	/** Page. */
	public function page_logs() {
		Pages::logs( $this->plugin );
	}
}
