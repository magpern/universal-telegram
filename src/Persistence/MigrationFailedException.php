<?php
/**
 * Migration failure exception.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

use Exception;

/**
 * Carries only a stable MigrationFailureCode, never a raw database error
 * string, so anything that catches it and reports the failure upward
 * (an admin notice, a log entry) cannot leak internal schema detail.
 */
final class MigrationFailedException extends Exception {

	/**
	 * The stable failure code this exception carries.
	 *
	 * @var MigrationFailureCode
	 */
	private MigrationFailureCode $failure_code;

	/**
	 * Constructor.
	 *
	 * @param MigrationFailureCode $failure_code The stable failure code.
	 */
	public function __construct( MigrationFailureCode $failure_code ) {
		parent::__construct( $failure_code->value );

		$this->failure_code = $failure_code;
	}

	/**
	 * The stable failure code this exception carries.
	 *
	 * @return MigrationFailureCode
	 */
	public function failure_code(): MigrationFailureCode {
		return $this->failure_code;
	}
}
