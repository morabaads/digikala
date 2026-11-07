<?php
/**
 * Base rule.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules;

use DKBridge\Mapping\ProductData;

/**
 * A rule inspects ProductData and can optionally fix it in place.
 */
abstract class Rule {

	/**
	 * Config.
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * Constructor.
	 *
	 * @param array $config Rule configuration.
	 */
	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	/**
	 * Config value.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	protected function cfg( $key, $default = null ) {
		return array_key_exists( $key, $this->config ) ? $this->config[ $key ] : $default;
	}

	/**
	 * Unique id.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * Inspect.
	 *
	 * @param ProductData $p Product.
	 * @return Issue[]
	 */
	abstract public function check( ProductData $p );

	/**
	 * Fix what can be fixed. Returns human-readable change notes.
	 *
	 * @param ProductData $p      Product.
	 * @param Issue[]     $issues Issues produced by check().
	 * @return string[]
	 */
	public function fix( ProductData $p, array $issues ) {
		return array();
	}

	/**
	 * Helper to create an issue.
	 *
	 * @param string $code     Code.
	 * @param string $severity Severity.
	 * @param string $section  Section.
	 * @param string $message  Message.
	 * @param bool   $fixable  Fixable.
	 * @param array  $data     Data.
	 * @return Issue
	 */
	protected function issue( $code, $severity, $section, $message, $fixable = false, array $data = array() ) {
		$issue       = new Issue( $code, $severity, $section, $message, $fixable, $data );
		$issue->rule = $this->id();
		return $issue;
	}
}
