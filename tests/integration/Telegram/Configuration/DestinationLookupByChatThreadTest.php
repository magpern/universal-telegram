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
use WP_UnitTestCase;

/**
 * ADR-0046: a chat id alone never identifies a destination — the unique key is
 * (bot_id, chat_id, message_thread_id).
 */
final class DestinationLookupByChatThreadTest extends WP_UnitTestCase {

	private function bot_id( string $name = 'Bot' ): int {
		return ( new BotProfileRepository( new SchemaHealth(), new CredentialVault() ) )->create( $name, 'token' )->id();
	}

	public function test_topics_of_one_supergroup_resolve_to_their_own_destination(): void {
		$repo   = new DestinationRepository( new SchemaHealth() );
		$bot_id = $this->bot_id();

		$topic_a = $repo->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$topic_b = $repo->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', 12, 'Sales' );

		$this->assertSame( $topic_a->id(), $repo->find_by_bot_chat_thread( $bot_id, '-1001', 11 )->id() );
		$this->assertSame( $topic_b->id(), $repo->find_by_bot_chat_thread( $bot_id, '-1001', 12 )->id() );
		$this->assertNull( $repo->find_by_bot_chat_thread( $bot_id, '-1001', 13 ) );
	}

	public function test_a_null_thread_matches_only_a_destination_without_a_topic(): void {
		$repo   = new DestinationRepository( new SchemaHealth() );
		$bot_id = $this->bot_id();

		$topic   = $repo->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );
		$general = $repo->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', null, 'General' );

		$this->assertSame( $general->id(), $repo->find_by_bot_chat_thread( $bot_id, '-1001', null )->id() );
		$this->assertSame( $topic->id(), $repo->find_by_bot_chat_thread( $bot_id, '-1001', 11 )->id() );
	}

	public function test_a_null_thread_never_falls_back_to_a_topic_destination(): void {
		$repo   = new DestinationRepository( new SchemaHealth() );
		$bot_id = $this->bot_id();

		$repo->create( $bot_id, DestinationKind::SUPERGROUP, '-1001', 11, 'Support' );

		$this->assertNull( $repo->find_by_bot_chat_thread( $bot_id, '-1001', null ) );
	}

	public function test_lookup_is_scoped_to_the_bot(): void {
		$repo  = new DestinationRepository( new SchemaHealth() );
		$bot_a = $this->bot_id( 'A' );
		$bot_b = $this->bot_id( 'B' );

		$repo->create( $bot_a, DestinationKind::PRIVATE, '555', null, 'DM' );

		$this->assertNotNull( $repo->find_by_bot_chat_thread( $bot_a, '555', null ) );
		$this->assertNull( $repo->find_by_bot_chat_thread( $bot_b, '555', null ) );
	}
}
