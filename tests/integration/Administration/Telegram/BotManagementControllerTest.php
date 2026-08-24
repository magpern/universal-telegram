<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Telegram;

use UniversalTelegram\Administration\Telegram\BotManagementController;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ForumTopicRemoteDeleter;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Outbound\DeadLetterDismisser;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

final class BotManagementControllerTest extends WP_UnitTestCase {

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

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->schema_health = new SchemaHealth();
		$this->bots          = new BotProfileRepository( $this->schema_health, new CredentialVault() );
	}

	protected function tearDown(): void {
		foreach ( $this->filters_to_remove as $callback ) {
			remove_filter( 'pre_http_request', $callback );
		}
		$this->filters_to_remove = array();
		unset( $_POST['_wpnonce'], $_REQUEST['_wpnonce'] );
		$_POST = array();

		parent::tearDown();
	}

	private function fake_get_me( bool $ok ): void {
		$callback = static function () use ( $ok ) {
			return array(
				'response' => array( 'code' => $ok ? 200 : 401 ),
				'body'     => wp_json_encode(
					$ok
						? array(
							'ok'     => true,
							'result' => array(
								'id'       => 111,
								'username' => 'my_bot',
							),
						)
						: array(
							'ok'          => false,
							'error_code'  => 401,
							'description' => 'Unauthorized',
						)
				),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	/**
	 * Fakes Telegram's sendMessage response. Used only for Test Message
	 * assertions — never a live call (docs/adr/0023 §4).
	 *
	 * @param bool        $ok          Whether the faked response is a success.
	 * @param int         $http_status Only used when $ok is false.
	 * @param string|null $description A raw description Telegram would return — must never surface in the admin-visible result.
	 */
	private function fake_send_message( bool $ok, int $http_status = 400, ?string $description = 'raw telegram detail, must never leak' ): void {
		$callback = static function () use ( $ok, $http_status, $description ) {
			return array(
				'response' => array( 'code' => $ok ? 200 : $http_status ),
				'body'     => wp_json_encode(
					$ok
						? array(
							'ok'     => true,
							'result' => array( 'message_id' => 42 ),
						)
						: array(
							'ok'          => false,
							'error_code'  => $http_status,
							'description' => $description,
						)
				),
			);
		};
		add_filter( 'pre_http_request', $callback, 10, 0 );
		$this->filters_to_remove[] = $callback;
	}

	/**
	 * @return array{0: \UniversalTelegram\Telegram\Configuration\BotProfile, 1: \UniversalTelegram\Telegram\Configuration\Destination}
	 */
	private function bot_with_destination(): array {
		$bot         = $this->bots->create( 'Bot', 'token' );
		$destination = ( new DestinationRepository( $this->schema_health ) )->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Test' );

		return array( $bot, $destination );
	}

	/**
	 * Asserts no row exists in universal_telegram_outbound_messages for a
	 * given bot. Auto-increment IDs are not transactional, so `find(1)`
	 * cannot be relied on across a suite — this mirrors
	 * MessageDispatcherTest's own direct-query assertion pattern instead.
	 *
	 * @param int $bot_id The bot's primary key.
	 */
	private function assertNoOutboundMessageRowFor( int $bot_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'universal_telegram_outbound_messages';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE bot_id = %d", $bot_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertNull( $row );
	}

	private function controller(): BotManagementController {
		$destinations = new DestinationRepository( $this->schema_health );
		$messages     = new OutboundMessageRepository( $this->schema_health, new CredentialVault() );
		$client       = new TelegramApiClient();

		return new class(
			$this->bots,
			$destinations,
			$messages,
			$client,
			new WebhookRegistrationCoordinator(
				$this->bots,
				$client,
				new AuditLogger( $this->schema_health, new Redactor() ),
				static function (): string {
					return 'https://example.com/webhook/';
				}
			),
			new Dispatcher( $this->schema_health ),
			new TelegramApiClient( 8 ),
			new TelegramFailureClassifier(),
			new AuditLogger( $this->schema_health, new Redactor() ),
			new ForumTopicRemoteDeleter( $this->bots, $destinations, $client ),
			new UnresolvedOutboundAbandoner( $messages ),
			new DeadLetterDismisser( $messages, new AuditLogger( $this->schema_health, new Redactor() ) )
		) extends BotManagementController {
			public ?string $last_redirect_url = null;

			protected function redirect_and_exit( string $url ): void {
				// Overridden so the test process is not terminated.
				$this->last_redirect_url = $url;
			}
		};
	}

	public function test_an_unauthorized_user_is_denied(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		$this->controller()->handle_request();
	}

	public function test_a_missing_nonce_is_rejected(): void {
		$_POST['op'] = 'create_bot';

		$this->expectException( \WPDieException::class );
		$this->controller()->handle_request();
	}

	public function test_replace_token_is_rejected_on_a_faked_getme_failure_and_the_old_token_stays_intact(): void {
		$bot = $this->bots->create( 'Bot', 'old-token' );

		$this->fake_get_me( false );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'       => 'replace_token',
			'bot_id'   => $bot->id(),
			'token'    => 'new-token',
			'_wpnonce' => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->controller()->handle_request();

		$after     = $this->bots->find( $bot->id() );
		$decrypted = $this->bots->decrypt_token( $after );
		$this->assertSame( 'old-token', $decrypted->plaintext() );
	}

	public function test_replace_token_is_committed_only_after_a_faked_getme_success(): void {
		$bot = $this->bots->create( 'Bot', 'old-token' );

		$this->fake_get_me( true );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'       => 'replace_token',
			'bot_id'   => $bot->id(),
			'token'    => 'new-token',
			'_wpnonce' => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->controller()->handle_request();

		$after     = $this->bots->find( $bot->id() );
		$decrypted = $this->bots->decrypt_token( $after );
		$this->assertSame( 'new-token', $decrypted->plaintext() );
	}

	public function test_create_bot_validates_via_getme_before_committing(): void {
		$this->fake_get_me( false );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'       => 'create_bot',
			'name'     => 'Rejected Bot',
			'token'    => 'bad-token',
			'_wpnonce' => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->controller()->handle_request();

		$this->assertCount( 0, $this->bots->all() );
	}

	public function test_send_test_message_on_a_faked_success_reports_ok_without_queuing_or_creating_a_message_row(): void {
		list( $bot, $destination ) = $this->bot_with_destination();

		$this->fake_send_message( true );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'             => 'send_test_message',
			'bot_id'         => $bot->id(),
			'destination_id' => $destination->id(),
			'_wpnonce'       => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$controller = $this->controller();
		$controller->handle_request();

		$this->assertStringContainsString( 'test_message_result=ok', $controller->last_redirect_url );
		$this->assertNoOutboundMessageRowFor( $bot->id() );
	}

	/**
	 * @return array<string, array{0: int, 1: string}>
	 */
	public static function failure_classification_provider(): array {
		return array(
			'rate limited'  => array( 429, 'test_message_result=failed_rate_limited' ),
			'terminal'      => array( 400, 'test_message_result=failed_terminal' ),
			'token invalid' => array( 401, 'test_message_result=failed_token_invalid' ),
			'retryable'     => array( 500, 'test_message_result=failed_retryable' ),
		);
	}

	/**
	 * @dataProvider failure_classification_provider
	 */
	public function test_send_test_message_on_each_failure_classification_reports_the_fixed_code_never_the_raw_description( int $http_status, string $expected_query_arg ): void {
		list( $bot, $destination ) = $this->bot_with_destination();

		$this->fake_send_message( false, $http_status, 'raw telegram detail, must never leak' );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'             => 'send_test_message',
			'bot_id'         => $bot->id(),
			'destination_id' => $destination->id(),
			'_wpnonce'       => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$controller = $this->controller();
		$controller->handle_request();

		$this->assertStringContainsString( $expected_query_arg, $controller->last_redirect_url );
		$this->assertStringNotContainsString( 'raw+telegram', $controller->last_redirect_url );
		$this->assertStringNotContainsString( 'raw%20telegram', $controller->last_redirect_url );
	}

	public function test_send_test_message_never_calls_the_queue_dispatcher(): void {
		list( $bot, $destination ) = $this->bot_with_destination();

		$this->fake_send_message( true );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'             => 'send_test_message',
			'bot_id'         => $bot->id(),
			'destination_id' => $destination->id(),
			'_wpnonce'       => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		// A dedicated Dispatcher subclass recording whether it was ever
		// asked to schedule anything — the bounded Test Message send must
		// never enqueue a job (mirrors DispatcherTest's own override
		// precedent).
		$dispatcher = new class( $this->schema_health ) extends Dispatcher {
			public bool $schedule_action_called = false;

			protected function schedule_action( array $args ) {
				$this->schedule_action_called = true;
				return 999;
			}
		};

		$messages = new OutboundMessageRepository( $this->schema_health, new CredentialVault() );
		$client   = new TelegramApiClient();
		$destinations = new DestinationRepository( $this->schema_health );

		$controller = new class(
			$this->bots,
			$destinations,
			$messages,
			$client,
			new WebhookRegistrationCoordinator(
				$this->bots,
				$client,
				new AuditLogger( $this->schema_health, new Redactor() ),
				static function (): string {
					return 'https://example.com/webhook/';
				}
			),
			$dispatcher,
			new TelegramApiClient( 8 ),
			new TelegramFailureClassifier(),
			new AuditLogger( $this->schema_health, new Redactor() ),
			new ForumTopicRemoteDeleter( $this->bots, $destinations, $client ),
			new UnresolvedOutboundAbandoner( $messages ),
			new DeadLetterDismisser( $messages, new AuditLogger( $this->schema_health, new Redactor() ) )
		) extends BotManagementController {
			protected function redirect_and_exit( string $url ): void {}
		};

		$controller->handle_request();

		$this->assertFalse( $dispatcher->schedule_action_called );
		$this->assertNoOutboundMessageRowFor( $bot->id() );
	}

	public function test_create_bot_from_the_wizard_redirects_back_into_it_with_the_new_bot_selected(): void {
		$this->fake_get_me( true );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'          => 'create_bot',
			'name'        => 'Wizard Bot',
			'token'       => 'good-token',
			'from_wizard' => '1',
			'_wpnonce'    => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$controller = $this->controller();
		$controller->handle_request();

		$this->assertCount( 1, $this->bots->all() );
		$this->assertStringContainsString( 'view=wizard', (string) $controller->last_redirect_url );
		$this->assertStringContainsString( 'bot_id=latest', (string) $controller->last_redirect_url );
	}

	public function test_create_bot_without_the_wizard_marker_redirects_to_the_plain_bots_tab(): void {
		$this->fake_get_me( true );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'       => 'create_bot',
			'name'     => 'Manual Bot',
			'token'    => 'good-token',
			'_wpnonce' => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$controller = $this->controller();
		$controller->handle_request();

		$this->assertStringNotContainsString( 'view=wizard', (string) $controller->last_redirect_url );
	}

	public function test_dismiss_dead_letter_removes_the_row_and_busts_the_queue_health_alert_cache(): void {
		list( $bot, $destination ) = $this->bot_with_destination();

		$messages = new OutboundMessageRepository( $this->schema_health, new CredentialVault() );
		$message  = $messages->create( $bot->id(), $destination->id(), 'hello', null );
		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );

		set_transient( QueueHealthAlert::ALERT_CACHE_TRANSIENT, true, 60 );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'         => 'dismiss_dead_letter',
			'message_id' => $message->id(),
			'_wpnonce'   => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->controller()->handle_request();

		$this->assertNull( $messages->find( $message->id() ) );
		$this->assertFalse( get_transient( QueueHealthAlert::ALERT_CACHE_TRANSIENT ) );
	}

	public function test_requeue_message_is_ignored_unless_the_message_is_dead_lettered(): void {
		list( $bot, $destination ) = $this->bot_with_destination();

		$messages = new OutboundMessageRepository( $this->schema_health, new CredentialVault() );
		$message  = $messages->create( $bot->id(), $destination->id(), 'hello', null );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'         => 'requeue_message',
			'message_id' => $message->id(),
			'_wpnonce'   => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->controller()->handle_request();

		$after = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::PENDING, $after->status() );
	}
}
