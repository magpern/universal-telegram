<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Digest;

use UniversalTelegram\Automations\Digest\VisitorDigestStateRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class VisitorDigestStateRepositoryTest extends WP_UnitTestCase {

	private function repository(): VisitorDigestStateRepository {
		return new VisitorDigestStateRepository( new SchemaHealth() );
	}

	public function test_no_window_is_open_immediately_after_migration(): void {
		$this->assertNull( $this->repository()->current_window_started_at() );
	}

	public function test_open_window_if_needed_opens_a_window_once(): void {
		$repo = $this->repository();

		$opened = $repo->open_window_if_needed( '2026-01-01 00:00:00' );

		$this->assertSame( '2026-01-01 00:00:00', $opened );
		$this->assertSame( '2026-01-01 00:00:00', $repo->current_window_started_at() );
	}

	/**
	 * A second call while a window is already open must not overwrite the
	 * original open timestamp — the atomic conditional UPDATE's WHERE
	 * window_started_at IS NULL clause makes this a safe no-op, which is
	 * exactly the concurrent-request race-safety property the window-open
	 * mechanism depends on.
	 */
	public function test_a_second_call_does_not_reopen_an_already_open_window(): void {
		$repo = $this->repository();

		$repo->open_window_if_needed( '2026-01-01 00:00:00' );
		$second = $repo->open_window_if_needed( '2026-01-01 00:05:00' );

		$this->assertSame( '2026-01-01 00:00:00', $second );
		$this->assertSame( '2026-01-01 00:00:00', $repo->current_window_started_at() );
	}

	/**
	 * The claim compare-and-set is the sole admission mutex preventing two
	 * concurrent sweep ticks from both sending the same window
	 * (docs/plans/m11a-visitor-activity-digests-plan-v1.md §5): a second
	 * claim attempt against the same still-open window must fail while the
	 * first claim's lease has not yet expired.
	 */
	public function test_a_second_claim_attempt_fails_while_the_first_claims_lease_is_unexpired(): void {
		$repo   = $this->repository();
		$window = $repo->open_window_if_needed( '2026-01-01 00:00:00' );

		$first_claim_won  = $repo->try_claim_for_send( $window, 'token-a', gmdate( 'Y-m-d H:i:s', time() + 120 ) );
		$second_claim_won = $repo->try_claim_for_send( $window, 'token-b', gmdate( 'Y-m-d H:i:s', time() + 120 ) );

		$this->assertTrue( $first_claim_won );
		$this->assertFalse( $second_claim_won );
	}

	/**
	 * Once the first claim's lease has expired, a fresh claim attempt must
	 * succeed — otherwise a crashed worker mid-send would permanently
	 * freeze the window.
	 */
	public function test_a_claim_can_be_reacquired_after_the_prior_lease_expires(): void {
		$repo   = $this->repository();
		$window = $repo->open_window_if_needed( '2026-01-01 00:00:00' );

		$repo->try_claim_for_send( $window, 'token-a', gmdate( 'Y-m-d H:i:s', time() - 5 ) );

		$this->assertTrue( $repo->try_claim_for_send( $window, 'token-b', gmdate( 'Y-m-d H:i:s', time() + 120 ) ) );
	}

	public function test_close_window_after_send_only_applies_to_the_matching_claim(): void {
		$repo   = $this->repository();
		$window = $repo->open_window_if_needed( '2026-01-01 00:00:00' );
		$repo->try_claim_for_send( $window, 'token-a', gmdate( 'Y-m-d H:i:s', time() + 120 ) );

		$this->assertFalse( $repo->close_window_after_send( $window, 'wrong-token', gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertSame( $window, $repo->current_window_started_at() );

		$this->assertTrue( $repo->close_window_after_send( $window, 'token-a', gmdate( 'Y-m-d H:i:s' ) ) );
		$this->assertNull( $repo->current_window_started_at() );
		$this->assertSame( 'sent', $repo->last_digest_status() );
	}

	public function test_release_claim_after_failure_leaves_window_open_and_records_status(): void {
		$repo   = $this->repository();
		$window = $repo->open_window_if_needed( '2026-01-01 00:00:00' );
		$repo->try_claim_for_send( $window, 'token-a', gmdate( 'Y-m-d H:i:s', time() + 120 ) );

		$repo->release_claim_after_failure( 'token-a', 'send_failed' );

		$this->assertSame( $window, $repo->current_window_started_at() );
		$this->assertSame( 'send_failed', $repo->last_digest_status() );
		// The lease is cleared, so a fresh claim attempt succeeds immediately.
		$this->assertTrue( $repo->try_claim_for_send( $window, 'token-b', gmdate( 'Y-m-d H:i:s', time() + 120 ) ) );
	}
}
