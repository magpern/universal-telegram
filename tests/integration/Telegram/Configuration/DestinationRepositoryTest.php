<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Configuration;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\InvalidDestinationException;
use WP_UnitTestCase;

final class DestinationRepositoryTest extends WP_UnitTestCase {

	private function bot_id(): int {
		$bots = new BotProfileRepository( new SchemaHealth(), new CredentialVault() );
		$bot  = $bots->create( 'Bot', 'token' );

		return $bot->id();
	}

	public function test_create_and_find_round_trip(): void {
		$repository = new DestinationRepository( new SchemaHealth() );
		$bot_id     = $this->bot_id();

		$destination = $repository->create( $bot_id, DestinationKind::SUPERGROUP, '-100123', 42, 'Topic' );

		$this->assertNotNull( $destination );
		$this->assertSame( $bot_id, $destination->bot_id() );
		$this->assertSame( 42, $destination->message_thread_id() );
		$this->assertTrue( $destination->enabled() );

		$found = $repository->find( $destination->id() );
		$this->assertNotNull( $found );
		$this->assertSame( '-100123', $found->chat_id() );
	}

	public function test_message_thread_id_is_rejected_on_a_private_destination(): void {
		$repository = new DestinationRepository( new SchemaHealth() );
		$bot_id     = $this->bot_id();

		$this->expectException( InvalidDestinationException::class );

		$repository->create( $bot_id, DestinationKind::PRIVATE, '12345', 1, 'Private chat' );
	}

	public function test_duplicate_destination_is_rejected_by_the_unique_constraint(): void {
		// message_thread_id must be non-null for this constraint to bind:
		// MySQL treats every NULL in a unique key as distinct from every
		// other NULL, so two destinations sharing the same (bot_id,
		// chat_id) with message_thread_id = NULL are not, in fact,
		// duplicates the schema itself rejects — only same-topic
		// supergroup destinations are.
		$repository = new DestinationRepository( new SchemaHealth() );
		$bot_id     = $this->bot_id();

		$first  = $repository->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', 7, 'Topic' );
		$second = $repository->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', 7, 'Topic Duplicate' );

		$this->assertNotNull( $first );
		$this->assertNull( $second );
	}

	public function test_for_bot_returns_only_that_bots_destinations(): void {
		$repository = new DestinationRepository( new SchemaHealth() );
		$bot_a      = $this->bot_id();
		$bot_b      = $this->bot_id();

		$repository->create( $bot_a, DestinationKind::PRIVATE, 'a1', null, 'A1' );
		$repository->create( $bot_a, DestinationKind::PRIVATE, 'a2', null, 'A2' );
		$repository->create( $bot_b, DestinationKind::PRIVATE, 'b1', null, 'B1' );

		$this->assertCount( 2, $repository->for_bot( $bot_a ) );
		$this->assertCount( 1, $repository->for_bot( $bot_b ) );
	}

	public function test_set_enabled_and_delete(): void {
		$repository  = new DestinationRepository( new SchemaHealth() );
		$destination = $repository->create( $this->bot_id(), DestinationKind::CHANNEL, '@channel', null, 'Channel' );

		$this->assertTrue( $repository->set_enabled( $destination->id(), false ) );
		$this->assertFalse( $repository->find( $destination->id() )->enabled() );

		$this->assertTrue( $repository->delete( $destination->id() ) );
		$this->assertNull( $repository->find( $destination->id() ) );
	}

	public function test_delete_for_bot_removes_every_destination(): void {
		$repository = new DestinationRepository( new SchemaHealth() );
		$bot_id     = $this->bot_id();

		$repository->create( $bot_id, DestinationKind::PRIVATE, 'a1', null, 'A1' );
		$repository->create( $bot_id, DestinationKind::PRIVATE, 'a2', null, 'A2' );

		$this->assertTrue( $repository->delete_for_bot( $bot_id ) );
		$this->assertCount( 0, $repository->for_bot( $bot_id ) );
	}
}
