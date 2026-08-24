<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\RetentionCleanupHandler;
use UniversalTelegram\Conversations\TopicDeletionDispatcher;
use UniversalTelegram\Conversations\TopicLifecycleState;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class RetentionCleanupHandlerTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private DestinationRepository $destinations;
	private ConversationPurgeService $purge_service;
	private ConversationTopicEligibility $eligibility;
	private TopicDeletionDispatcher $topic_deletion;
	private RetentionCleanupHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$this->conversations = new ConversationRepository( $this->schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$this->messages      = new MessageRepository( $this->schema_health, new CredentialVault() );
		$this->destinations  = new DestinationRepository( $this->schema_health );
		$this->purge_service = new ConversationPurgeService( $this->conversations, $this->messages, $this->destinations );
		$this->eligibility   = new ConversationTopicEligibility( $this->conversations, $this->destinations );
		$this->topic_deletion = new TopicDeletionDispatcher( $this->conversations, new Dispatcher( $this->schema_health ) );

		$this->handler = new RetentionCleanupHandler(
			$this->conversations,
			$this->messages,
			$this->purge_service,
			$this->eligibility,
			$this->topic_deletion
		);
	}

	private function set_conversation_updated_at( int $conversation_id, int $days_ago ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_conversations';
		$wpdb->update( $table, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) - 60 ) ), array( 'id' => $conversation_id ) );
	}

	private function resolved_conversation(): \UniversalTelegram\Conversations\Conversation {
		$conversation = $this->conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$this->conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		return $this->conversations->find( $conversation->id() );
	}

	public function test_run_archives_every_resolved_conversation_and_revokes_its_secret(): void {
		$conversation = $this->resolved_conversation();

		$this->handler->run();

		$updated = $this->conversations->find( $conversation->id() );
		$this->assertSame( ConversationStatus::ARCHIVED, $updated->status() );
		$this->assertNull( $updated->secret_hash() );
	}

	public function test_run_nulls_message_bodies_thirty_days_after_archival(): void {
		$conversation = $this->resolved_conversation();
		$message      = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->handler->run();
		$this->set_conversation_updated_at( $conversation->id(), 31 );

		$this->handler->run();

		$reloaded = $this->messages->find( $message->id() );
		$this->assertNull( $reloaded->body_ciphertext() );
	}

	/**
	 * Docs/adr/0028 §4 retention table: AI draft bodies follow the exact
	 * same 30-day nulling pass as message bodies.
	 */
	public function test_run_nulls_ai_draft_bodies_thirty_days_after_archival(): void {
		global $wpdb;

		$conversation = $this->resolved_conversation();
		$table        = $wpdb->prefix . 'universal_telegram_ai_drafts';
		$wpdb->insert(
			$table,
			array(
				'draft_uuid'            => wp_generate_uuid4(),
				'conversation_id'       => $conversation->id(),
				'status'                => 'generated',
				'provider'              => 'openai',
				'model'                 => 'gpt-4o-mini',
				'prompt_policy_version' => 'v1',
				'requested_by_user_id'  => 1,
				'body_ciphertext'       => 'ciphertext-placeholder',
				'created_at'            => current_time( 'mysql', true ),
				'updated_at'            => current_time( 'mysql', true ),
			)
		);

		$this->handler->run();
		$this->set_conversation_updated_at( $conversation->id(), 31 );
		$this->handler->run();

		$body = $wpdb->get_var(
			$wpdb->prepare( "SELECT body_ciphertext FROM {$table} WHERE conversation_id = %d", $conversation->id() ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertNull( $body );
	}

	public function test_run_leaves_recently_archived_message_bodies_intact(): void {
		$conversation = $this->resolved_conversation();
		$message      = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->handler->run();

		$reloaded = $this->messages->find( $message->id() );
		$this->assertNotNull( $reloaded->body_ciphertext() );
	}

	public function test_run_deletes_ineligible_conversation_locally_without_dropping_unrelated_dest(): void {
		$conversation = $this->resolved_conversation();
		$destination  = $this->destinations->create( 1, DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );
		$this->conversations->set_destination( $conversation->id(), $destination->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->handler->run();
		$this->set_conversation_updated_at( $conversation->id(), 91 );

		$this->handler->run();

		$this->assertNull( $this->conversations->find( $conversation->id() ) );
		$this->assertNull( $this->messages->find( $message->id() ) );
		// Ineligible (topic_creation_state never created) — dest row retained.
		$this->assertNotNull( $this->destinations->find( $destination->id() ) );
	}

	public function test_run_queues_remote_delete_for_eligible_conversation_topic(): void {
		$conversation = $this->resolved_conversation();
		$destination  = $this->destinations->create( 1, DestinationKind::SUPERGROUP, '-100elig-ret', 66, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 66, $destination->id() );

		$this->handler->run();
		$this->set_conversation_updated_at( $conversation->id(), 91 );
		$this->handler->run();

		$fresh = $this->conversations->find( $conversation->id() );
		$this->assertNotNull( $fresh );
		$this->assertSame( TopicLifecycleState::DELETE_PENDING, $fresh->topic_lifecycle_state() );
		$this->assertNotNull( $this->destinations->find( $destination->id() ) );
	}

	public function test_run_shared_destination_purges_conversation_but_keeps_dest(): void {
		$conversation = $this->resolved_conversation();
		$destination  = $this->destinations->create( 1, DestinationKind::SUPERGROUP, '-100share-ret', 77, 'Topic' );
		$this->conversations->mark_topic_created( $conversation->id(), 77, $destination->id() );
		$this->handler->run();

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_conversations';
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX destination_id" );
		$other = $this->conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$wpdb->update(
			$table,
			array(
				'destination_id'        => $destination->id(),
				'telegram_topic_id'     => 77,
				'topic_creation_state'  => 'created',
				'topic_lifecycle_state' => TopicLifecycleState::ACTIVE,
				'status'                => ConversationStatus::ARCHIVED,
			),
			array( 'id' => $other->id() ),
			array( '%d', '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		$this->set_conversation_updated_at( $conversation->id(), 91 );
		$this->handler->run();

		$this->assertNull( $this->conversations->find( $conversation->id() ) );
		$this->assertNotNull( $this->destinations->find( $destination->id() ) );
		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY destination_id (destination_id)" );
	}

	public function test_run_is_idempotent_on_rerun_against_already_cleaned_data(): void {
		$conversation = $this->resolved_conversation();

		$this->handler->run();
		$this->set_conversation_updated_at( $conversation->id(), 91 );
		$this->handler->run();

		// A second pass over already-deleted/already-archived data must not
		// error or change anything further.
		$this->handler->run();

		$this->assertNull( $this->conversations->find( $conversation->id() ) );
	}

	public function test_run_never_touches_a_conversation_that_is_not_yet_resolved(): void {
		$conversation = $this->conversations->create( 'uuid-untouched', 'hash', 1, null );

		$this->handler->run();

		$found = $this->conversations->find( $conversation->id() );
		$this->assertSame( ConversationStatus::NEW, $found->status() );
		$this->assertNotNull( $found->secret_hash() );
	}

	public function test_run_auto_resolves_and_archives_an_inactive_open_conversation_in_one_pass(): void {
		$conversation = $this->conversations->create( 'uuid-inactive-open', 'hash', 1, null );
		$this->conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$this->set_conversation_updated_at( $conversation->id(), 31 );

		$this->handler->run();

		$updated = $this->conversations->find( $conversation->id() );
		$this->assertSame( ConversationStatus::ARCHIVED, $updated->status() );
		$this->assertNull( $updated->secret_hash() );
	}

	public function test_run_leaves_a_recently_active_open_conversation_untouched(): void {
		$conversation = $this->conversations->create( 'uuid-recent-open', 'hash', 1, null );
		$this->conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );

		$this->handler->run();

		$found = $this->conversations->find( $conversation->id() );
		$this->assertSame( ConversationStatus::OPEN, $found->status() );
		$this->assertNotNull( $found->secret_hash() );
	}

	public function test_run_auto_resolves_inactive_waiting_for_visitor_and_waiting_for_operator(): void {
		$waiting_for_visitor = $this->conversations->create( 'uuid-wfv', 'hash', 1, null );
		$this->conversations->transition( $waiting_for_visitor->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$this->conversations->transition( $waiting_for_visitor->id(), ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_VISITOR );
		$this->set_conversation_updated_at( $waiting_for_visitor->id(), 31 );

		$waiting_for_operator = $this->conversations->create( 'uuid-wfo', 'hash', 1, null );
		$this->conversations->transition( $waiting_for_operator->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$this->conversations->transition( $waiting_for_operator->id(), ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_OPERATOR );
		$this->set_conversation_updated_at( $waiting_for_operator->id(), 31 );

		$this->handler->run();

		$this->assertSame( ConversationStatus::ARCHIVED, $this->conversations->find( $waiting_for_visitor->id() )->status() );
		$this->assertSame( ConversationStatus::ARCHIVED, $this->conversations->find( $waiting_for_operator->id() )->status() );
	}

	public function test_run_never_reopens_or_reprocesses_an_already_archived_conversation(): void {
		$conversation = $this->resolved_conversation();
		$this->handler->run();
		$this->assertSame( ConversationStatus::ARCHIVED, $this->conversations->find( $conversation->id() )->status() );

		// Make the archived row look "inactive" too — the inactivity step
		// must never select it, since it is not in (open, waiting_for_visitor,
		// waiting_for_operator).
		$this->set_conversation_updated_at( $conversation->id(), 31 );

		$this->handler->run();

		$this->assertSame( ConversationStatus::ARCHIVED, $this->conversations->find( $conversation->id() )->status() );
	}

	public function test_run_auto_resolves_and_archives_a_stale_new_conversation_via_the_legal_two_hop_chain(): void {
		// M06.3.1, ADR-0025: `new` now occupies the owner_active_slot
		// concurrency index, so a conversation stuck in `new` forever (e.g.
		// its topic creation permanently failed) must eventually free that
		// slot too — via the two individually-valid transitions
		// new->open->resolved, then the existing resolved->archived sweep,
		// in the same pass.
		$conversation = $this->conversations->create( 'uuid-new-inactive', 'hash', 1, null, null, 555, 'Kim' );
		$this->set_conversation_updated_at( $conversation->id(), 31 );

		$this->handler->run();

		$this->assertSame( ConversationStatus::ARCHIVED, $this->conversations->find( $conversation->id() )->status() );
	}

	public function test_run_leaves_a_recently_created_new_conversation_untouched(): void {
		$conversation = $this->conversations->create( 'uuid-new-fresh', 'hash', 1, null );

		$this->handler->run();

		$this->assertSame( ConversationStatus::NEW, $this->conversations->find( $conversation->id() )->status() );
	}

	public function test_custom_inactivity_days_is_honored(): void {
		$handler = new RetentionCleanupHandler(
			$this->conversations,
			$this->messages,
			$this->purge_service,
			$this->eligibility,
			$this->topic_deletion,
			30,
			90,
			10
		);

		$conversation = $this->conversations->create( 'uuid-custom-inactivity', 'hash', 1, null );
		$this->conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$this->set_conversation_updated_at( $conversation->id(), 11 );

		$handler->run();

		$this->assertSame( ConversationStatus::ARCHIVED, $this->conversations->find( $conversation->id() )->status() );
	}
}
