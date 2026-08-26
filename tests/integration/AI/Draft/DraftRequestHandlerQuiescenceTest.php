<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use ActionScheduler;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Draft\DraftRequestHandler;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\WorkerRunner;
use WP_UnitTestCase;

/**
 * ADR-0040 §2 entry point #6: AI draft request refuses outside idle,
 * before eligibility/enablement checks.
 */
final class DraftRequestHandlerQuiescenceTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET enabled = 1, model = 'gpt', api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'UPDATE ' . $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE . " SET state = 'idle', updated_at = NOW() WHERE id = 1" );

		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}

		$schema_health = new SchemaHealth();
		$this->gate    = new QuiescenceGate(
			$schema_health,
			new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
			new QuiescenceTransitionRepository()
		);
	}

	public function test_request_returns_quiescence_active_outside_idle(): void {
		$this->gate->enter();

		$schema_health = new SchemaHealth();
		$handler       = new DraftRequestHandler(
			new AiDraftRepository( $schema_health, new CredentialVault() ),
			new AIProviderRepository( $schema_health, new CredentialVault() ),
			new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() ),
			new Dispatcher( $schema_health ),
			$this->gate
		);

		$this->assertSame( 'quiescence_active', $handler->request( 1, 1 ) );
	}
}
