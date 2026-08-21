<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Telegram;

use UniversalTelegram\Administration\Telegram\BotManagementController;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
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
			new MessageDispatcher( $messages, new Dispatcher( $this->schema_health ) ),
			new Dispatcher( $this->schema_health )
		) extends BotManagementController {
			protected function redirect_and_exit( string $url ): void {
				// Overridden so the test process is not terminated.
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
}
