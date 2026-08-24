<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use RuntimeException;
use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\TopicDeletionHandler;
use UniversalTelegram\Conversations\TopicLifecycleState;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramTopicError;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class TopicDeletionHandlerTest extends WP_UnitTestCase {

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

	private function handler(
		ConversationRepository $conversations,
		BotProfileRepository $bots,
		DestinationRepository $destinations
	): TopicDeletionHandler {
		$eligibility = new ConversationTopicEligibility( $conversations, $destinations );
		$purge       = new ConversationPurgeService( $conversations, new MessageRepository( new SchemaHealth() ), $destinations );

		return new TopicDeletionHandler(
			$conversations,
			$bots,
			$eligibility,
			$purge,
			new TelegramApiClient(),
			new RetryPolicy()
		);
	}

	private function job( int $conversation_id, int $attempt = 1, ?string $lease = null ): array {
		$payload = array( 'conversation_id' => $conversation_id );
		if ( null !== $lease ) {
			$payload['claimed_lease_expires_at'] = $lease;
		}

		return array(
			'job_id'   => 'job-del-' . $conversation_id,
			'job_type' => TopicDeletionHandler::JOB_TYPE,
			'attempt'  => $attempt,
			'payload'  => $payload,
		);
	}

	private function setup_eligible(): array {
		$schema        = new SchemaHealth();
		$vault         = new CredentialVault();
		$conversations = new ConversationRepository( $schema, $vault, new VisitorTokenGenerator() );
		$bots          = new BotProfileRepository( $schema, $vault );
		$destinations  = new DestinationRepository( $schema );

		$bot          = $bots->create( 'Bot', 'token-secret' );
		$conversation = $conversations->create( wp_generate_uuid4(), 'hash', $bot->id(), null );
		$destination  = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100delh', 57, 'Topic' );
		$conversations->mark_topic_created( $conversation->id(), 57, $destination->id() );
		$conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );
		$conversations->transition( $conversation->id(), ConversationStatus::RESOLVED, ConversationStatus::ARCHIVED );
		$lease = $conversations->try_begin_topic_deletion( $conversation->id() );

		return array( $conversations, $bots, $destinations, $conversation->id(), $destination->id(), $lease );
	}

	public function test_success_purges_conversation_and_destination(): void {
		[ $conversations, $bots, $destinations, $cid, $did, $lease ] = $this->setup_eligible();

		$this->fake_telegram_response( 200, array( 'ok' => true, 'result' => true ) );
		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $cid, 1, $lease ) );

		$this->assertNull( $conversations->find( $cid ) );
		$this->assertNull( $destinations->find( $did ) );
	}

	public function test_missing_topic_is_idempotent_success_and_purges(): void {
		[ $conversations, $bots, $destinations, $cid, $did, $lease ] = $this->setup_eligible();

		$this->fake_telegram_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request: message thread not found',
			)
		);
		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $cid, 1, $lease ) );

		$this->assertNull( $conversations->find( $cid ) );
		$this->assertNull( $destinations->find( $did ) );
	}

	public function test_chat_not_found_retains_and_marks_delete_failed(): void {
		[ $conversations, $bots, $destinations, $cid, $did, $lease ] = $this->setup_eligible();

		$this->fake_telegram_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request: chat not found',
			)
		);
		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $cid, 1, $lease ) );

		$fresh = $conversations->find( $cid );
		$this->assertNotNull( $fresh );
		$this->assertSame( TopicLifecycleState::DELETE_FAILED, $fresh->topic_lifecycle_state() );
		$this->assertSame( TelegramTopicError::TOPIC_DELETE_CHAT_NOT_FOUND, $fresh->topic_lifecycle_code() );
		$this->assertNotNull( $destinations->find( $did ) );
	}

	public function test_forbidden_marks_delete_failed_without_purge(): void {
		[ $conversations, $bots, $destinations, $cid, $did, $lease ] = $this->setup_eligible();

		$this->fake_telegram_response(
			403,
			array(
				'ok'          => false,
				'error_code'  => 403,
				'description' => 'Forbidden: not enough rights to manage topics',
			)
		);
		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $cid, 1, $lease ) );

		$fresh = $conversations->find( $cid );
		$this->assertNotNull( $fresh );
		$this->assertSame( TopicLifecycleState::DELETE_FAILED, $fresh->topic_lifecycle_state() );
		$this->assertSame( TelegramTopicError::TOPIC_DELETE_FORBIDDEN, $fresh->topic_lifecycle_code() );
		$this->assertNotNull( $destinations->find( $did ) );
	}

	public function test_retryable_throws_until_exhausted(): void {
		[ $conversations, $bots, $destinations, $cid, $did, $lease ] = $this->setup_eligible();

		$this->fake_telegram_response(
			500,
			array(
				'ok'          => false,
				'error_code'  => 500,
				'description' => 'Internal Server Error',
			)
		);

		$this->expectException( RuntimeException::class );
		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $cid, 1, $lease ) );
	}

	public function test_duplicate_job_without_matching_lease_is_a_noop_when_not_pending(): void {
		[ $conversations, $bots, $destinations, $cid ] = $this->setup_eligible();
		$conversations->mark_topic_lifecycle( $cid, TopicLifecycleState::DELETE_FAILED, TelegramTopicError::TOPIC_DELETE_FORBIDDEN );

		$this->handler( $conversations, $bots, $destinations )->handle_job( $this->job( $cid ) );

		$this->assertNotNull( $conversations->find( $cid ) );
		$this->assertSame( TopicLifecycleState::DELETE_FAILED, $conversations->find( $cid )->topic_lifecycle_state() );
	}

	public function test_second_cas_loses_while_delete_pending(): void {
		[ $conversations, , , $cid ] = $this->setup_eligible();

		$this->assertNull( $conversations->try_begin_topic_deletion( $cid ) );
	}
}
