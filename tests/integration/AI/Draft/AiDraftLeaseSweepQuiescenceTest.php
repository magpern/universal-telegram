<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use ActionScheduler;
use UniversalTelegram\AI\Draft\AiDraftLeaseSweep;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\WorkerRunner;
use WP_UnitTestCase;

/**
 * ADR-0040 §5: the AI draft lease-recovery sweep skips the entire cycle
 * outside idle — never marked failed.
 */
final class AiDraftLeaseSweepQuiescenceTest extends WP_UnitTestCase {

	private QuiescenceGate $gate;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

	private function force_lease_expired( int $draft_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}" . Migrator::AI_DRAFTS_TABLE . ' SET generation_lease_expires_at = %s WHERE id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				gmdate( 'Y-m-d H:i:s', time() - 3600 ),
				$draft_id
			)
		);
	}

	public function test_run_does_not_reclaim_an_expired_lease_outside_idle(): void {
		$schema_health = new SchemaHealth();
		$drafts        = new AiDraftRepository( $schema_health, new CredentialVault() );
		$conversations = new ConversationRepository( $schema_health, new CredentialVault(), new VisitorTokenGenerator() );
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null );

		$draft = $drafts->create( $conversation->id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $draft->id() );

		$this->gate->enter();

		( new AiDraftLeaseSweep( $drafts, $this->gate ) )->run();

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'generating', $result->status(), 'The sweep must skip its whole cycle outside idle, leaving the stale lease untouched.' );
	}
}
