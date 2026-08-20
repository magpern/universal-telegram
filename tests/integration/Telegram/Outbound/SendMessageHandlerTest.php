<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Outbound;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use WP_UnitTestCase;

final class SendMessageHandlerTest extends WP_UnitTestCase {

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

	public function test_a_faked_success_reaches_sent_with_the_telegram_message_id_recorded(): void {
		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array( 'message_id' => 999 ),
			)
		);

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'fake-token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		$handler = new SendMessageHandler( $messages, $bots, $destinations, new TelegramApiClient() );

		$handler->handle_job(
			array(
				'job_id'   => 'job-1',
				'job_type' => 'telegram_send_message',
				'attempt'  => 1,
				'payload'  => array(
					'message_uuid'   => $message->message_uuid(),
					'bot_id'         => $bot->id(),
					'destination_id' => $destination->id(),
				),
			)
		);

		$sent = $messages->find( $message->id() );

		$this->assertSame( OutboundMessageStatus::SENT, $sent->status() );
		$this->assertSame( 999, $sent->telegram_message_id() );
		$this->assertNotNull( $sent->sent_at() );
	}

	public function test_a_faked_failure_throws_for_the_generic_retry_sequence(): void {
		$this->fake_response(
			500,
			array(
				'ok'          => false,
				'error_code'  => 500,
				'description' => 'Internal Server Error',
			)
		);

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'fake-token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '12345', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		$handler = new SendMessageHandler( $messages, $bots, $destinations, new TelegramApiClient() );

		$this->expectException( \RuntimeException::class );

		$handler->handle_job(
			array(
				'job_id'   => 'job-1',
				'job_type' => 'telegram_send_message',
				'attempt'  => 1,
				'payload'  => array(
					'message_uuid'   => $message->message_uuid(),
					'bot_id'         => $bot->id(),
					'destination_id' => $destination->id(),
				),
			)
		);
	}
}
