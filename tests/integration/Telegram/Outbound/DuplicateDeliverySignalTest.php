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
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_Error;
use WP_UnitTestCase;

final class DuplicateDeliverySignalTest extends WP_UnitTestCase {

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

	private function fake_network_error(): void {
		$callback = static function () {
			return new WP_Error( 'http_request_failed', 'Connection timed out' );
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
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

	private function build(): array {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

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

		return array( $bots, $destinations, $messages, $handler );
	}

	public function test_a_network_transport_failure_sets_the_duplicate_delivery_signal(): void {
		list( $bots, $destinations, $messages, $handler ) = $this->build();

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		$this->fake_network_error();

		try {
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
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $exception ) {
			$after = $messages->find( $message->id() );
			$this->assertTrue( $after->possible_duplicate_delivery() );
		}
	}

	public function test_a_definite_http_error_response_does_not_set_the_duplicate_delivery_signal(): void {
		list( $bots, $destinations, $messages, $handler ) = $this->build();

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		$this->fake_response(
			500,
			array(
				'ok'          => false,
				'error_code'  => 500,
				'description' => 'Internal Server Error',
			)
		);

		try {
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
			$this->fail( 'Expected a RuntimeException.' );
		} catch ( \RuntimeException $exception ) {
			$after = $messages->find( $message->id() );
			$this->assertFalse( $after->possible_duplicate_delivery() );
		}
	}
}
