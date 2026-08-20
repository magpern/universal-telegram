<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Audit;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use WP_UnitTestCase;

final class AuditLoggerTest extends WP_UnitTestCase {

	public function test_a_recorded_entry_is_retrievable_afterward(): void {
		$schema_health = new SchemaHealth();
		$logger        = new AuditLogger( $schema_health, new Redactor() );
		$repository    = new AuditLogRepository( $schema_health );

		$recorded = $logger->record(
			'test.entry_recorded',
			'system',
			null,
			array(
				'note'   => 'hello',
				'secret' => 'must-not-appear',
			),
			array(
				'note'   => Classification::PUBLIC,
				'secret' => Classification::SECRET,
			),
			Classification::PUBLIC
		);

		$this->assertTrue( $recorded );

		$entries = $repository->recent( 5 );
		$this->assertNotEmpty( $entries );

		$latest = $entries[0];
		$this->assertSame( 'test.entry_recorded', $latest['action'] );
		$this->assertStringContainsString( 'hello', $latest['context'] );
		$this->assertStringNotContainsString( 'must-not-appear', $latest['context'] );
	}

	public function test_a_field_missing_from_the_classification_map_is_absent_from_the_persisted_context(): void {
		$schema_health = new SchemaHealth();
		$logger        = new AuditLogger( $schema_health, new Redactor() );
		$repository    = new AuditLogRepository( $schema_health );

		$logger->record(
			'test.unclassified_field',
			'system',
			null,
			array(
				'known'   => 'value',
				'unknown' => 'must-not-persist',
			),
			array(
				'known' => Classification::PUBLIC,
			),
			Classification::PUBLIC
		);

		$entries = $repository->recent( 1 );
		$this->assertStringNotContainsString( 'must-not-persist', $entries[0]['context'] );
	}

	public function test_recording_is_refused_while_schema_is_unavailable(): void {
		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

		$logger = new AuditLogger( $schema_health, new Redactor() );

		$recorded = $logger->record( 'test.should_not_write', 'system', null, array(), array(), Classification::PUBLIC );

		$this->assertFalse( $recorded );
	}
}
