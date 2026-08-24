<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\TopicLifecycleState;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class ConversationTopicEligibilityTest extends WP_UnitTestCase {

	private function repos(): array {
		$schema        = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema, $vault, new VisitorTokenGenerator() );
		$destinations  = new DestinationRepository( $schema );

		return array(
			$conversations,
			$destinations,
			new ConversationTopicEligibility( $conversations, $destinations ),
		);
	}

	private function archived_with_topic( ConversationRepository $conversations, DestinationRepository $destinations, string $chat_id = '', int $thread = 57 ): array {
		if ( '' === $chat_id ) {
			$chat_id = '-100elig-' . wp_generate_uuid4();
		}

		$conversation = $conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$destination  = $destinations->create( 1, DestinationKind::SUPERGROUP, $chat_id, $thread, 'Topic' );
		$this->assertNotNull( $destination );
		$conversations->mark_topic_created( $conversation->id(), $thread, $destination->id() );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );

		return array( $conversations->find( $conversation->id() ), $destination );
	}

	public function test_eligible_plugin_created_topic_passes(): void {
		[ $conversations, $destinations, $eligibility ] = $this->repos();
		[ $conversation ]                               = $this->archived_with_topic( $conversations, $destinations );

		$this->assertTrue( $eligibility->is_remote_deletable( $conversation ) );
		$this->assertSame( $conversation->destination_id(), $eligibility->destination_id_for_purge( $conversation ) );
	}

	public function test_general_null_thread_is_ineligible(): void {
		[ $conversations, $destinations, $eligibility ] = $this->repos();
		$conversation                                   = $conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$destination                                    = $destinations->create( 1, DestinationKind::SUPERGROUP, '-100g', null, 'General' );
		$conversations->mark_topic_created( $conversation->id(), 2, $destination->id() );
		// Force mismatched / general-style dest: recreate properly.
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );
		$fresh = $conversations->find( $conversation->id() );

		$this->assertFalse( $eligibility->is_remote_deletable( $fresh ) );
	}

	public function test_thread_id_one_is_ineligible(): void {
		[ $conversations, $destinations, $eligibility ] = $this->repos();
		$conversation                                   = $conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$destination                                    = $destinations->create( 1, DestinationKind::SUPERGROUP, '-100one', 1, 'General topic' );
		$conversations->mark_topic_created( $conversation->id(), 1, $destination->id() );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );

		$this->assertFalse( $eligibility->is_remote_deletable( $conversations->find( $conversation->id() ) ) );
	}

	public function test_shared_destination_id_is_ineligible_and_purge_keeps_dest(): void {
		[ $conversations, $destinations, $eligibility ] = $this->repos();
		[ $first, $destination ]                        = $this->archived_with_topic( $conversations, $destinations, '-100share-' . wp_generate_uuid4(), 90 );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_conversations';
		// Simulate pre-repair shared ownership by dropping UNIQUE briefly.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
		try {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX destination_id" );

			$second = $conversations->create( wp_generate_uuid4(), 'hash', 1, null );
			$wpdb->update(
				$table,
				array(
					'destination_id'        => $destination->id(),
					'telegram_topic_id'     => 90,
					'topic_creation_state'  => 'created',
					'topic_lifecycle_state' => TopicLifecycleState::ACTIVE,
					'status'                => ConversationStatus::ARCHIVED,
				),
				array( 'id' => $second->id() ),
				array( '%d', '%d', '%s', '%s', '%s' ),
				array( '%d' )
			);

			$first_fresh = $conversations->find( $first->id() );
			$this->assertFalse( $eligibility->is_remote_deletable( $first_fresh ) );
			$this->assertNull( $eligibility->destination_id_for_purge( $first_fresh ) );

			$purge = new ConversationPurgeService( $conversations, new MessageRepository( new SchemaHealth(), new CredentialVault() ), $destinations );
			$purge->purge( $first->id(), null );

			$this->assertNotNull( $destinations->find( $destination->id() ) );
			$this->assertNull( $conversations->find( $first->id() ) );
		} finally {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY destination_id (destination_id)" );
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_open_conversation_is_ineligible(): void {
		[ $conversations, $destinations, $eligibility ] = $this->repos();
		$conversation                                   = $conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$destination                                    = $destinations->create( 1, DestinationKind::SUPERGROUP, '-100open', 44, 'Open' );
		$conversations->mark_topic_created( $conversation->id(), 44, $destination->id() );

		$this->assertFalse( $eligibility->is_remote_deletable( $conversations->find( $conversation->id() ) ) );
	}
}
