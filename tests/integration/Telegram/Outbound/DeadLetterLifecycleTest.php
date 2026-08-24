<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Audit\AuditLogger;
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
use UniversalTelegram\Telegram\Outbound\DeadLetterDismisser;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_UnitTestCase;

final class DeadLetterLifecycleTest extends WP_UnitTestCase {

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

	public function test_a_terminal_failure_dead_letters_on_the_very_first_attempt(): void {
		$this->fake_response(
			400,
			array(
				'ok'          => false,
				'error_code'  => 400,
				'description' => 'Bad Request: chat not found',
			)
		);

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		$handler = new SendMessageHandler(
			$messages,
			$bots,
			$destinations,
			new TelegramApiClient(),
			new TelegramFailureClassifier(),
			new RateLimiter( $schema_health ),
			new CircuitBreaker( $schema_health, new RetryPolicy() ),
			new AuditLogger( $schema_health, new Redactor() ),
			new RetryPolicy(),
			new UnresolvedOutboundAbandoner( $messages )
		);

		$handler->handle_job(
			array(
				'job_id'   => $message->message_uuid(),
				'job_type' => 'telegram_send_message',
				'attempt'  => 1,
				'payload'  => array(
					'message_uuid'   => $message->message_uuid(),
					'bot_id'         => $bot->id(),
					'destination_id' => $destination->id(),
				),
			)
		);

		$dead_lettered = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::DEAD_LETTER, $dead_lettered->status() );
		$this->assertNotNull( $dead_lettered->dead_lettered_at() );

		// Content remains retained (not purged) while dead-lettered.
		$this->assertNotNull( $dead_lettered->body_ciphertext() );
	}

	public function test_requeue_resets_a_dead_lettered_message_to_a_fresh_pending_attempt_without_retyping(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$bot          = $bots->create( 'Bot', 'token' );
		$destination  = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message      = $messages->create( $bot->id(), $destination->id(), 'original content', null );

		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );
		$before_requeue = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::DEAD_LETTER, $before_requeue->status() );

		$this->assertTrue( $messages->requeue( $message->id() ) );

		$after = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::PENDING, $after->status() );
		$this->assertSame( 0, $after->attempt_count() );
		$this->assertNull( $after->last_failure_code() );
		$this->assertNull( $after->dead_lettered_at() );

		$decrypted = $messages->decrypt_body( $after );
		$this->assertSame( 'original content', $decrypted->plaintext() );
	}

	public function test_dismiss_removes_a_dead_lettered_row_and_records_an_audit_entry(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$audit         = new AuditLogger( $schema_health, new Redactor() );

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$bot          = $bots->create( 'Bot', 'token' );
		$destination  = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message      = $messages->create( $bot->id(), $destination->id(), 'original content', null );

		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );

		$dismisser = new DeadLetterDismisser( $messages, $audit );
		$this->assertTrue( $dismisser->dismiss( $message->id() ) );
		$this->assertNull( $messages->find( $message->id() ) );
		$this->assertFalse( $dismisser->dismiss( $message->id() ) );
	}
}
