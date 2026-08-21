<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use RuntimeException;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\TopicCreationHandler;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class TopicCreationHandlerTest extends WP_UnitTestCase {

	/**
	 * @var array<int, callable>
	 */
	private array $filters_to_remove = array();

	protected function tearDown(): void {
		foreach ( $this->filters_to_remove as $callback ) {
			remove_filter( 'pre_http_request', $callback );
		}
		$this->filters_to_remove = array();

		parent::tearDown();
	}

	private function fake_telegram_response( int $status, array $body ): void {
		$callback = static function () use ( $status, $body ) {
			return array(
				'response' => array( 'code' => $status ),
				'body'     => wp_json_encode( $body ),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	private function job( int $conversation_id, int $attempt = 1 ): array {
		return array(
			'job_id'   => 'job-' . $conversation_id,
			'job_type' => TopicCreationHandler::JOB_TYPE,
			'attempt'  => $attempt,
			'payload'  => array( 'conversation_id' => $conversation_id ),
		);
	}

	private function handler( ConversationRepository $conversations, BotProfileRepository $bots, DestinationRepository $destinations ): TopicCreationHandler {
		return new TopicCreationHandler(
			$conversations,
			$bots,
			new ChatProfileResolver( $bots, $destinations ),
			$destinations,
			new TelegramApiClient(),
			new RetryPolicy()
		);
	}

	public function test_success_creates_the_destination_row_marks_the_topic_created_and_opens_the_conversation(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'Support Bot', 'token' );
		$destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $conversations->create( 'uuid-success', 'hash', $bot->id(), null );
		$conversations->try_begin_topic_creation( $conversation->id() );

		$this->fake_telegram_response(
			200,
			array(
				'ok'     => true,
				'result' => array( 'message_thread_id' => 77 ),
			)
		);

		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $conversation->id() ) );

		$updated = $conversations->find( $conversation->id() );
		$this->assertSame( 'created', $updated->topic_creation_state() );
		$this->assertSame( 77, $updated->telegram_topic_id() );
		$this->assertNotNull( $updated->destination_id() );
		$this->assertSame( 'open', $updated->status() );

		$destination = $destinations->find( $updated->destination_id() );
		$this->assertSame( 77, $destination->message_thread_id() );
	}

	public function test_a_job_for_a_conversation_no_longer_pending_is_a_noop(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot          = $bots->create( 'Support Bot', 'token' );
		$conversation = $conversations->create( 'uuid-not-pending', 'hash', $bot->id(), null );
		// topic_creation_state is still 'none' — the compare-and-set was
		// never won, so no job should have been enqueued for this in
		// production; this exercises the handler's own defensive check.

		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $conversation->id() ) );

		$this->assertSame( 'none', $conversations->find( $conversation->id() )->topic_creation_state() );
	}

	public function test_failure_below_the_retry_ceiling_rethrows_and_leaves_state_pending(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'Support Bot', 'token' );
		$destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $conversations->create( 'uuid-retry', 'hash', $bot->id(), null );
		$conversations->try_begin_topic_creation( $conversation->id() );

		$this->fake_telegram_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request',
			)
		);

		$this->expectException( RuntimeException::class );

		try {
			$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $conversation->id(), 1 ) );
		} finally {
			$this->assertSame( 'pending', $conversations->find( $conversation->id() )->topic_creation_state() );
		}
	}

	public function test_failure_at_the_retry_ceiling_marks_the_topic_failed_without_throwing(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'Support Bot', 'token' );
		$destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$conversation = $conversations->create( 'uuid-exhausted', 'hash', $bot->id(), null );
		$conversations->try_begin_topic_creation( $conversation->id() );

		$this->fake_telegram_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request',
			)
		);

		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $conversation->id(), ( new RetryPolicy() )->max_attempts() ) );

		$this->assertSame( 'failed', $conversations->find( $conversation->id() )->topic_creation_state() );
	}

	public function test_no_configured_conversation_chat_marks_the_topic_failed_at_the_ceiling(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema_health );
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot          = $bots->create( 'Support Bot', 'token' );
		$conversation = $conversations->create( 'uuid-no-chat', 'hash', $bot->id(), null );
		$conversations->try_begin_topic_creation( $conversation->id() );

		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $conversation->id(), ( new RetryPolicy() )->max_attempts() ) );

		$this->assertSame( 'failed', $conversations->find( $conversation->id() )->topic_creation_state() );
	}
}
