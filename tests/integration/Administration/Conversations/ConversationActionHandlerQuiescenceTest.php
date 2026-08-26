<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Conversations;

use UniversalTelegram\Administration\Conversations\ConversationActionHandler;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\TopicDeletionDispatcher;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry point #4: every operator conversation-workflow action
 * refuses outside idle, before capability/nonce checks.
 */
final class ConversationActionHandlerQuiescenceTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		$schema_health = new SchemaHealth();
		$this->gate    = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
	}

	protected function tearDown(): void {
		unset( $_POST['_wpnonce'], $_POST['op'] );
		parent::tearDown();
	}

	private function handler(): ConversationActionHandler {
		$schema_health  = new SchemaHealth();
		$availability   = new OperatorAvailabilityRepository( $schema_health );
		$identities     = new OperatorIdentityRepository( $schema_health );
		$conversations  = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$notes          = new ConversationNoteRepository( $schema_health, new CredentialVault() );
		$messages       = new MessageRepository( $schema_health, new CredentialVault() );
		$destinations   = new DestinationRepository( $schema_health );
		$purge_service  = new ConversationPurgeService( $conversations, $messages, $destinations );
		$eligibility    = new ConversationTopicEligibility( $conversations, $destinations );
		$topic_deletion = new TopicDeletionDispatcher( $conversations, new Dispatcher( $schema_health ) );
		$audit          = new AuditLogger( $schema_health, new Redactor() );

		return new ConversationActionHandler( $availability, $identities, $conversations, $notes, $purge_service, $audit, $eligibility, $topic_deletion, $this->gate );
	}

	public function test_request_is_blocked_with_409_outside_idle_even_with_full_capability_and_nonce(): void {
		$this->gate->enter();

		$operator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $operator );

		$_POST['_wpnonce'] = wp_create_nonce( ConversationActionHandler::NONCE_ACTION );
		$_POST['op']       = 'set_availability';

		$this->expectException( \WPDieException::class );

		$this->handler()->handle_request();
	}
}
