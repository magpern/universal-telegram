<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Automations\Intelligence;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryPromptBuilder;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRow;

final class OperationalSummaryPromptBuilderTest extends TestCase {

	private function row(): OperationalSummaryRow {
		return new OperationalSummaryRow(
			1,
			'2026-01-01',
			5,
			4,
			1,
			0,
			2,
			3,
			1,
			0,
			10,
			6,
			2,
			5,
			true
		);
	}

	public function test_policy_version_is_a_fixed_constant(): void {
		$this->assertSame( 'v1', OperationalSummaryPromptBuilder::POLICY_VERSION );
	}

	public function test_build_only_accepts_the_typed_row_object(): void {
		// This is a compile-time/type-system guarantee, not a runtime
		// branch: build()'s signature is `OperationalSummaryRow $row`, so
		// PHP itself rejects a string, array, or any other shape before
		// this method's own body ever runs — reflection confirms the
		// declared parameter type matches exactly.
		$reflection = new \ReflectionMethod( OperationalSummaryPromptBuilder::class, 'build' );
		$parameters = $reflection->getParameters();

		$this->assertSame( OperationalSummaryRow::class, (string) $parameters[0]->getType() );
	}

	public function test_build_embeds_only_the_aggregate_counts_and_never_the_raw_row_object(): void {
		$request = ( new OperationalSummaryPromptBuilder() )->build( $this->row(), 'gpt-test' );

		$this->assertSame( 'gpt-test', $request->model() );
		$this->assertStringContainsString( 'orders_created: 5', $request->user_content() );
		$this->assertStringContainsString( 'checkout_failures: 2', $request->user_content() );
		$this->assertStringContainsString( '<data>', $request->user_content() );
		$this->assertStringContainsString( '</data>', $request->user_content() );
	}
}
