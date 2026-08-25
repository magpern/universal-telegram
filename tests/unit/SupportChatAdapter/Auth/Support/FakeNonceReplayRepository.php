<?php
/**
 * In-memory NonceReplayRepository test double (no WordPress/$wpdb dependency).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth\Support;

use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\Auth\NonceReplayRepository;

/**
 * Mirrors the real repository's race-free "first write wins" semantics
 * with a plain in-memory set, so SignatureVerifier's replay check can be
 * unit-tested without a WordPress bootstrap.
 */
final class FakeNonceReplayRepository extends NonceReplayRepository {

	/**
	 * @var array<string, true>
	 */
	private array $seen = array();

	public function __construct() {
		parent::__construct( new SchemaHealth() );
	}

	public function record_if_new( string $sender, string $key_id, string $nonce ): bool {
		$tuple = $sender . '|' . $key_id . '|' . $nonce;

		if ( isset( $this->seen[ $tuple ] ) ) {
			return false;
		}

		$this->seen[ $tuple ] = true;
		return true;
	}

	public function purge_expired(): int {
		$count      = count( $this->seen );
		$this->seen = array();
		return $count;
	}
}
