<?php
/**
 * Uninstall: remove data only when the user opted in.
 *
 * @package DKBridge
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$dkb_settings = get_option( 'dkb_settings', array() );
if ( is_array( $dkb_settings ) && ! empty( $dkb_settings['delete_data_on_uninstall'] ) ) {
	if ( ! defined( 'DKB_DB_VERSION' ) ) {
		define( 'DKB_DB_VERSION', '1.1.0' );
	}
	require_once __DIR__ . '/includes/Autoloader.php';
	\DKBridge\Autoloader::register();
	\DKBridge\Installer::drop();
}
wp_clear_scheduled_hook( 'dkb_flush_stock' );
