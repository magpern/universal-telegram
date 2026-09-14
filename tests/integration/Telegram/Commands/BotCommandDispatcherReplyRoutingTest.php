<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Commands\BotCommandDispatcher;
use UniversalTelegram\Telegram\Commands\CommandAcknowledgements;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\SendMessageHandler;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_UnitTestCase;

/**
 * M09: a command's answer belongs to whoever asked, not to everyone in the
 * shared group -- BotCommandDispatcher::reply() attempts a private DM
 * first, falling back to a neutral group prompt (never the reply's own
 * content) only when that private attempt does not complete.
 */
final class BotCommandDispatcherReplyRoutingTest extends WP_UnitTestCase {

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

	/**
	 * Fails every sendMessage call whose JSON body targets the given chat_id
	 * (the private-chat cold-DM refusal Telegram actually returns), while
	 * letting every other chat_id through to WordPress' own default HTTP
	 * transport short-circuit (there is none in a test run, so this filter
	 * is the only responder for both chats -- the group send below installs
	 * its own success stub first).
	 *
	 * @param string $blocked_chat_id The chat_id to refuse.
	 */
	private function fake_forbidden_for_chat( string $blocked_chat_id ): void {
		$callback = static function ( $preempt, $args ) use ( $blocked_chat_id ) {
			$body = array();
			if ( isset( $args['body']['chat_id'] ) && $args['body']['chat_id'] === $blocked_chat_id ) {
				return array(
					'response' => array( 'code' => 403 ),
					'body'     => wp_json_encode(
						array(
							'ok'          => false,
							'error_code'  => 403,
							'description' => "Forbidden: bot can't initiate conversation with a user",
						)
					),
				);
			}

			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'ok'     => true,
						'result' => array( 'message_id' => 1 ),
					)
				),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 2 );
		$this->filters_to_remove[] = $callback;
	}

	/**
	 * @return array{0: BotCommandDispatcher, 1: \UniversalTelegram\Telegram\Configuration\BotProfile, 2: \UniversalTelegram\Telegram\Configuration\Destination, 3: \UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository, 4: \UniversalTelegram\Telegram\Outbound\OutboundMessageRepository}
	 */
	private function build( SchemaHealth $schema_health ): array {
		$vault = new CredentialVault();

		$bots         = new BotProfileRepository( $schema_health, $vault );
		$destinations = new DestinationRepository( $schema_health );
		$messages     = new OutboundMessageRepository( $schema_health, $vault );

		$bot = $bots->create( 'Bot', 'token' );
		$bots->update_telegram_identity( $bot->id(), 123456, 'TestBot' );
		$bot               = $bots->find( $bot->id() );
		$group_destination = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1001', null, 'Group' );

		$send_handler = new SendMessageHandler(
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

		$message_dispatcher = new MessageDispatcher(
			$messages,
			new Dispatcher( $schema_health ),
			$send_handler,
			$bots,
			$destinations
		);

		$operator_identities = new OperatorIdentityMapRepository( $schema_health );
		$audit               = new AuditLogger( $schema_health, new Redactor() );

		$dispatcher = new BotCommandDispatcher(
			$operator_identities,
			new QueueHealth(),
			new EventHistoryRepository( $schema_health, new Registry(), new Redactor() ),
			new WooCommerceSupport(),
			new WooCommerceCommandQueryService(),
			$message_dispatcher,
			$destinations,
			$audit
		);

		return array( $dispatcher, $bot, $group_destination, $operator_identities, $messages );
	}

	private function whoami_update( int $sender_telegram_user_id ): array {
		return array(
			'update_id' => 1,
			'message'   => array(
				'chat'     => array( 'id' => -1001 ),
				'text'     => '/whoami',
				'from'     => array( 'id' => $sender_telegram_user_id ),
				'entities' => array(
					array(
						'type'   => 'bot_command',
						'offset' => 0,
						'length' => 7,
					),
				),
			),
		);
	}

	public function test_whoami_is_delivered_to_the_operators_own_dm_not_the_group(): void {
		$schema_health = new SchemaHealth();
		list( $dispatcher, $bot, $group_destination, $operator_identities, $messages ) = $this->build( $schema_health );

		$telegram_user_id = 999111222;
		$wp_user_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$operator_identities->create( $wp_user_id, $telegram_user_id, 'operator', 1 );

		$this->fake_response(
			200,
			array(
				'ok'     => true,
				'result' => array( 'message_id' => 1 ),
			)
		);

		$decoded = $this->whoami_update( $telegram_user_id );
		$parsed  = CommandParser::parse( $decoded['message'], $bot->telegram_username() );
		$this->assertNotNull( $parsed );

		$dispatcher->handle( $bot, '-1001', null, $parsed, $decoded );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$rows  = $wpdb->get_results( "SELECT message_uuid, destination_id, status FROM {$table} WHERE bot_id = {$bot->id()} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Two outbound messages: the private whoami reply itself, plus a
		// short group breadcrumb (never the reply's own content) so the
		// chat doesn't look like the bot did nothing.
		$this->assertCount( 2, $rows );
		$this->assertSame( 'sent', $rows[0]['status'] );
		$this->assertNotSame( (string) $group_destination->id(), (string) $rows[0]['destination_id'], 'the first row is the private reply, not the group' );
		$this->assertSame( (string) $group_destination->id(), (string) $rows[1]['destination_id'], 'the second row is the group breadcrumb' );
		$this->assertSame( 'sent', $rows[1]['status'] );

		$breadcrumb_message = $messages->find_by_uuid( $rows[1]['message_uuid'] );
		$breadcrumb         = $messages->decrypt_body( $breadcrumb_message );
		$this->assertNotNull( $breadcrumb );
		$this->assertSame( CommandAcknowledgements::REPLIED_PRIVATELY, $breadcrumb->plaintext() );

		$keyboard = $messages->decrypt_reply_markup( $breadcrumb_message );
		$this->assertSame( 'https://t.me/TestBot', $keyboard['inline_keyboard'][0][0]['url'], 'the breadcrumb carries an Open chat button straight to the bots DM' );
	}

	public function test_whoami_falls_back_to_a_neutral_group_prompt_when_the_operator_has_never_dmed_the_bot(): void {
		$schema_health = new SchemaHealth();
		list( $dispatcher, $bot, $group_destination, $operator_identities, $messages ) = $this->build( $schema_health );

		$telegram_user_id = 999111333;
		$wp_user_id       = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$operator_identities->create( $wp_user_id, $telegram_user_id, 'operator', 1 );

		$this->fake_forbidden_for_chat( (string) $telegram_user_id );

		$decoded = $this->whoami_update( $telegram_user_id );
		$parsed  = CommandParser::parse( $decoded['message'], $bot->telegram_username() );
		$this->assertNotNull( $parsed );

		$dispatcher->handle( $bot, '-1001', null, $parsed, $decoded );

		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$rows  = $wpdb->get_results( "SELECT destination_id, body_ciphertext FROM {$table} WHERE bot_id = {$bot->id()} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Two rows: the failed private attempt, and the group fallback.
		$this->assertCount( 2, $rows );

		$group_rows = array_values(
			array_filter( $rows, static fn ( $row ) => (string) $group_destination->id() === (string) $row['destination_id'] )
		);
		$this->assertCount( 1, $group_rows, 'exactly one message went to the group destination' );

		$fallback_message = $messages->find_by_uuid( $this->uuid_for( $group_rows[0], $table ) );
		$decrypted        = $messages->decrypt_body( $fallback_message );
		$this->assertNotNull( $decrypted );
		$this->assertSame( CommandAcknowledgements::DM_REQUIRED, $decrypted->plaintext(), 'the group message is the neutral prompt, never the actual whoami answer' );

		$keyboard = $messages->decrypt_reply_markup( $fallback_message );
		$this->assertSame( 'https://t.me/TestBot', $keyboard['inline_keyboard'][0][0]['url'], 'the fallback prompt carries the same Open chat button, pointing at the chat the operator needs to open' );
	}

	/**
	 * Re-reads a row's own message_uuid (not selected above, to keep that
	 * query's own assertion focused) for the decrypt-and-compare step.
	 *
	 * @param array<string, mixed> $row   A row already fetched by destination_id.
	 * @param string               $table The outbound_messages table name.
	 */
	private function uuid_for( array $row, string $table ): string {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT message_uuid FROM {$table} WHERE destination_id = %d ORDER BY id DESC LIMIT 1", $row['destination_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
