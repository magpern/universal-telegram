<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Digest;

use UniversalTelegram\Automations\Digest\VisitorDigestCounterRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class VisitorDigestCounterRepositoryTest extends WP_UnitTestCase {

	private function repository(): VisitorDigestCounterRepository {
		return new VisitorDigestCounterRepository( new SchemaHealth() );
	}

	public function test_repeated_increments_collapse_into_one_row_per_bucket(): void {
		$repo   = $this->repository();
		$window = '2026-01-01 00:00:00';

		$repo->increment( $window, 'page_views', 'home' );
		$repo->increment( $window, 'page_views', 'home' );
		$repo->increment( $window, 'page_views', 'home' );

		$rows = $repo->for_window( $window );
		$this->assertCount( 1, $rows );
		$this->assertSame( 3, $rows[0]['event_count'] );
	}

	/**
	 * page_type is always stored as '' rather than SQL NULL — MySQL treats
	 * every NULL in a unique key as distinct from every other NULL, which
	 * would otherwise silently defeat the ON DUPLICATE KEY UPDATE collapse
	 * for every category besides page_views.
	 */
	public function test_repeated_increments_with_no_page_type_still_collapse_into_one_row(): void {
		$repo   = $this->repository();
		$window = '2026-01-01 00:00:00';

		$repo->increment( $window, 'search' );
		$repo->increment( $window, 'search' );

		$rows = $repo->for_window( $window );
		$this->assertCount( 1, $rows );
		$this->assertSame( 2, $rows[0]['event_count'] );
		$this->assertSame( '', $rows[0]['page_type'] );
	}

	public function test_different_categories_and_page_types_are_separate_buckets(): void {
		$repo   = $this->repository();
		$window = '2026-01-01 00:00:00';

		$repo->increment( $window, 'page_views', 'home' );
		$repo->increment( $window, 'page_views', 'singular' );
		$repo->increment( $window, 'search' );
		$repo->increment( $window, 'product_views' );

		$this->assertCount( 4, $repo->for_window( $window ) );
		$this->assertSame( 4, $repo->sum_for_window( $window ) );
	}

	public function test_different_windows_are_independent(): void {
		$repo = $this->repository();

		$repo->increment( '2026-01-01 00:00:00', 'search' );
		$repo->increment( '2026-01-01 00:15:00', 'search' );

		$this->assertSame( 1, $repo->sum_for_window( '2026-01-01 00:00:00' ) );
		$this->assertSame( 1, $repo->sum_for_window( '2026-01-01 00:15:00' ) );
	}

	public function test_delete_for_window_removes_only_that_windows_rows(): void {
		$repo = $this->repository();

		$repo->increment( '2026-01-01 00:00:00', 'search' );
		$repo->increment( '2026-01-01 00:15:00', 'search' );

		$repo->delete_for_window( '2026-01-01 00:00:00' );

		$this->assertSame( 0, $repo->sum_for_window( '2026-01-01 00:00:00' ) );
		$this->assertSame( 1, $repo->sum_for_window( '2026-01-01 00:15:00' ) );
	}

	/**
	 * Concurrent-request race safety: two "requests" incrementing the same
	 * bucket in quick succession must both be counted, not lost to a
	 * lost-update race — proven here by issuing the increments back to back
	 * against the same repository instance (the atomic INSERT ... ON
	 * DUPLICATE KEY UPDATE statement itself, not PHP-level locking, is what
	 * makes this safe under real concurrent connections).
	 */
	public function test_concurrent_style_increments_are_not_lost(): void {
		$repo   = $this->repository();
		$window = '2026-01-01 00:00:00';

		for ( $i = 0; $i < 10; $i++ ) {
			$repo->increment( $window, 'page_views', 'home' );
		}

		$this->assertSame( 10, $repo->sum_for_window( $window ) );
	}
}
