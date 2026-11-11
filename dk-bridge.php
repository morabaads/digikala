<?php
/**
 * Plugin Name:       DK Bridge — انتقال ووکامرس به دیجی‌کالا
 * Plugin URI:        https://github.com/morabaads/digikala
 * Description:       انتقال محصولات ووکامرس به پنل فروشندگان دیجی‌کالا از طریق Open API رسمی، اصلاح خودکار محتوا طبق قوانین دیجی‌کالا، و همگام‌سازی دوطرفهٔ موجودی، قیمت و سفارش.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Morabaads
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dk-bridge
 * Domain Path:       /languages
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package DKBridge
 */

defined( 'ABSPATH' ) || exit;

define( 'DKB_VERSION', '1.1.0' );
define( 'DKB_DB_VERSION', '1.1.0' );
define( 'DKB_FILE', __FILE__ );
define( 'DKB_DIR', plugin_dir_path( __FILE__ ) );
define( 'DKB_URL', plugin_dir_url( __FILE__ ) );
define( 'DKB_BASENAME', plugin_basename( __FILE__ ) );

require_once DKB_DIR . 'includes/Autoloader.php';
\DKBridge\Autoloader::register();

register_activation_hook( __FILE__, array( '\DKBridge\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\DKBridge\Installer', 'deactivate' ) );

// Declare HPOS (custom order tables) compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		load_plugin_textdomain( 'dk-bridge', false, dirname( DKB_BASENAME ) . '/languages' );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'افزونهٔ DK Bridge برای کار به ووکامرس نیاز دارد. لطفاً ووکامرس را نصب و فعال کنید.', 'dk-bridge' ) . '</p></div>';
				}
			);
			return;
		}

		\DKBridge\Plugin::instance()->boot();
	}
);

/**
 * Global accessor for the plugin container.
 *
 * @return \DKBridge\Plugin
 */
function dkb() {
	return \DKBridge\Plugin::instance();
}
