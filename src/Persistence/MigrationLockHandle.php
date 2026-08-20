<?php
/**
 * Proof of holding the migration lock.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Persistence;

/**
 * A token and the exact option value string that was written when the
 * lock was acquired. Release compares against this exact value, never
 * against "whatever the lock currently holds", so a stale holder's
 * release can never destroy a legitimate replacement holder's lock.
 */
final class MigrationLockHandle {

	/**
	 * The lock's freshly generated token.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * The exact option value string written when the lock was acquired.
	 *
	 * @var string
	 */
	private string $value;

	/**
	 * Constructor.
	 *
	 * @param string $token The lock's freshly generated token.
	 * @param string $value The exact option value string written.
	 */
	public function __construct( string $token, string $value ) {
		$this->token = $token;
		$this->value = $value;
	}

	/**
	 * The lock's freshly generated token.
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * The exact option value string written when the lock was acquired.
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}
}
