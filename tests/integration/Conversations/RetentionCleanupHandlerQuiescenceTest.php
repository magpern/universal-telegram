<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Conversations;

use UniversalTelegram\Conversations\ConversationPurgeService;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\ConversationTopicEligibility;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\RetentionCleanupHandler;
use UniversalTelegram\Conversations\TopicDeletionDispatcher;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use WP_UnitTestCase;

/**
 * ADR-0040 §5: the conversation retention sweep skips the entire cycle
 * outside idle — never marked failed.
 */
final class RetentionCleanupHandlerQuiescenceTest extends WP_UnitTestCase {

	private ConversationRepository $conversations;
	private QuiescenceGate $gate;
	private RetentionCleanupHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		$schema_health = new SchemaHealth();
		$this->conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$messages             = new MessageRepository( $schema_health, new CredentialVault() );
		$destinations         = new \UniversalTelegram\Telegram\Configuration\DestinationRepository( $schema_health );
		$purge_service        = new ConversationPurgeService( $this->conversations, $messages, $destinations );
		$eligibility          = new ConversationTopicEligibility( $this->conversations, $destinations );
		$topic_deletion       = new TopicDeletionDispatcher( $this->conversations, new Dispatcher( $schema_health ) );

		$this->gate = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);

		$this->handler = new RetentionCleanupHandler(
			$this->conversations,
			$messages,
			$purge_service,
			$eligibility,
			$topic_deletion,
			30,
			90,
			30,
			$this->gate
		);
	}

	public function test_run_does_not_archive_resolved_conversations_outside_idle(): void {
		$conversation = $this->conversations->create( wp_generate_uuid4(), 'hash', 1, null );
		$this->conversations->transition( $conversation->id(), ConversationStatus::NEW, ConversationStatus::OPEN );
		$this->conversations->transition( $conversation->id(), ConversationStatus::OPEN, ConversationStatus::RESOLVED );

		$this->gate->enter();

		$this->handler->run();

		$updated = $this->conversations->find( $conversation->id() );
		$this->assertSame( ConversationStatus::RESOLVED, $updated->status(), 'The sweep must skip its whole cycle outside idle.' );
	}
}
