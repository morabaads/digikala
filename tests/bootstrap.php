<?php
/**
 * PHPUnit bootstrap: loads the pure domain classes without WordPress.
 *
 * @package DKBridge
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/Autoloader.php';
\DKBridge\Autoloader::register();
