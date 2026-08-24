<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Configuration;

use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use WP_UnitTestCase;

final class BotProfileRepositoryTest extends WP_UnitTestCase {

	private function repository(): BotProfileRepository {
		return new BotProfileRepository( new SchemaHealth(), new CredentialVault() );
	}

	public function test_create_round_trips_a_bot_profile_with_ciphertext_never_equal_to_plaintext(): void {
		$repository = $this->repository();
		$plaintext  = '123456:AAAA-real-token-looking-value';

		$bot = $repository->create( 'My Bot', $plaintext );

		$this->assertNotNull( $bot );
		$this->assertSame( 'My Bot', $bot->name() );
		$this->assertNotSame( $plaintext, $bot->token_ciphertext() );
		$this->assertSame( 'unregistered', $bot->webhook_registration_state() );
		$this->assertSame( BotStatus::UNCONFIGURED, $bot->status() );
		$this->assertFalse( $bot->has_pending_secret() );

		$decrypted = $repository->decrypt_token( $bot );
		$this->assertSame( CredentialState::AVAILABLE, $decrypted->state() );
		$this->assertSame( $plaintext, $decrypted->plaintext() );

		$found = $repository->find( $bot->id() );
		$this->assertNotNull( $found );
		$this->assertSame( $bot->bot_uuid(), $found->bot_uuid() );

		$found_by_uuid = $repository->find_by_uuid( $bot->bot_uuid() );
		$this->assertNotNull( $found_by_uuid );
		$this->assertSame( $bot->id(), $found_by_uuid->id() );
	}

	public function test_replace_token_is_unconditional_and_performs_no_remote_validation(): void {
		$repository = $this->repository();
		$bot        = $repository->create( 'Bot', 'old-token' );
		$this->assertNotNull( $bot );

		$this->assertTrue( $repository->replace_token( $bot->id(), 'new-token' ) );

		$updated   = $repository->find( $bot->id() );
		$decrypted = $repository->decrypt_token( $updated );
		$this->assertSame( 'new-token', $decrypted->plaintext() );
	}

	public function test_pending_secret_lifecycle_is_a_pure_data_operation(): void {
		$repository = $this->repository();
		$bot        = $repository->create( 'Bot', 'token' );
		$this->assertNotNull( $bot );

		$this->assertTrue( $repository->start_pending_secret( $bot->id(), 'new-pending-secret' ) );

		$with_pending = $repository->find( $bot->id() );
		$this->assertTrue( $with_pending->has_pending_secret() );

		$pending_decrypted = $repository->decrypt_pending_webhook_secret( $with_pending );
		$this->assertNotNull( $pending_decrypted );
		$this->assertSame( 'new-pending-secret', $pending_decrypted->plaintext() );

		$this->assertTrue( $repository->promote_pending_secret( $bot->id() ) );

		$promoted = $repository->find( $bot->id() );
		$this->assertFalse( $promoted->has_pending_secret() );
		$this->assertSame( 'registered', $promoted->webhook_registration_state() );

		$active_decrypted = $repository->decrypt_webhook_secret( $promoted );
		$this->assertSame( 'new-pending-secret', $active_decrypted->plaintext() );
	}

	public function test_discard_pending_secret_leaves_active_and_state_untouched(): void {
		$repository = $this->repository();
		$bot        = $repository->create( 'Bot', 'token' );
		$this->assertNotNull( $bot );

		$repository->start_pending_secret( $bot->id(), 'pending' );
		$repository->mark_uncertain( $bot->id() );

		$this->assertTrue( $repository->discard_pending_secret( $bot->id() ) );

		$after = $repository->find( $bot->id() );
		$this->assertFalse( $after->has_pending_secret() );
		$this->assertSame( 'uncertain', $after->webhook_registration_state() );
	}

	public function test_count_stale_unresolved_registrations_reads_the_correct_timestamp_per_case(): void {
		$repository = $this->repository();

		global $wpdb;

		// Case 1: uncertain rotation (pending secret set), stale by pending_since.
		$rotation = $repository->create( 'Rotation Bot', 'token' );
		$repository->start_pending_secret( $rotation->id(), 'pending' );
		$repository->mark_uncertain( $rotation->id() );
		$repository->touch_last_attempt( $rotation->id() );

		$stale_time = gmdate( 'Y-m-d H:i:s', time() - ( 48 * HOUR_IN_SECONDS ) );
		$table      = $wpdb->prefix . 'universal_telegram_bots';
		$wpdb->update( $table, array( 'webhook_secret_pending_since' => $stale_time ), array( 'id' => $rotation->id() ) );

		// Case 2: uncertain initial registration (no pending secret), stale by last_attempt_at.
		$registration = $repository->create( 'Registration Bot', 'token' );
		$repository->mark_uncertain( $registration->id() );
		$repository->touch_last_attempt( $registration->id() );
		$wpdb->update( $table, array( 'webhook_last_attempt_at' => $stale_time ), array( 'id' => $registration->id() ) );

		// Case 3: uncertain but recent — must not count.
		$recent = $repository->create( 'Recent Bot', 'token' );
		$repository->mark_uncertain( $recent->id() );
		$repository->touch_last_attempt( $recent->id() );

		// Case 4: registered — must not count regardless of age.
		$registered = $repository->create( 'Registered Bot', 'token' );
		$repository->mark_registered( $registered->id() );
		$wpdb->update(
			$table,
			array(
				'webhook_registered_at' => $stale_time,
				'updated_at'            => $stale_time,
			),
			array( 'id' => $registered->id() )
		);

		$this->assertSame( 2, $repository->count_stale_unresolved_registrations( 24 ) );
	}

	public function test_delete_removes_the_row(): void {
		$repository = $this->repository();
		$bot        = $repository->create( 'Bot', 'token' );
		$this->assertNotNull( $bot );

		$this->assertTrue( $repository->delete( $bot->id() ) );
		$this->assertNull( $repository->find( $bot->id() ) );
	}

	/**
	 * CHANGED_ACTION (M11A, docs/plans/m11a-visitor-activity-digests-plan-v1.md
	 * §3.1) must fire from every successful mutating write in this
	 * repository — create, any field update, and delete — so a listener
	 * such as Automations\Digest\DigestEligibility is notified regardless
	 * of which caller (Bots tab, setup wizard, webhook registration,
	 * cleanup) triggered the write.
	 */
	public function test_changed_action_fires_on_create_update_and_delete(): void {
		$repository = $this->repository();
		$fired      = 0;
		$listener   = function () use ( &$fired ) {
			++$fired;
		};

		add_action( BotProfileRepository::CHANGED_ACTION, $listener );

		$bot = $repository->create( 'Bot', 'token' );
		$this->assertSame( 1, $fired );

		$repository->set_status( $bot->id(), BotStatus::ACTIVE );
		$this->assertSame( 2, $fired );

		$repository->mark_unregistered( $bot->id() );
		$this->assertSame( 3, $fired );

		$repository->delete( $bot->id() );
		$this->assertSame( 4, $fired );

		remove_action( BotProfileRepository::CHANGED_ACTION, $listener );
	}
}
