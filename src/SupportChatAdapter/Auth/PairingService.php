<?php
/**
 * Administrator-authorized pairing (ADR-0007 §2).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * Orchestrates pairing/rotation/revocation of the Support Chat peer.
 * Capability checks are the caller's responsibility — ADR-0007 §2's
 * both-capabilities rule is enforced at the admin controller layer, before
 * any of these methods run — this class only enforces the pairing-specific
 * invariants: valid input, confirm-before-replace, and audit-without-secrets.
 */
class PairingService {

	/**
	 * Constructor.
	 *
	 * @param PeerRepository $peers Peer key store.
	 * @param AuditLogger    $audit Audit logger.
	 */
	public function __construct(
		private readonly PeerRepository $peers,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Pairs (or re-pairs) a peer. Idempotent when the incoming key and
	 * metadata are unchanged from an active pairing; otherwise requires
	 * `$confirm_replace` before an existing active key is superseded.
	 *
	 * @param string             $peer_id                  Peer slug.
	 * @param string             $public_key_base64         Base64 public key.
	 * @param string             $key_id                    Peer key ID.
	 * @param array<int, string> $allowed_operations        Permitted operations (Support Chat → adapter).
	 * @param string|null        $required_peer_capability  Required peer capability.
	 * @param bool               $confirm_replace           Explicit confirmation to replace an active key.
	 * @param int|null           $actor_user_id             Acting administrator's WP user ID, for audit.
	 * @param string|null        $expires_at                Expiry (UTC mysql), or null for no expiry.
	 */
	public function pair(
		string $peer_id,
		string $public_key_base64,
		string $key_id,
		array $allowed_operations,
		?string $required_peer_capability,
		bool $confirm_replace,
		?int $actor_user_id,
		?string $expires_at = null
	): PairingResult {
		if ( ! $this->is_valid_peer_id( $peer_id ) ) {
			return PairingResult::failure( PairingResult::REASON_INVALID_INPUT );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding, not obfuscation.
		$raw_key = base64_decode( $public_key_base64, true );
		if ( false === $raw_key || 32 !== strlen( $raw_key ) ) {
			return PairingResult::failure( PairingResult::REASON_INVALID_INPUT );
		}

		if ( ! KeyId::is_valid_format( $key_id ) || KeyId::compute( $peer_id, $raw_key ) !== $key_id ) {
			return PairingResult::failure( PairingResult::REASON_INVALID_INPUT );
		}

		if ( ! ContractConstants::is_valid_peer_allow_list( $allowed_operations ) ) {
			return PairingResult::failure( PairingResult::REASON_INVALID_INPUT );
		}

		$existing = $this->peers->find_by_peer_id( $peer_id );

		if ( null === $existing ) {
			$created = $this->peers->create( $peer_id, $public_key_base64, $key_id, $allowed_operations, $required_peer_capability, $expires_at );
			if ( null === $created ) {
				return PairingResult::failure( PairingResult::REASON_UNAVAILABLE );
			}

			$this->record_audit( 'support_chat_adapter.peer_paired', $peer_id, $key_id, $actor_user_id );

			return PairingResult::success( PairingResult::REASON_CREATED );
		}

		$unchanged = $existing->is_usable()
			&& hash_equals( $existing->public_key_base64(), $public_key_base64 )
			&& hash_equals( $existing->key_id(), $key_id )
			&& $existing->allowed_operations() === array_values( $allowed_operations )
			&& $existing->required_peer_capability() === $required_peer_capability;

		if ( $unchanged ) {
			return PairingResult::success( PairingResult::REASON_UNCHANGED );
		}

		if ( ! $confirm_replace ) {
			return PairingResult::failure( PairingResult::REASON_CONFIRMATION_REQUIRED );
		}

		$replaced = $this->peers->replace_key( $peer_id, $public_key_base64, $key_id, $allowed_operations, $required_peer_capability, $expires_at );
		if ( null === $replaced ) {
			return PairingResult::failure( PairingResult::REASON_UNAVAILABLE );
		}

		$this->record_audit( 'support_chat_adapter.peer_key_replaced', $peer_id, $key_id, $actor_user_id );

		return PairingResult::success( PairingResult::REASON_REPLACED );
	}

	/**
	 * Revokes a peer's key. Immediate: calls signed with it are rejected
	 * from the next request onward.
	 *
	 * @param string   $peer_id       Peer slug.
	 * @param int|null $actor_user_id Acting administrator's WP user ID, for audit.
	 */
	public function revoke( string $peer_id, ?int $actor_user_id ): bool {
		$ok = $this->peers->set_status( $peer_id, PeerRecord::STATUS_REVOKED );

		if ( $ok ) {
			$this->record_audit( 'support_chat_adapter.peer_revoked', $peer_id, null, $actor_user_id );
		}

		return $ok;
	}

	/**
	 * Disables a peer without revoking its key (reversible via enable()).
	 *
	 * @param string   $peer_id       Peer slug.
	 * @param int|null $actor_user_id Acting administrator's WP user ID, for audit.
	 */
	public function disable( string $peer_id, ?int $actor_user_id ): bool {
		$ok = $this->peers->set_status( $peer_id, PeerRecord::STATUS_DISABLED );

		if ( $ok ) {
			$this->record_audit( 'support_chat_adapter.peer_disabled', $peer_id, null, $actor_user_id );
		}

		return $ok;
	}

	/**
	 * Re-enables a disabled peer.
	 *
	 * @param string   $peer_id       Peer slug.
	 * @param int|null $actor_user_id Acting administrator's WP user ID, for audit.
	 */
	public function enable( string $peer_id, ?int $actor_user_id ): bool {
		$ok = $this->peers->set_status( $peer_id, PeerRecord::STATUS_ACTIVE );

		if ( $ok ) {
			$this->record_audit( 'support_chat_adapter.peer_enabled', $peer_id, null, $actor_user_id );
		}

		return $ok;
	}

	/**
	 * Whether the peer slug is syntactically acceptable.
	 *
	 * @param string $peer_id Candidate peer slug.
	 */
	private function is_valid_peer_id( string $peer_id ): bool {
		return 1 === preg_match( '/^[a-z0-9-]{1,191}$/', $peer_id );
	}

	/**
	 * Records a pairing audit event. Never includes key material.
	 *
	 * @param string      $action        Audit action name.
	 * @param string      $peer_id       Peer slug.
	 * @param string|null $key_id        Key ID, if relevant (not the key itself).
	 * @param int|null    $actor_user_id Acting administrator's WP user ID.
	 */
	private function record_audit( string $action, string $peer_id, ?string $key_id, ?int $actor_user_id ): void {
		$context = array( 'peer_id' => $peer_id );
		$map     = array( 'peer_id' => Classification::INTERNAL );

		if ( null !== $key_id ) {
			$context['key_id'] = $key_id;
			$map['key_id']     = Classification::INTERNAL;
		}

		$this->audit->record( $action, 'operator', $actor_user_id, $context, $map, Classification::INTERNAL );
	}
}
