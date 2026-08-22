<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationOutboundHandler;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

final class ConversationOutboundHandlerTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private CredentialVault $vault;
	private ConversationRepository $conversations;
	private MessageRepository $messages;
	private BotProfileRepository $bots;
	private DestinationRepository $destinations;
	private OutboundMessageRepository $outbound_messages;
	private ConversationOutboundHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health     = new SchemaHealth();
		$this->vault             = new CredentialVault();
		$this->conversations     = new ConversationRepository( $this->schema_health, new CredentialVault() );
		$this->messages          = new MessageRepository( $this->schema_health, $this->vault );
		$this->bots              = new BotProfileRepository( $this->schema_health, $this->vault );
		$this->destinations      = new DestinationRepository( $this->schema_health );
		$this->outbound_messages = new OutboundMessageRepository( $this->schema_health, $this->vault );

		$this->handler = new ConversationOutboundHandler(
			$this->messages,
			$this->conversations,
			$this->outbound_messages,
			new Dispatcher( $this->schema_health )
		);
	}

	private function job( int $message_id, int $conversation_id ): array {
		return array(
			'job_id'   => 'job-' . $message_id,
			'job_type' => ConversationOutboundHandler::JOB_TYPE,
			'attempt'  => 1,
			'payload'  => array(
				'conversation_message_id' => $message_id,
				'conversation_id'         => $conversation_id,
			),
		);
	}

	public function test_message_is_stored_immediately_regardless_of_topic_state(): void {
		$bot          = $this->bots->create( 'Bot', 'token' );
		$conversation = $this->conversations->create( 'uuid-store', 'hash', $bot->id(), null );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Hello' );

		$this->assertNotNull( $message );
		$this->assertSame( 'stored', $message->delivery_state() );
		$this->assertSame( 'Hello', $this->messages->decrypt( $this->messages->find( $message->id() ) ) );
	}

	public function test_handle_job_sends_and_marks_routed_once_the_topic_is_created(): void {
		$bot         = $this->bots->create( 'Bot', 'token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );

		$conversation = $this->conversations->create( 'uuid-created', 'hash', $bot->id(), null );
		$this->conversations->mark_topic_created( $conversation->id(), 55, $destination->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Route me' );

		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$updated = $this->messages->find( $message->id() );
		$this->assertSame( 'sent', $updated->delivery_state() );
		$this->assertNotNull( $updated->outbound_message_uuid() );

		$outbound = $this->outbound_messages->find_by_uuid( $updated->outbound_message_uuid() );
		$this->assertNotNull( $outbound );
		$this->assertSame( $bot->id(), $outbound->bot_id() );
		$this->assertSame( $destination->id(), $outbound->destination_id() );
	}

	public function test_handle_job_reschedules_while_the_topic_is_still_pending(): void {
		$bot          = $this->bots->create( 'Bot', 'token' );
		$conversation = $this->conversations->create( 'uuid-pending', 'hash', $bot->id(), null );
		$this->conversations->try_begin_topic_creation( $conversation->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Waiting' );

		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$this->assertSame( 'stored', $this->messages->find( $message->id() )->delivery_state() );

		$scheduled = as_get_scheduled_actions(
			array(
				'hook'  => WorkerRunner::HOOK,
				'group' => WorkerRunner::GROUP,
			)
		);
		$this->assertNotEmpty( $scheduled );
	}

	public function test_handle_job_marks_failed_immediately_once_topic_creation_itself_failed(): void {
		$bot          = $this->bots->create( 'Bot', 'token' );
		$conversation = $this->conversations->create( 'uuid-topic-failed', 'hash', $bot->id(), null );
		$this->conversations->try_begin_topic_creation( $conversation->id() );
		$this->conversations->mark_topic_failed( $conversation->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Never delivered' );

		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$this->assertSame( 'failed', $this->messages->find( $message->id() )->delivery_state() );
	}

	public function test_handle_job_marks_failed_once_the_bounded_wait_is_exceeded(): void {
		$bot          = $this->bots->create( 'Bot', 'token' );
		$conversation = $this->conversations->create( 'uuid-timeout', 'hash', $bot->id(), null );
		$this->conversations->try_begin_topic_creation( $conversation->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Stale' );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_conversation_messages';
		$wpdb->update( $table, array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 301 ) ), array( 'id' => $message->id() ) );

		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$updated = $this->messages->find( $message->id() );
		$this->assertSame( 'failed', $updated->delivery_state() );
		// Never silently dropped: the row and its ciphertext remain.
		$this->assertNotNull( $updated->body_ciphertext() );
	}

	public function test_first_message_gets_a_one_time_display_name_context_header(): void {
		$bot         = $this->bots->create( 'Bot', 'token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );

		$conversation = $this->conversations->create( 'abcdef01-2345-4678-9abc-def012345678', 'hash', $bot->id(), null );
		$this->conversations->store_display_name( $conversation, 'Alice' );
		$conversation = $this->conversations->find( $conversation->id() );
		$this->conversations->mark_topic_created( $conversation->id(), 55, $destination->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Hello there' );

		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$updated  = $this->messages->find( $message->id() );
		$outbound = $this->outbound_messages->find_by_uuid( $updated->outbound_message_uuid() );
		$body     = $this->outbound_messages->decrypt_body( $outbound )->plaintext();

		$this->assertSame( "[Alice · abcdef01]\nHello there", $body );
	}

	public function test_a_later_message_never_repeats_the_context_header(): void {
		$bot         = $this->bots->create( 'Bot', 'token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );

		$conversation = $this->conversations->create( 'abcdef02-2345-4678-9abc-def012345678', 'hash', $bot->id(), null );
		$this->conversations->store_display_name( $conversation, 'Bob' );
		$conversation = $this->conversations->find( $conversation->id() );
		$this->conversations->mark_topic_created( $conversation->id(), 55, $destination->id() );

		$first  = $this->messages->create( $conversation->id(), 'visitor', 'First' );
		$second = $this->messages->create( $conversation->id(), 'visitor', 'Second' );

		$this->handler->handle_job( $this->job( $first->id(), $conversation->id() ) );
		$this->handler->handle_job( $this->job( $second->id(), $conversation->id() ) );

		$second_updated  = $this->messages->find( $second->id() );
		$second_outbound = $this->outbound_messages->find_by_uuid( $second_updated->outbound_message_uuid() );
		$second_body     = $this->outbound_messages->decrypt_body( $second_outbound )->plaintext();

		$this->assertSame( 'Second', $second_body );
	}

	public function test_first_message_with_no_stored_display_name_carries_no_header(): void {
		$bot         = $this->bots->create( 'Bot', 'token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 55, 'Topic' );

		$conversation = $this->conversations->create( 'uuid-no-name-header', 'hash', $bot->id(), null );
		$this->conversations->mark_topic_created( $conversation->id(), 55, $destination->id() );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Hello' );

		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$updated  = $this->messages->find( $message->id() );
		$outbound = $this->outbound_messages->find_by_uuid( $updated->outbound_message_uuid() );
		$body     = $this->outbound_messages->decrypt_body( $outbound )->plaintext();

		$this->assertSame( 'Hello', $body );
	}

	public function test_handle_job_on_an_already_resolved_message_is_a_noop(): void {
		$bot          = $this->bots->create( 'Bot', 'token' );
		$conversation = $this->conversations->create( 'uuid-resolved', 'hash', $bot->id(), null );

		$message = $this->messages->create( $conversation->id(), 'visitor', 'Done' );
		$this->messages->mark_delivery_failed( $message->id() );

		// Re-running the job must not throw or change state further.
		$this->handler->handle_job( $this->job( $message->id(), $conversation->id() ) );

		$this->assertSame( 'failed', $this->messages->find( $message->id() )->delivery_state() );
	}
}
