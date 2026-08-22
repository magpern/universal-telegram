<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use WP_UnitTestCase;

/**
 * Claim/lease protocol coverage for try_claim_for_sending() (M06.2
 * corrective plan v2 §3.1, ADR-0023 amendment): at most one concurrent
 * claimant, crash recovery via lease expiry, and permanent exclusion of
 * terminal states.
 */
final class OutboundMessageRepositoryTest extends WP_UnitTestCase {

	private function repository(): OutboundMessageRepository {
		return new OutboundMessageRepository( new SchemaHealth(), new CredentialVault() );
	}

	public function test_try_claim_for_sending_concurrent_race_exactly_one_wins(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 1, 'hello', null );

		$first  = $repo->try_claim_for_sending( $message->id(), 15 );
		$second = $repo->try_claim_for_sending( $message->id(), 15 );

		$this->assertTrue( $first );
		$this->assertFalse( $second );

		$after = $repo->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::SENDING, $after->status() );
		$this->assertSame( 1, $after->attempt_count() );
	}

	public function test_try_claim_for_sending_reclaims_only_after_the_lease_expires(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 1, 'hello', null );

		$this->assertTrue( $repo->try_claim_for_sending( $message->id(), 15 ) );
		$this->assertFalse( $repo->try_claim_for_sending( $message->id(), 15 ) );

		// Simulate a crashed prior claimant: the lease has already expired.
		global $wpdb;
		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$wpdb->update(
			$table,
			array( 'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ),
			array( 'id' => $message->id() )
		);

		$this->assertTrue( $repo->try_claim_for_sending( $message->id(), 15 ) );

		$after = $repo->find( $message->id() );
		$this->assertSame( 2, $after->attempt_count() );
	}

	public function test_try_claim_for_sending_never_reclaims_a_terminal_status(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 1, 'hello', null );

		$repo->try_claim_for_sending( $message->id(), 15 );
		$repo->mark_sent( $message->id(), 42 );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$wpdb->update(
			$table,
			array( 'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 100 ) ),
			array( 'id' => $message->id() )
		);

		$this->assertFalse( $repo->try_claim_for_sending( $message->id(), 15 ) );
	}

	public function test_try_claim_for_sending_matches_pending_and_retry_scheduled_regardless_of_lease(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 1, 'hello', null );

		$this->assertTrue( $repo->try_claim_for_sending( $message->id(), 15 ) );

		$repo->mark_retry_scheduled( $message->id() );

		$this->assertTrue( $repo->try_claim_for_sending( $message->id(), 15 ) );

		$after = $repo->find( $message->id() );
		$this->assertSame( 2, $after->attempt_count() );
	}

	public function test_release_claim_for_retry_allows_immediate_reclaim(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 1, 'hello', null );

		$repo->try_claim_for_sending( $message->id(), 15 );
		$repo->release_claim_for_retry( $message->id() );

		$after = $repo->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::RETRY_SCHEDULED, $after->status() );
		$this->assertNull( $after->claim_expires_at() );

		$this->assertTrue( $repo->try_claim_for_sending( $message->id(), 15 ) );
	}
}
