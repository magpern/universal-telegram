<?php
/**
 * Paired Support Chat peer snapshot (ADR-0007 §2).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

/**
 * Immutable snapshot of one paired peer's public key and pairing metadata.
 * Never holds a private key or any secret material.
 */
final class PeerRecord {

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_DISABLED = 'disabled';
	public const STATUS_REVOKED  = 'revoked';

	/**
	 * Constructor.
	 *
	 * @param int                $id                        Primary key.
	 * @param string             $peer_id                   Peer slug, e.g. "universal-support-chat".
	 * @param string             $public_key_base64         Base64 public key.
	 * @param string             $key_id                    Peer's current key ID.
	 * @param array<int, string> $allowed_operations        Permitted operations (Support Chat → adapter).
	 * @param string|null        $required_peer_capability  WordPress capability the pairing administrator was also required to hold.
	 * @param string             $status                    Stored status.
	 * @param string             $created_at                Pairing creation time (UTC mysql).
	 * @param string|null        $last_rotated_at           Last key-replace time (UTC mysql).
	 * @param string|null        $last_used_at              Last successful authenticated call time (UTC mysql).
	 * @param string|null        $expires_at                Expiry time (UTC mysql), or null for no expiry.
	 * @param string|null        $revoked_at                Revocation time (UTC mysql).
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $peer_id,
		private readonly string $public_key_base64,
		private readonly string $key_id,
		private readonly array $allowed_operations,
		private readonly ?string $required_peer_capability,
		private readonly string $status,
		private readonly string $created_at,
		private readonly ?string $last_rotated_at,
		private readonly ?string $last_used_at,
		private readonly ?string $expires_at,
		private readonly ?string $revoked_at
	) {}

	/**
	 * Primary key.
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * Peer slug.
	 */
	public function peer_id(): string {
		return $this->peer_id;
	}

	/**
	 * Base64-encoded public key.
	 */
	public function public_key_base64(): string {
		return $this->public_key_base64;
	}

	/**
	 * Raw 32-byte public key, or null if the stored value cannot decode.
	 */
	public function public_key_raw(): ?string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding, not obfuscation.
		$decoded = base64_decode( $this->public_key_base64, true );

		if ( false === $decoded || 32 !== strlen( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Peer's current key ID.
	 */
	public function key_id(): string {
		return $this->key_id;
	}

	/**
	 * Permitted operation allow-list.
	 *
	 * @return array<int, string>
	 */
	public function allowed_operations(): array {
		return $this->allowed_operations;
	}

	/**
	 * Whether the given operation is on this peer's allow-list.
	 *
	 * @param string $operation Operation name.
	 */
	public function allows( string $operation ): bool {
		return in_array( $operation, $this->allowed_operations, true );
	}

	/**
	 * Required peer capability, if recorded.
	 */
	public function required_peer_capability(): ?string {
		return $this->required_peer_capability;
	}

	/**
	 * Stored status.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * Pairing creation time.
	 */
	public function created_at(): string {
		return $this->created_at;
	}

	/**
	 * Last key-replace time, if any.
	 */
	public function last_rotated_at(): ?string {
		return $this->last_rotated_at;
	}

	/**
	 * Last successful authenticated call time, if any.
	 */
	public function last_used_at(): ?string {
		return $this->last_used_at;
	}

	/**
	 * Expiry time, if any.
	 */
	public function expires_at(): ?string {
		return $this->expires_at;
	}

	/**
	 * Revocation time, if any.
	 */
	public function revoked_at(): ?string {
		return $this->revoked_at;
	}

	/**
	 * Whether this peer's expiry policy has passed, as of now (UTC).
	 */
	public function is_expired(): bool {
		if ( null === $this->expires_at ) {
			return false;
		}

		return strtotime( $this->expires_at . ' UTC' ) < time();
	}

	/**
	 * Whether this peer's key currently verifies calls: active status,
	 * unrevoked, and unexpired.
	 */
	public function is_usable(): bool {
		return self::STATUS_ACTIVE === $this->status && ! $this->is_expired();
	}

	/**
	 * The operator-facing pairing state (ADR-0007 §2). "degraded" and
	 * "incompatible" are computed by the caller from live discovery/status
	 * signals this record alone cannot express — never returned here.
	 */
	public function pairing_state(): string {
		if ( self::STATUS_REVOKED === $this->status ) {
			return 'revoked';
		}

		if ( $this->is_expired() ) {
			return 'expired';
		}

		if ( self::STATUS_DISABLED === $this->status ) {
			return 'paired_disabled';
		}

		return 'active';
	}

	/**
	 * Hydrates from a database row.
	 *
	 * @param array<string, mixed> $row Database row.
	 */
	public static function from_row( array $row ): self {
		$decoded = json_decode( (string) $row['allowed_operations'], true );
		$ops     = is_array( $decoded ) ? array_values( array_filter( $decoded, 'is_string' ) ) : array();

		return new self(
			(int) $row['id'],
			(string) $row['peer_id'],
			(string) $row['public_key'],
			(string) $row['key_id'],
			$ops,
			self::nullable_string( $row['required_peer_capability'] ?? null ),
			(string) $row['status'],
			(string) $row['created_at'],
			self::nullable_string( $row['last_rotated_at'] ?? null ),
			self::nullable_string( $row['last_used_at'] ?? null ),
			self::nullable_string( $row['expires_at'] ?? null ),
			self::nullable_string( $row['revoked_at'] ?? null )
		);
	}

	/**
	 * Coerces a nullable string column.
	 *
	 * @param mixed $value Raw column value.
	 */
	private static function nullable_string( $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}

		return (string) $value;
	}
}
