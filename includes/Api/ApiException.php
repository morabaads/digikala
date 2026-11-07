<?php
/**
 * API error.
 *
 * @package DKBridge
 */

namespace DKBridge\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Raised for any non-OK Digikala response.
 */
class ApiException extends \RuntimeException {

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Validation errors returned by the API.
	 *
	 * @var array
	 */
	private $errors;

	/**
	 * Seconds to wait before retrying (for 429 / circuit breaker).
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * Constructor.
	 *
	 * @param string $message     Message.
	 * @param int    $http_status HTTP status (0 for network errors).
	 * @param array  $errors      Error details.
	 * @param int    $retry_after Retry delay.
	 */
	public function __construct( $message, $http_status = 0, array $errors = array(), $retry_after = 0 ) {
		parent::__construct( $message, $http_status );
		$this->http_status = (int) $http_status;
		$this->errors      = $errors;
		$this->retry_after = (int) $retry_after;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function http_status() {
		return $this->http_status;
	}

	/**
	 * Errors.
	 *
	 * @return array
	 */
	public function errors() {
		return $this->errors;
	}

	/**
	 * Retry delay in seconds.
	 *
	 * @return int
	 */
	public function retry_after() {
		return $this->retry_after;
	}

	/**
	 * Whether the failure is temporary and the job should be retried later.
	 *
	 * @return bool
	 */
	public function is_transient() {
		return 0 === $this->http_status || 429 === $this->http_status || $this->http_status >= 500;
	}

	/**
	 * Human readable message including the validation details.
	 *
	 * @return string
	 */
	public function full_message() {
		$parts = array( $this->getMessage() );
		foreach ( self::flatten( $this->errors ) as $line ) {
			$parts[] = $line;
		}
		return implode( ' — ', array_unique( array_filter( $parts ) ) );
	}

	/**
	 * Flatten nested error structures to strings.
	 *
	 * @param mixed  $errors Errors.
	 * @param string $prefix Key prefix.
	 * @return string[]
	 */
	public static function flatten( $errors, $prefix = '' ) {
		$out = array();
		if ( is_array( $errors ) ) {
			foreach ( $errors as $key => $value ) {
				$label = is_string( $key ) ? ( $prefix ? $prefix . '.' . $key : $key ) : $prefix;
				$out   = array_merge( $out, self::flatten( $value, $label ) );
			}
		} elseif ( is_scalar( $errors ) && '' !== (string) $errors ) {
			$out[] = ( $prefix ? $prefix . ': ' : '' ) . $errors;
		}
		return $out;
	}
}
