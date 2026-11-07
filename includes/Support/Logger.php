<?php
/**
 * Structured, redacting logger backed by a custom table.
 *
 * @package DKBridge
 */

namespace DKBridge\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Logger.
 */
class Logger {

	const LEVELS = array(
		'debug'   => 0,
		'info'    => 1,
		'warning' => 2,
		'error'   => 3,
	);

	/**
	 * Minimum level.
	 *
	 * @var string
	 */
	private $min_level;

	/**
	 * Constructor.
	 *
	 * @param string $min_level Minimum level to persist.
	 */
	public function __construct( $min_level = 'info' ) {
		$this->min_level = isset( self::LEVELS[ $min_level ] ) ? $min_level : 'info';
	}

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Level.
	 * @param string $channel Channel (api, sync, rules, ...).
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 * @param int    $product WooCommerce product id.
	 */
	public function log( $level, $channel, $message, array $context = array(), $product = 0 ) {
		if ( ! isset( self::LEVELS[ $level ] ) || self::LEVELS[ $level ] < self::LEVELS[ $this->min_level ] ) {
			return;
		}
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return;
		}
		$wpdb->insert(
			$wpdb->prefix . 'dkb_logs',
			array(
				'level'         => $level,
				'channel'       => substr( $channel, 0, 40 ),
				'message'       => $message,
				'context'       => $context ? wp_json_encode( self::redact( $context ), JSON_UNESCAPED_UNICODE ) : null,
				'wc_product_id' => $product ? (int) $product : null,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $product Product.
	 */
	public function debug( $channel, $message, array $context = array(), $product = 0 ) {
		$this->log( 'debug', $channel, $message, $context, $product );
	}

	/**
	 * Shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $product Product.
	 */
	public function info( $channel, $message, array $context = array(), $product = 0 ) {
		$this->log( 'info', $channel, $message, $context, $product );
	}

	/**
	 * Shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $product Product.
	 */
	public function warning( $channel, $message, array $context = array(), $product = 0 ) {
		$this->log( 'warning', $channel, $message, $context, $product );
	}

	/**
	 * Shortcut.
	 *
	 * @param string $channel Channel.
	 * @param string $message Message.
	 * @param array  $context Context.
	 * @param int    $product Product.
	 */
	public function error( $channel, $message, array $context = array(), $product = 0 ) {
		$this->log( 'error', $channel, $message, $context, $product );
	}

	/**
	 * Remove secrets from context recursively.
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function redact( $data ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				if ( is_string( $key ) && preg_match( '/token|authorization|secret|password|authorization_code/i', $key ) ) {
					$data[ $key ] = '[redacted]';
				} else {
					$data[ $key ] = self::redact( $value );
				}
			}
			return $data;
		}
		if ( is_string( $data ) ) {
			$data = preg_replace( '/Bearer\s+[A-Za-z0-9\-\._~\+\/]+=*/', 'Bearer [redacted]', $data );
			if ( strlen( $data ) > 4000 ) {
				$data = substr( $data, 0, 4000 ) . '…';
			}
		}
		return $data;
	}

	/**
	 * Delete old entries.
	 *
	 * @param int $days Retention.
	 */
	public static function cleanup( $days ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}dkb_logs WHERE created_at < %s",
				gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) $days ) * DAY_IN_SECONDS )
			)
		);
	}
}
