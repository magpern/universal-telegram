<?php
/**
 * Contract v1 signature verification outcome (ADR-0007 §3).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

/**
 * Immutable result. On failure, no cause is exposed beyond this object's
 * own internal state — callers must return the uniform denial regardless
 * of which check failed (ADR-0007 §3, §5).
 */
final class VerificationResult {

	/**
	 * Whether verification succeeded.
	 *
	 * @var bool
	 */
	private bool $ok;

	/**
	 * The verified sender's peer ID, when ok.
	 *
	 * @var string|null
	 */
	private ?string $peer_id;

	/**
	 * Constructor.
	 *
	 * @param bool        $ok      Whether verification succeeded.
	 * @param string|null $peer_id Verified sender peer ID, when ok.
	 */
	private function __construct( bool $ok, ?string $peer_id ) {
		$this->ok      = $ok;
		$this->peer_id = $peer_id;
	}

	/**
	 * A successful verification for the given peer.
	 *
	 * @param string $peer_id Verified sender peer ID.
	 */
	public static function accepted( string $peer_id ): self {
		return new self( true, $peer_id );
	}

	/**
	 * A failed verification. Never carries a reason — the caller must
	 * return the same uniform denial regardless of cause.
	 */
	public static function denied(): self {
		return new self( false, null );
	}

	/**
	 * Whether verification succeeded.
	 */
	public function ok(): bool {
		return $this->ok;
	}

	/**
	 * The verified sender's peer ID, when ok.
	 */
	public function peer_id(): ?string {
		return $this->peer_id;
	}
}
