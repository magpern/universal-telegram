<?php
/**
 * In-memory PeerRepository test double (no WordPress/$wpdb dependency).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth\Support;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRecord;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;

/**
 * Overrides every method that would otherwise touch $wpdb, so
 * SignatureVerifier and SupportChatContractClient can be unit-tested
 * without a WordPress bootstrap.
 */
final class FakePeerRepository extends PeerRepository {

	/**
	 * @var array<string, PeerRecord>
	 */
	private array $records = array();

	/**
	 * @var array<int, string>
	 */
	public array $touched = array();

	public function __construct() {
		parent::__construct( new SchemaHealth() );
	}

	/**
	 * Seeds a record for a peer id.
	 */
	public function seed( string $peer_id, PeerRecord $record ): void {
		$this->records[ $peer_id ] = $record;
	}

	public function find_by_peer_id( string $peer_id ): ?PeerRecord {
		return $this->records[ $peer_id ] ?? null;
	}

	public function list_all(): array {
		return array_values( $this->records );
	}

	public function create( string $peer_id, string $public_key_base64, string $key_id, array $allowed_operations, ?string $required_peer_capability, ?string $expires_at = null ): ?PeerRecord {
		unset( $public_key_base64, $key_id, $allowed_operations, $required_peer_capability, $expires_at );
		return $this->records[ $peer_id ] ?? null;
	}

	public function replace_key( string $peer_id, string $public_key_base64, string $key_id, array $allowed_operations, ?string $required_peer_capability, ?string $expires_at = null ): ?PeerRecord {
		unset( $public_key_base64, $key_id, $allowed_operations, $required_peer_capability, $expires_at );
		return $this->records[ $peer_id ] ?? null;
	}

	public function set_status( string $peer_id, string $status ): bool {
		unset( $peer_id, $status );
		return true;
	}

	public function touch_last_used( string $peer_id ): void {
		$this->touched[] = $peer_id;
	}
}
