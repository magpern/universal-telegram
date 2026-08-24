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
}
