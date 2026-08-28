<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Telegram\Commands;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Commands\CommandCatalogue;

final class CommandCatalogueTest extends TestCase {

	public function test_the_nine_transport_only_commands_are_known_and_no_conversation_command_survives(): void {
		$expected = array( 'help', 'whoami', 'status', 'errors', 'visitors', 'orders', 'order', 'stock', 'sales' );

		foreach ( $expected as $command ) {
			$this->assertTrue( CommandCatalogue::is_known( $command ), "expected '$command' to be known" );
		}

		$this->assertCount( 9, CommandCatalogue::all_commands() );

		foreach ( array( 'conversations', 'here', 'presence', 'claim', 'release', 'resolve', 'reopen', 'confirm' ) as $removed ) {
			$this->assertFalse( CommandCatalogue::is_known( $removed ), "'$removed' must have been removed by ADR-0044" );
		}
	}

	public function test_unknown_command_is_not_known(): void {
		$this->assertFalse( CommandCatalogue::is_known( 'shell' ) );
		$this->assertFalse( CommandCatalogue::is_known( 'eval' ) );
		$this->assertFalse( CommandCatalogue::is_known( '' ) );
	}

	public function test_context_requirements(): void {
		$this->assertSame( CommandCatalogue::CONTEXT_ANY, CommandCatalogue::context_for( 'help' ) );
		$this->assertSame( CommandCatalogue::CONTEXT_ANY, CommandCatalogue::context_for( 'whoami' ) );
		$this->assertSame( CommandCatalogue::CONTEXT_GENERAL, CommandCatalogue::context_for( 'status' ) );
		$this->assertNull( CommandCatalogue::context_for( 'nope' ) );
	}

	public function test_no_argument_commands_reject_any_trailing_text(): void {
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'status', '' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'status', 'extra' ) );
	}

	public function test_order_argument_is_bounded_numeric(): void {
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'order', '12345' ) );
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'order', str_repeat( '1', 20 ) ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'order', str_repeat( '1', 21 ) ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'order', '' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'order', 'abc' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'order', '-1' ) );
	}

	public function test_stock_argument_is_a_bounded_token_without_wildcards(): void {
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'stock', 'SKU-123' ) );
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'stock', str_repeat( 'a', 100 ) ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'stock', str_repeat( 'a', 101 ) ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'stock', '' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'stock', 'SKU%' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'stock', 'SKU*' ) );
	}

	public function test_sales_argument_is_one_of_three_fixed_literals(): void {
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'sales', 'today' ) );
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'sales', 'week' ) );
		$this->assertTrue( CommandCatalogue::is_argument_valid( 'sales', 'month' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'sales', 'year' ) );
		$this->assertFalse( CommandCatalogue::is_argument_valid( 'sales', '' ) );
	}

	public function test_commands_valid_in_general_context_are_the_transport_commands(): void {
		$general = CommandCatalogue::commands_valid_in( CommandCatalogue::CONTEXT_GENERAL );

		$this->assertContains( 'help', $general );
		$this->assertContains( 'whoami', $general );
		$this->assertContains( 'status', $general );
		$this->assertContains( 'orders', $general );
		$this->assertNotContains( 'claim', $general );
		$this->assertNotContains( 'conversations', $general );
	}
}
