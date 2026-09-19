<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

/**
 * ADR-0046: the outbound row is the join point for native replies — it stores an
 * opaque correlation token and is found again by (destination, replied-to Telegram id).
 */
final class OutboundMessageCorrelationTest extends WP_UnitTestCase {

	private function repository(): OutboundMessageRepository {
		return new OutboundMessageRepository( new SchemaHealth(), new CredentialVault() );
	}

	public function test_a_token_round_trips_and_defaults_to_null(): void {
		$repo = $this->repository();

		$with    = $repo->create( 1, 1, 'a', null, 'standard', null, 'ticket:12' );
		$without = $repo->create( 1, 1, 'b', null );

		$this->assertSame( 'ticket:12', $repo->find( $with->id() )->correlation_token() );
		$this->assertNull( $repo->find( $without->id() )->correlation_token() );
	}

	public function test_finder_matches_on_destination_and_telegram_message_id(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 5, 'a', null, 'standard', null, 'ticket:12' );
		$repo->mark_sent( $message->id(), 777 );

		$found = $repo->find_by_destination_and_telegram_message_id( 5, 777 );

		$this->assertNotNull( $found );
		$this->assertSame( $message->id(), $found->id() );
		$this->assertSame( 'ticket:12', $found->correlation_token() );
	}

	public function test_finder_returns_null_when_nothing_matches(): void {
		$repo    = $this->repository();
		$message = $repo->create( 1, 5, 'a', null, 'standard', null, 'ticket:12' );
		$repo->mark_sent( $message->id(), 777 );

		$this->assertNull( $repo->find_by_destination_and_telegram_message_id( 5, 778 ) );
		$this->assertNull( $repo->find_by_destination_and_telegram_message_id( 6, 777 ), 'a message id is only meaningful within its own destination' );
	}

	public function test_same_telegram_message_id_in_two_destinations_resolves_independently(): void {
		$repo = $this->repository();

		$topic_a = $repo->create( 1, 10, 'a', null, 'standard', null, 'ticket:1' );
		$topic_b = $repo->create( 1, 11, 'b', null, 'standard', null, 'ticket:2' );
		$repo->mark_sent( $topic_a->id(), 500 );
		$repo->mark_sent( $topic_b->id(), 500 );

		$this->assertSame( 'ticket:1', $repo->find_by_destination_and_telegram_message_id( 10, 500 )->correlation_token() );
		$this->assertSame( 'ticket:2', $repo->find_by_destination_and_telegram_message_id( 11, 500 )->correlation_token() );
	}

	public function test_an_unsent_message_has_no_telegram_id_and_is_never_found(): void {
		$repo = $this->repository();
		$repo->create( 1, 5, 'a', null, 'standard', null, 'ticket:12' );

		$this->assertNull( $repo->find_by_destination_and_telegram_message_id( 5, 0 ) );
	}

	public function test_message_dispatcher_threads_the_token_and_leaves_default_sends_untouched(): void {
		global $wpdb;

		$schema_health = new SchemaHealth();
		$repo          = new OutboundMessageRepository( $schema_health, new CredentialVault() );
		$dispatcher    = new MessageDispatcher( $repo, new Dispatcher( $schema_health ) );

		$dispatcher->send( 1, 1, 'plain', null );
		$dispatcher->send( 1, 1, 'correlated', null, null, false, 'ticket:99' );

		$table  = $wpdb->prefix . Migrator::OUTBOUND_MESSAGES_TABLE;
		$tokens = $wpdb->get_col( "SELECT correlation_token FROM {$table} ORDER BY id ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$this->assertSame( array( null, 'ticket:99' ), $tokens );
	}
}
