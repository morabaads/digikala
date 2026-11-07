<?php
/**
 * Thrown instead of sending a mutating request in dry-run mode.
 *
 * @package DKBridge
 */

namespace DKBridge\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the request that would have been sent.
 */
class DryRunException extends ApiException {

	/**
	 * Body.
	 *
	 * @var array
	 */
	private $body;

	/**
	 * Path.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Constructor.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param array  $body   Body.
	 */
	public function __construct( $method, $path, array $body ) {
		parent::__construct( sprintf( 'Dry run: %s %s was not sent.', $method, $path ), 0 );
		$this->path = $path;
		$this->body = $body;
	}

	/**
	 * Path.
	 *
	 * @return string
	 */
	public function path() {
		return $this->path;
	}

	/**
	 * Body.
	 *
	 * @return array
	 */
	public function body() {
		return $this->body;
	}

	/**
	 * Dry-run is not a transient failure.
	 *
	 * @return bool
	 */
	public function is_transient() {
		return false;
	}
}
