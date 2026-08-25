<?php
/**
 * Outcome of a pairing action (ADR-0007 §2).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

/**
 * Immutable result of a PairingService action. `reason` is an internal,
 * stable, non-sensitive code for the admin UI only — never key material.
 */
final class PairingResult {

	public const REASON_CREATED               = 'created';
	public const REASON_UNCHANGED             = 'unchanged';
	public const REASON_CONFIRMATION_REQUIRED = 'confirmation_required';
	public const REASON_REPLACED              = 'replaced';
	public const REASON_INVALID_INPUT         = 'invalid_input';
	public const REASON_UNAVAILABLE           = 'unavailable';

	/**
	 * Whether the action succeeded.
	 *
	 * @var bool
	 */
	private bool $ok;

	/**
	 * Stable, non-sensitive reason code.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Constructor.
	 *
	 * @param bool   $ok     Whether the action succeeded.
	 * @param string $reason Stable, non-sensitive reason code.
	 */
	private function __construct( bool $ok, string $reason ) {
		$this->ok     = $ok;
		$this->reason = $reason;
	}

	/**
	 * A successful outcome.
	 *
	 * @param string $reason One of the REASON_* constants.
	 */
	public static function success( string $reason ): self {
		return new self( true, $reason );
	}

	/**
	 * A failed outcome.
	 *
	 * @param string $reason One of the REASON_* constants.
	 */
	public static function failure( string $reason ): self {
		return new self( false, $reason );
	}

	/**
	 * Whether the action succeeded.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * Stable, non-sensitive reason code.
	 */
	public function reason(): string {
		return $this->reason;
	}
}
