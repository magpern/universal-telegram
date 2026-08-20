<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Persistence;

use Throwable;
use UniversalTelegram\Persistence\MigrationFailedException;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\MigrationLock;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

final class DegradedModeTest extends WP_UnitTestCase {

	public function test_a_migration_failure_marks_schema_unavailable_without_crashing_the_request(): void {
		$schema_health = new SchemaHealth();

		$migrator = new class( new MigrationLock() ) extends Migrator {
			protected function run_step( int $number ): void {
				throw new MigrationFailedException( MigrationFailureCode::STEP_FAILED );
			}
		};

		delete_option( 'universal_telegram_db_version' );

		$unexpected_exception_escaped = false;

		// This mirrors exactly what Core\Plugin::init() does on every
		// request: an ordinary "frontend request" continues after this
		// block regardless of the outcome.
		try {
			$migrator->maybe_migrate();
		} catch ( MigrationFailedException $exception ) {
			$schema_health->mark_unavailable( $exception->failure_code() );
		} catch ( Throwable $unexpected ) {
			$unexpected_exception_escaped = true;
		}

		$this->assertFalse( $unexpected_exception_escaped );
		$this->assertFalse( $schema_health->is_available() );
		$this->assertSame( MigrationFailureCode::STEP_FAILED, $schema_health->failure_code() );
	}

	public function test_a_degraded_surface_may_only_render_the_stable_failure_code(): void {
		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

		$rendered = $schema_health->is_available()
			? 'ok'
			: sprintf( 'Schema unavailable: %s', $schema_health->failure_code()->value );

		$this->assertSame( 'Schema unavailable: step_failed', $rendered );
		$this->assertStringNotContainsStringIgnoringCase( 'sql', $rendered );
		$this->assertStringNotContainsStringIgnoringCase( 'mysql', $rendered );
	}
}
