<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Draft\DraftRequestHandler;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\WorkerRunner;
use ActionScheduler;
use WP_UnitTestCase;

/**
 * Docs/adr/0028 decisions 1 and 5: full eligibility gating (AI
 * disabled/unacknowledged/not found), conversation-row-locked one-active-
 * draft idempotency under a simulated concurrent duplicate request, the
 * one-rule cooldown, and the reject-while-retained rule.
 */
final class DraftRequestHandlerTest extends WP_UnitTestCase {

	/**
	 * Explicit reset, not an assumption: the singleton ai_config row, the
	 * ai_drafts table, and Action Scheduler's own tables are not reliably
	 * rolled back by WP_UnitTestCase's per-test transaction wrapping once
	 * a DDL statement elsewhere in the same run has forced an implicit
	 * commit — the same root cause already documented elsewhere in this
	 * suite for plain option writes, and the same reason
	 * Queue\QueueHealthTest resets Action Scheduler's group in setUp().
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
		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET enabled = 0, model = '', api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );
		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}
	}

	private function drafts(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function provider(): AIProviderRepository {
		return new AIProviderRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversations(): ConversationRepository {
		return new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
	}

	private function handler(): DraftRequestHandler {
		return new DraftRequestHandler(
			$this->drafts(),
			$this->provider(),
			$this->conversations(),
			new Dispatcher( new SchemaHealth() )
		);
	}

	private function enable_ai(): string {
		$provider = $this->provider();
		$provider->set_credential( 'sk-test-key' );
		$provider->update_settings( 'gpt-4o-mini', true );

		return $provider->get()->ack_policy_version();
	}

	private function acknowledged_conversation_id( string $ack_policy_version ): int {
		global $wpdb;
		$conversation = $this->conversations()->create( wp_generate_uuid4(), 'hashed-secret', 1, null );
		$wpdb->update(
			$wpdb->prefix . Migrator::CONVERSATIONS_TABLE,
			array( 'ai_ack_policy_version' => $ack_policy_version ),
			array( 'id' => $conversation->id() )
		);

		return $conversation->id();
	}

	public function test_rejects_when_ai_is_disabled(): void {
		$conversation_id = $this->conversations()->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'ai_disabled', $outcome );
	}

	public function test_rejects_an_unacknowledged_conversation_even_when_ai_is_enabled(): void {
		$this->enable_ai();
		$conversation_id = $this->conversations()->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'not_acknowledged', $outcome );
	}

	public function test_rejects_a_stale_ack_policy_version(): void {
		$this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( 'a-different-version' );

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'not_acknowledged', $outcome );
	}

	public function test_rejects_an_unknown_conversation(): void {
		$this->enable_ai();

		$outcome = $this->handler()->request( 999999, 1 );

		$this->assertSame( 'not_found', $outcome );
	}

	public function test_creates_a_queued_draft_and_enqueues_a_job(): void {
		$ack_version     = $this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( $ack_version );

		$outcome = $this->handler()->request( $conversation_id, 42 );

		$this->assertSame( 'created', $outcome );

		$draft = $this->drafts()->find_active_for_conversation( $conversation_id );
		$this->assertNotNull( $draft );
		$this->assertSame( 'queued', $draft->status() );
		$this->assertSame( 42, $draft->requested_by_user_id() );
		$this->assertNotNull( $draft->job_reference() );
	}

	public function test_a_duplicate_request_while_one_is_active_returns_the_same_draft_idempotently(): void {
		$ack_version     = $this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( $ack_version );

		$handler = $this->handler();
		$this->assertSame( 'created', $handler->request( $conversation_id, 1 ) );

		$first_active = $this->drafts()->find_active_for_conversation( $conversation_id );

		// A second, near-simultaneous request must observe the existing
		// active draft, not create a duplicate row.
		$this->assertSame( 'existing_active', $handler->request( $conversation_id, 1 ) );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}" . Migrator::AI_DRAFTS_TABLE . ' WHERE conversation_id = %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$conversation_id
			)
		);
		$this->assertSame( 1, $count );

		$still_active = $this->drafts()->find_active_for_conversation( $conversation_id );
		$this->assertSame( $first_active->draft_uuid(), $still_active->draft_uuid() );
	}

	public function test_rejects_while_a_generated_draft_is_retained(): void {
		$ack_version     = $this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( $ack_version );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $conversation_id, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim  = $drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'a draft body', '[]', str_repeat( 'a', 64 ), 'v1' );

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'rejected_retained', $outcome );
	}

	public function test_cooldown_rejects_a_request_within_30_seconds_of_a_failure(): void {
		$ack_version     = $this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( $ack_version );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $conversation_id, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$drafts->fail( $draft->id(), null, 'no_matching_source' );

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'rejected_cooldown', $outcome );
	}

	public function test_no_cooldown_applies_after_an_explicit_discard(): void {
		$ack_version     = $this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( $ack_version );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $conversation_id, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim  = $drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'body', '[]', str_repeat( 'a', 64 ), 'v1' );
		$drafts->mark_discarded( $draft->id(), 1 );

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'created', $outcome, 'Discard must not impose a cooldown, unlike a failure.' );
	}

	public function test_a_request_after_the_cooldown_window_elapses_succeeds(): void {
		$ack_version     = $this->enable_ai();
		$conversation_id = $this->acknowledged_conversation_id( $ack_version );

		$drafts = $this->drafts();
		$draft  = $drafts->create( $conversation_id, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$drafts->fail( $draft->id(), null, 'no_matching_source' );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Migrator::AI_DRAFTS_TABLE,
			array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 31 ) ),
			array( 'id' => $draft->id() )
		);

		$outcome = $this->handler()->request( $conversation_id, 1 );

		$this->assertSame( 'created', $outcome );
	}
}
