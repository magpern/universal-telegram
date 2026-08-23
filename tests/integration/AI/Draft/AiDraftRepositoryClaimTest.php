<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\AI\Draft;

use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * Docs/adr/0028 decision 5, §3.1-B/§3.3/§3.5 of the frozen plan: race-safe
 * concurrency claim, compare-and-set completion/release/failure, and the
 * stale-lease sweep's own atomic reclaim/exhaust primitives.
 */
final class AiDraftRepositoryClaimTest extends WP_UnitTestCase {

	private function repository(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
	}

	/**
	 * Explicit reset, not an assumption: the ai_drafts table is not
	 * reliably rolled back by WP_UnitTestCase's per-test transaction
	 * wrapping once a DDL statement elsewhere in the same run has forced
	 * an implicit commit — several of this class's own tests assert an
	 * exact concurrency cap, which a leaked 'generating' row from an
	 * earlier test would silently defeat.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_ai_drafts();
	}

	protected function tearDown(): void {
		$this->reset_ai_drafts();
		parent::tearDown();
	}

	private function reset_ai_drafts(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_claim_succeeds_on_a_queued_row_and_increments_attempt_count(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$claim = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );

		$this->assertNotNull( $claim );
		$this->assertSame( 1, $claim['attempt_count'] );

		$claimed = $repository->find( $claim['draft_id'] );
		$this->assertSame( 'generating', $claimed->status() );
		$this->assertNotNull( $claimed->lease_token() );
	}

	public function test_claim_fails_when_the_concurrency_cap_is_already_reached(): void {
		$repository     = $this->repository();
		$conversation_a = $this->conversation_id();
		$conversation_b = $this->conversation_id();
		$conversation_c = $this->conversation_id();

		$draft_a = $repository->create( $conversation_a, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$draft_b = $repository->create( $conversation_b, 1, 'openai', 'gpt-4o-mini', 'v1' );
		$draft_c = $repository->create( $conversation_c, 1, 'openai', 'gpt-4o-mini', 'v1' );

		$claim_a = $repository->claim_for_generation( $draft_a->draft_uuid(), 90, 2 );
		$claim_b = $repository->claim_for_generation( $draft_b->draft_uuid(), 90, 2 );
		$this->assertNotNull( $claim_a );
		$this->assertNotNull( $claim_b );

		// Cap is 2; a third concurrent claim must be refused, not queued
		// past the limit.
		$claim_c = $repository->claim_for_generation( $draft_c->draft_uuid(), 90, 2 );
		$this->assertNull( $claim_c );

		$still_queued = $repository->find( $draft_c->id() );
		$this->assertSame( 'queued', $still_queued->status() );
	}

	public function test_claim_reclaims_a_generating_row_whose_lease_has_already_expired(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$first = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );
		$this->assertNotNull( $first );

		$this->force_lease_expired( $draft->id() );

		$second = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );

		$this->assertNotNull( $second );
		$this->assertNotSame( $first['lease_token'], $second['lease_token'] );
		$this->assertSame( 2, $second['attempt_count'] );
	}

	public function test_complete_generation_is_a_no_op_for_a_stale_lease_token(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$claim = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );
		$this->assertNotNull( $claim );

		$this->force_lease_expired( $draft->id() );
		$reclaimed = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );
		$this->assertNotNull( $reclaimed );

		// The first (now stale/superseded) worker's completion must be
		// silently discarded — the reclaiming worker's outcome is
		// authoritative.
		$won = $repository->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'stale text', '[]', str_repeat( 'a', 64 ), 'v1' );
		$this->assertFalse( $won );

		$current = $repository->find( $draft->id() );
		$this->assertSame( 'generating', $current->status() );
		$this->assertSame( $reclaimed['lease_token'], $current->lease_token() );
	}

	public function test_release_to_queued_preserves_attempt_count(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$claim = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );
		$this->assertTrue( $repository->release_to_queued( $claim['draft_id'], $claim['lease_token'] ) );

		$released = $repository->find( $claim['draft_id'] );
		$this->assertSame( 'queued', $released->status() );
		$this->assertNull( $released->lease_token() );
		$this->assertSame( 1, $released->attempt_count() );
	}

	public function test_fail_with_lease_token_dead_letters_and_clears_lease(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim      = $repository->claim_for_generation( $draft->draft_uuid(), 90, 2 );

		$this->assertTrue( $repository->fail( $claim['draft_id'], $claim['lease_token'], 'provider_terminal_error' ) );

		$failed = $repository->find( $claim['draft_id'] );
		$this->assertSame( 'failed', $failed->status() );
		$this->assertSame( 'provider_terminal_error', $failed->failure_class() );
		$this->assertNull( $failed->lease_token() );
	}

	public function test_fail_without_a_lease_token_matches_a_pre_claim_queued_row(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$this->assertTrue( $repository->fail( $draft->id(), null, 'circuit_open' ) );

		$failed = $repository->find( $draft->id() );
		$this->assertSame( 'failed', $failed->status() );
		$this->assertSame( 'circuit_open', $failed->failure_class() );
	}

	public function test_find_stale_generating_only_returns_expired_leases(): void {
		$repository = $this->repository();
		$fresh      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$stale      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$repository->claim_for_generation( $fresh->draft_uuid(), 90, 5 );
		$repository->claim_for_generation( $stale->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $stale->id() );

		$candidates = $repository->find_stale_generating();
		$ids        = array_map( static fn( $d ) => $d->id(), $candidates );

		$this->assertContains( $stale->id(), $ids );
		$this->assertNotContains( $fresh->id(), $ids );
	}

	public function test_try_reclaim_stale_re_arms_below_the_attempt_budget(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$repository->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $draft->id() );

		$this->assertTrue( $repository->try_reclaim_stale( $draft->id() ) );

		$reclaimed = $repository->find( $draft->id() );
		$this->assertSame( 'queued', $reclaimed->status() );
		$this->assertNull( $reclaimed->lease_token() );
	}

	public function test_try_reclaim_stale_is_idempotent_under_a_second_call(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$repository->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $draft->id() );

		$this->assertTrue( $repository->try_reclaim_stale( $draft->id() ) );
		// The row is now 'queued', not 'generating' with an expired lease
		// — a second overlapping sweep call must not match it again.
		$this->assertFalse( $repository->try_reclaim_stale( $draft->id() ) );
	}

	public function test_try_exhaust_stale_dead_letters_as_crashed_exhausted(): void {
		$repository = $this->repository();
		$draft      = $repository->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$repository->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$this->force_lease_expired( $draft->id() );

		$this->assertTrue( $repository->try_exhaust_stale( $draft->id() ) );

		$failed = $repository->find( $draft->id() );
		$this->assertSame( 'failed', $failed->status() );
		$this->assertSame( 'crashed_exhausted', $failed->failure_class() );
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
}
