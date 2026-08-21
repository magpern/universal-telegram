<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Events\Emitters;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Events\Emitters\FatalErrorMarkerWriter;

final class FatalErrorMarkerWriterTest extends TestCase {

	public function test_never_throws_when_wpdb_is_unavailable(): void {
		// This unit-test process has no WordPress bootstrap at all, so the
		// $wpdb global is genuinely undefined here.
		$writer = new FatalErrorMarkerWriter();

		$writer->write_marker_for(
			array(
				'type'    => E_ERROR,
				'message' => 'Simulated fatal error.',
				'file'    => '/var/www/html/file.php',
				'line'    => 42,
			)
		);

		$this->assertTrue( true );
	}

	public function test_never_throws_for_a_null_error(): void {
		$writer = new FatalErrorMarkerWriter();

		$writer->write_marker_for( null );

		$this->assertTrue( true );
	}

	public function test_never_throws_for_a_non_fatal_error_type(): void {
		$writer = new FatalErrorMarkerWriter();

		$writer->write_marker_for(
			array(
				'type'    => E_WARNING,
				'message' => 'Simulated warning.',
				'file'    => '/var/www/html/file.php',
				'line'    => 42,
			)
		);

		$this->assertTrue( true );
	}
}
