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
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Configuration\WebhookRegistrationCoordinator;
use UniversalTelegram\Telegram\Outbound\DeadLetterDismisser;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageStatus;
use UniversalTelegram\Telegram\Outbound\UnresolvedOutboundAbandoner;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry point #8: requeue_message() is blocked unconditionally
 * outside idle, regardless of the requeued message's origin. The
 * dead-lettered row itself is untouched — requeue is only deferred.
 */
final class BotManagementControllerRequeueQuiescenceTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private BotProfileRepository $bots;
	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		( new CapabilityRegistrar() )->grant_to_administrator();
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$this->schema_health = new SchemaHealth();
		$this->bots          = new BotProfileRepository( $this->schema_health, new CredentialVault() );
		$this->gate          = new QuiescenceGate(
			$this->schema_health,
			new DeferredUpdateRepository( $this->schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
	}

	protected function tearDown(): void {
		unset( $_POST, $_REQUEST['_wpnonce'] );
		parent::tearDown();
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
			new DeadLetterDismisser( $messages, new AuditLogger( $this->schema_health, new Redactor() ) ),
			$this->gate
		) extends BotManagementController {
			protected function redirect_and_exit( string $url ): void {
				// no-op: avoids a real exit/header call in the test process.
			}
		};
	}

	public function test_requeue_is_blocked_unconditionally_outside_idle(): void {
		$this->gate->enter();

		$bot          = $this->bots->create( 'Bot', 'token' );
		$destinations = new DestinationRepository( $this->schema_health );
		$destination  = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Test' );

		$messages = new OutboundMessageRepository( $this->schema_health, new CredentialVault() );
		$message  = $messages->create( $bot->id(), $destination->id(), 'hello', null );
		$messages->mark_dead_letter( $message->id(), 'test_failure' );

		$nonce                = wp_create_nonce( BotManagementController::NONCE_ACTION );
		$_POST                = array(
			'op'         => 'requeue_message',
			'message_id' => $message->id(),
			'_wpnonce'   => $nonce,
		);
		$_REQUEST['_wpnonce'] = $nonce;

		$this->controller()->handle_request();

		$after = $messages->find( $message->id() );
		$this->assertSame( OutboundMessageStatus::DEAD_LETTER, $after->status(), 'The dead-lettered row itself must be left untouched, only the requeue action refused.' );
	}
}
