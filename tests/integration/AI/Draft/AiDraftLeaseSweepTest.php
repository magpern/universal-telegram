<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use UniversalTelegram\AI\Draft\AiDraftLeaseSweep;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\WorkerRunner;
use ActionScheduler;
use WP_UnitTestCase;

/**
 * Docs/adr/0028 decision 5, §3.5 of the frozen plan: the durable recovery
 * trigger for a crashed worker's expired lease — re-enqueue below the
 * shared attempt budget, dead-letter at/above it, idempotent under
 * overlapping runs, and a bounded upper bound on total staleness.
 */
final class AiDraftLeaseSweepTest extends WP_UnitTestCase {

	/**
	 * Explicit reset, not an assumption: the ai_drafts table and Action
	 * Scheduler's own tables are not reliably rolled back by
	 * WP_UnitTestCase's per-test transaction wrapping once a DDL
	 * statement elsewhere in the same run has forced an implicit commit —
	 * the same reason Queue\QueueHealthTest resets Action Scheduler's
	 * group in setUp() rather than relying on tearDown alone.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_ai_state();
	}

	protected function tearDown(): void {
		$this->reset_ai_state();
		parent::tearDown();
	}

	private function reset_ai_state(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}
	}

	private function drafts(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function sweep(): AiDraftLeaseSweep {
		return new AiDraftLeaseSweep( $this->drafts() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
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

	public function test_re_enqueues_an_expired_row_below_the_shared_attempt_budget(): void {
		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $draft->id() );

		$this->sweep()->run();

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'queued', $result->status() );
		$this->assertNotNull( $result->job_reference(), 'A fresh Action Scheduler action must be scheduled and recorded.' );
	}

	public function test_dead_letters_as_crashed_exhausted_at_the_shared_attempt_budget(): void {
		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Migrator::AI_DRAFTS_TABLE,
			array( 'attempt_count' => AiDraftLeaseSweep::MAX_ATTEMPTS_BEFORE_EXHAUSTED ),
			array( 'id' => $draft->id() )
		);
		$this->force_lease_expired( $draft->id() );

		$this->sweep()->run();

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'failed', $result->status() );
		$this->assertSame( 'crashed_exhausted', $result->failure_class() );
	}

	public function test_is_idempotent_under_two_consecutive_runs(): void {
		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $draft->id() );

		$sweep = $this->sweep();
		$sweep->run();
		$first_reference = $drafts->find( $draft->id() )->job_reference();

		// A second run must not re-touch a row that is no longer
		// 'generating' with an expired lease — it is now plain 'queued'.
		$sweep->run();
		$second_reference = $drafts->find( $draft->id() )->job_reference();

		$this->assertSame( $first_reference, $second_reference );
	}

	public function test_does_not_touch_a_row_with_a_still_valid_lease(): void {
		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim  = $drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );

		$this->sweep()->run();

		$result = $drafts->find( $draft->id() );
		$this->assertSame( 'generating', $result->status() );
		$this->assertSame( $claim['lease_token'], $result->lease_token() );
	}
}
