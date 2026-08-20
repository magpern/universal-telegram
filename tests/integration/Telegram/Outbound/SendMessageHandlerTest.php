<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_UnitTestCase;

final class SendMessageHandlerTest extends WP_UnitTestCase {

	/**
	 * @var array<int, callable>
	 */
	private array $filters_to_remove = array();

	/**
	 * @var SchemaHealth
	 */
	private SchemaHealth $schema_health;

	/**
	 * @var BotProfileRepository
	 */
	private BotProfileRepository $bots;

	/**
	 * @var DestinationRepository
	 */
	private DestinationRepository $destinations;

	/**
	 * @var OutboundMessageRepository
	 */
	private OutboundMessageRepository $messages;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$vault               = new CredentialVault();

		$this->bots         = new BotProfileRepository( $this->schema_health, $vault );
		$this->destinations = new DestinationRepository( $this->schema_health );
		$this->messages     = new OutboundMessageRepository( $this->schema_health, $vault );
	}

	protected function tearDown(): void {
		foreach ( $this->filters_to_remove as $callback ) {
			remove_filter( 'pre_http_request', $callback );
		}
		$this->filters_to_remove = array();

		parent::tearDown();
	}

	private function fake_response( int $status, array $body ): void {
		$callback = static function () use ( $status, $body ) {
			return array(
				'response' => array( 'code' => $status ),
				'body'     => wp_json_encode( $body ),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	private function handler(): SendMessageHandler {
		return new SendMessageHandler(
			$this->messages,
			$this->bots,
			$this->destinations,
			new TelegramApiClient(),
			new TelegramFailureClassifier(),
			new RateLimiter( $this->schema_health ),
			new CircuitBreaker( $this->schema_health, new RetryPolicy() ),
			new AuditLogger( $this->schema_health, new Redactor() ),
			new RetryPolicy()
		);
	}

	private function job( string $message_uuid, int $bot_id, int $destination_id, int $attempt = 1 ): array {
		return array(
			'job_id'   => $message_uuid,
			'job_type' => 'telegram_send_message',
			'attempt'  => $attempt,
			'payload'  => array(
				'message_uuid'   => $message_uuid,
				'bot_id'         => $bot_id,
				'destination_id' => $destination_id,
			),
		);
	}

	public function test_a_faked_success_reaches_sent_with_the_telegram_message_id_recorded(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array( 'message_id' => 999 ),
			)
		);

		$bot         = $this->bots->create( 'Bot', 'fake-token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $this->messages->create( $bot->id(), $destination->id(), 'hello', null );

		$this->handler()->handle_job( $this->job( $message->message_uuid(), $bot->id(), $destination->id() ) );

		$sent = $this->messages->find( $message->id() );

		$this->assertSame( OutboundMessageStatus::SENT, $sent->status() );
		$this->assertSame( 999, $sent->telegram_message_id() );
		$this->assertNotNull( $sent->sent_at() );
	}

	public function test_a_faked_500_throws_for_the_generic_retry_sequence_and_is_marked_retry_scheduled(): void {
		$this->fake_response(
			500,
			array(
				'ok'          => false,
				'error_code'  => 500,
				'description' => 'Internal Server Error',
			)
		);

		$bot         = $this->bots->create( 'Bot', 'fake-token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $this->messages->create( $bot->id(), $destination->id(), 'hello', null );

		$this->expectException( \RuntimeException::class );

		try {
			$this->handler()->handle_job( $this->job( $message->message_uuid(), $bot->id(), $destination->id(), 1 ) );
		} finally {
			$after = $this->messages->find( $message->id() );
			$this->assertSame( OutboundMessageStatus::RETRY_SCHEDULED, $after->status() );
		}
	}

	public function test_a_faked_500_on_the_final_permitted_attempt_dead_letters_and_still_rethrows(): void {
		$this->fake_response(
			500,
			array(
				'ok'          => false,
				'error_code'  => 500,
				'description' => 'Internal Server Error',
			)
		);

		$bot         = $this->bots->create( 'Bot', 'fake-token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $this->messages->create( $bot->id(), $destination->id(), 'hello', null );

		$max_attempts = ( new RetryPolicy() )->max_attempts();

		try {
			$this->handler()->handle_job( $this->job( $message->message_uuid(), $bot->id(), $destination->id(), $max_attempts ) );
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $exception ) {
			$after = $this->messages->find( $message->id() );
			$this->assertSame( OutboundMessageStatus::DEAD_LETTER, $after->status() );
			$this->assertSame( 'telegram_retryable_attempts_exhausted', $after->last_failure_code() );
		}
	}

	public function test_a_faked_400_dead_letters_immediately_without_throwing(): void {
		$this->fake_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request: chat not found',
			)
		);

		$bot         = $this->bots->create( 'Bot', 'fake-token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $this->messages->create( $bot->id(), $destination->id(), 'hello', null );

		$this->handler()->handle_job( $this->job( $message->message_uuid(), $bot->id(), $destination->id() ) );

		$after = $this->messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::DEAD_LETTER, $after->status() );
		$this->assertSame( 'telegram_terminal_rejection', $after->last_failure_code() );
	}

	public function test_a_faked_401_opens_the_bot_circuit_indefinitely_and_invalidates_the_bot(): void {
		$this->fake_response(
			401,
			array(
				'ok'          => false,
				'error_code'  => 401,
				'description' => 'Unauthorized',
			)
		);

		$bot         = $this->bots->create( 'Bot', 'fake-token' );
		$destination = $this->destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $this->messages->create( $bot->id(), $destination->id(), 'hello', null );

		$breaker = new CircuitBreaker( $this->schema_health, new RetryPolicy() );

		$this->handler()->handle_job( $this->job( $message->message_uuid(), $bot->id(), $destination->id() ) );

		$after = $this->messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::DEAD_LETTER, $after->status() );
		$this->assertSame( 'telegram_token_invalid', $after->last_failure_code() );

		$this->assertSame( \UniversalTelegram\Telegram\Reliability\CircuitBreakerState::OPEN, $breaker->state( 'bot', $bot->id() ) );
		$this->assertFalse( $breaker->may_attempt( 'bot', $bot->id() ) );

		$updated_bot = $this->bots->find( $bot->id() );
		$this->assertSame( \UniversalTelegram\Telegram\Configuration\BotStatus::INVALID, $updated_bot->status() );
	}
}
