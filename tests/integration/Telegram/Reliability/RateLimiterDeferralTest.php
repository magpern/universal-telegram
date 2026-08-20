<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Reliability;

use ActionScheduler_Store;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
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

final class RateLimiterDeferralTest extends WP_UnitTestCase {

	public function test_a_locally_rate_limited_destination_defers_without_consuming_an_attempt_and_leaves_the_message_pending(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		// Pre-exhaust the destination's 1-token bucket.
		$rate_limiter = new RateLimiter( $schema_health );
		$this->assertTrue( $rate_limiter->try_consume( 'destination', $destination->id(), 1.0, 1.0 ) );

		$handler = new SendMessageHandler(
			$messages,
			$bots,
			$destinations,
			new TelegramApiClient(),
			new TelegramFailureClassifier(),
			$rate_limiter,
			new CircuitBreaker( $schema_health, new RetryPolicy() ),
			new AuditLogger( $schema_health, new Redactor() ),
			new RetryPolicy()
		);

		// No pre_http_request fake is installed at all — a real Telegram
		// call must never be attempted while the bucket is empty.
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

		$after = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::PENDING, $after->status() );
		$this->assertSame( 0, $after->attempt_count() );

		// A fresh Action Scheduler action was scheduled for a deferred retry.
		$pending = ActionScheduler_Store::instance()->query_actions(
			array(
				'hook'   => WorkerRunner::HOOK,
				'group'  => WorkerRunner::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			),
			'count'
		);
		$this->assertGreaterThan( 0, (int) $pending );
	}
}
