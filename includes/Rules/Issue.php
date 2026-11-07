<?php
/**
 * A single compliance finding.
 *
 * @package DKBridge
 */

namespace DKBridge\Rules;

/**
 * Issue value object.
 */
class Issue {

	const ERROR   = 'error';
	const WARNING = 'warning';
	const INFO    = 'info';

	/** @var string */
	public $code;
	/** @var string */
	public $severity;
	/** @var string Score section (title_fa, photo…). */
	public $section;
	/** @var string */
	public $message;
	/** @var bool */
	public $fixable;
	/** @var string Rule id that produced it. */
	public $rule;
	/** @var array */
	public $data;

	/**
	 * Constructor.
	 *
	 * @param string $code     Code.
	 * @param string $severity Severity.
	 * @param string $section  Section.
	 * @param string $message  Persian message.
	 * @param bool   $fixable  Auto-fixable.
	 * @param array  $data     Extra.
	 */
	public function __construct( $code, $severity, $section, $message, $fixable = false, array $data = array() ) {
		$this->code     = $code;
		$this->severity = $severity;
		$this->section  = $section;
		$this->message  = $message;
		$this->fixable  = (bool) $fixable;
		$this->data     = $data;
		$this->rule     = '';
	}

	/**
	 * Array form (for storage / REST).
	 *
	 * @return array
	 */
	public function to_array() {
		return array(
			'code'     => $this->code,
			'severity' => $this->severity,
			'section'  => $this->section,
			'message'  => $this->message,
			'fixable'  => $this->fixable,
			'data'     => $this->data,
		);
	}
}
