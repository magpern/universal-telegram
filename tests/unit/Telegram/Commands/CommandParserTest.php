<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Telegram\Commands;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Commands\CommandParser;

final class CommandParserTest extends TestCase {

	private function message( string $text, array $entities ): array {
		return array(
			'text'     => $text,
			'entities' => $entities,
		);
	}

	public function test_a_bare_command_at_offset_zero_is_recognized(): void {
		$message = $this->message( '/help', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 5 ) ) );

		$parsed = CommandParser::parse( $message, null );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'help', $parsed->command() );
		$this->assertSame( '', $parsed->raw_argument() );
		$this->assertTrue( $parsed->is_argument_valid() );
	}

	public function test_own_bot_username_suffix_matches_case_insensitively(): void {
		$message = $this->message( '/help@MyBot', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 11 ) ) );

		$parsed = CommandParser::parse( $message, 'mybot' );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'help', $parsed->command() );
	}

	public function test_other_bot_username_suffix_is_not_a_command_for_us(): void {
		$message = $this->message( '/help@othersbot', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 15 ) ) );

		$this->assertNull( CommandParser::parse( $message, 'mybot' ) );
	}

	public function test_username_suffix_with_no_persisted_bot_username_never_matches(): void {
		$message = $this->message( '/help@anything', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 14 ) ) );

		$this->assertNull( CommandParser::parse( $message, null ) );
	}

	public function test_no_entities_is_not_a_command(): void {
		$this->assertNull( CommandParser::parse( array( 'text' => '/help' ), null ) );
	}

	public function test_entity_not_at_offset_zero_is_not_a_command(): void {
		$message = $this->message( 'hey /help', array( array( 'type' => 'bot_command', 'offset' => 4, 'length' => 5 ) ) );

		$this->assertNull( CommandParser::parse( $message, null ) );
	}

	public function test_entity_of_a_different_type_is_not_a_command(): void {
		$message = $this->message( '/help', array( array( 'type' => 'mention', 'offset' => 0, 'length' => 5 ) ) );

		$this->assertNull( CommandParser::parse( $message, null ) );
	}

	public function test_unknown_command_word_is_not_recognized(): void {
		$message = $this->message( '/shell', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 6 ) ) );

		$this->assertNull( CommandParser::parse( $message, null ) );
	}

	public function test_near_miss_text_starting_with_slash_but_no_entity_falls_through(): void {
		$this->assertNull( CommandParser::parse( array( 'text' => '/not a real command, just chat' ), null ) );
	}

	public function test_argument_is_extracted_and_trimmed(): void {
		$message = $this->message( '/order  12345 ', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 6 ) ) );

		$parsed = CommandParser::parse( $message, null );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'order', $parsed->command() );
		$this->assertSame( '12345', $parsed->raw_argument() );
		$this->assertTrue( $parsed->is_argument_valid() );
	}

	public function test_malformed_argument_still_yields_a_recognized_command(): void {
		$message = $this->message( '/order abc', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 6 ) ) );

		$parsed = CommandParser::parse( $message, null );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'order', $parsed->command() );
		$this->assertFalse( $parsed->is_argument_valid() );
	}

	public function test_unexpected_trailing_text_on_a_no_argument_command_is_malformed(): void {
		$message = $this->message( '/claim please', array( array( 'type' => 'bot_command', 'offset' => 0, 'length' => 6 ) ) );

		$parsed = CommandParser::parse( $message, null );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'claim', $parsed->command() );
		$this->assertFalse( $parsed->is_argument_valid() );
	}
}
