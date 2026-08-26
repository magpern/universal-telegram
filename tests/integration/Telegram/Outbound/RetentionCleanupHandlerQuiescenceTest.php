<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\RetentionCleanupHandler;
use WP_UnitTestCase;

/**
 * ADR-0040 §5: the two message-retention passes skip the whole cycle
 * outside idle, never marked failed; the new 30-day deferred-update
 * cleanup pass is unconditional and keeps running regardless of state,
 * but only ever touches replayed rows — never an unreplayed one.
 */
final class RetentionCleanupHandlerQuiescenceTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;
	private DeferredUpdateRepository $deferred;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$schema_health  = new SchemaHealth();
		$this->deferred = new DeferredUpdateRepository( $schema_health, new CredentialVault() );
		$this->gate     = new QuiescenceGate( $schema_health, $this->deferred, new QuiescenceTransitionRepository() );
	}

	private function age_message( int $id, int $days_old ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'universal_telegram_outbound_messages',
			array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - ( $days_old * DAY_IN_SECONDS ) ) ),
			array( 'id' => $id )
		);
	}

	private function insert_deferred_row( int $update_id, ?string $replayed_at ): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;
		$wpdb->insert(
			$table,
			array(
				'bot_id'             => 1,
				'update_id'          => $update_id,
				'update_type'        => 'message',
				'payload_ciphertext' => 'irrelevant',
				'received_at'        => gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ),
				'replayed_at'        => $replayed_at,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	public function test_message_retention_passes_skip_the_cycle_outside_idle(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$messages      = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'old content', null );
		$messages->mark_sent( $message->id(), 111 );
		$this->age_message( $message->id(), 31 );

		$this->gate->enter();

		$handler = new RetentionCleanupHandler( $messages, 30, 90, $this->gate, $this->deferred );
		$handler->run();

		$after = $messages->find( $message->id() );
		$this->assertNotSame( OutboundMessageStatus::PURGED, $after->status(), 'The message-retention pass must not run outside idle.' );
	}

	public function test_deferred_update_cleanup_pass_runs_regardless_of_quiescence_state(): void {
		$this->insert_deferred_row( 1001, gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) );

		$this->gate->enter();

		$schema_health = new SchemaHealth();
		$handler       = new RetentionCleanupHandler( new OutboundMessageRepository( $schema_health, new CredentialVault() ), 30, 90, $this->gate, $this->deferred );
		$handler->run();

		$this->assertFalse( $this->deferred->exists( 1, 1001 ), 'A replayed row older than 30 days must be deleted regardless of quiescence state.' );
	}

	public function test_deferred_update_cleanup_pass_never_deletes_an_unreplayed_row(): void {
		$this->insert_deferred_row( 1002, null );

		$schema_health = new SchemaHealth();
		$handler       = new RetentionCleanupHandler( new OutboundMessageRepository( $schema_health, new CredentialVault() ), 30, 90, $this->gate, $this->deferred );
		$handler->run();

		$this->assertTrue( $this->deferred->exists( 1, 1002 ), 'An unreplayed row must never be auto-deleted, regardless of age.' );
	}
}
