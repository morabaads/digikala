<?php
/**
 * PSR-4 style autoloader for the DKBridge namespace.
 *
 * @package DKBridge
 */

namespace DKBridge;

defined( 'ABSPATH' ) || exit;

/**
 * Maps DKBridge\Foo\Bar to includes/Foo/Bar.php.
 */
final class Autoloader {

	/**
	 * Register the autoloader.
	 */
	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public static function load( $class ) {
		$prefix = __NAMESPACE__ . '\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
