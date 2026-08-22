<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Conversations;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Conversations\ConversationDisplay;

final class ConversationDisplayTest extends TestCase {

	public function test_is_valid_display_name_accepts_the_1_to_80_char_bound(): void {
		$this->assertTrue( ConversationDisplay::is_valid_display_name( 'A' ) );
		$this->assertTrue( ConversationDisplay::is_valid_display_name( str_repeat( 'a', 80 ) ) );
	}

	public function test_is_valid_display_name_rejects_empty_and_oversized(): void {
		$this->assertFalse( ConversationDisplay::is_valid_display_name( '' ) );
		$this->assertFalse( ConversationDisplay::is_valid_display_name( str_repeat( 'a', 81 ) ) );
	}

	public function test_is_valid_display_name_counts_utf8_characters_not_bytes(): void {
		// 80 multibyte characters (each 2+ bytes in UTF-8) must still be
		// accepted — a byte-oriented bound would reject this.
		$this->assertTrue( ConversationDisplay::is_valid_display_name( str_repeat( 'é', 80 ) ) );
		$this->assertFalse( ConversationDisplay::is_valid_display_name( str_repeat( 'é', 81 ) ) );
	}

	public function test_bounded_utf8_never_splits_a_multibyte_character(): void {
		$value = str_repeat( 'é', 10 );

		$this->assertSame( str_repeat( 'é', 5 ), ConversationDisplay::bounded_utf8( $value, 5 ) );
		$this->assertSame( 5, mb_strlen( ConversationDisplay::bounded_utf8( $value, 5 ), 'UTF-8' ) );
	}

	public function test_short_ref_is_the_first_eight_hex_characters(): void {
		$this->assertSame( 'abcdef01', ConversationDisplay::short_ref( 'abcdef01-2345-4678-9abc-def012345678' ) );
	}

	public function test_topic_title_falls_back_to_the_pre_m06_3_literal_when_no_name_is_stored(): void {
		$this->assertSame(
			'Conversation abcdef01-2345-4678-9abc-def012345678',
			ConversationDisplay::topic_title( null, 'abcdef01-2345-4678-9abc-def012345678' )
		);
	}

	public function test_topic_title_appends_the_short_reference_to_a_stored_name(): void {
		$this->assertSame(
			'Alice · abcdef01',
			ConversationDisplay::topic_title( 'Alice', 'abcdef01-2345-4678-9abc-def012345678' )
		);
	}

	public function test_topic_title_never_exceeds_telegrams_128_char_cap(): void {
		$long_name = str_repeat( 'a', 200 );

		$title = ConversationDisplay::topic_title( $long_name, 'abcdef01-2345-4678-9abc-def012345678' );

		$this->assertLessThanOrEqual( 128, mb_strlen( $title, 'UTF-8' ) );
		$this->assertStringEndsWith( ' · abcdef01', $title );
	}

	public function test_topic_title_boundary_is_unicode_safe_and_never_splits_a_character(): void {
		// A multibyte name long enough that a byte-oriented substr() would
		// split a character exactly at the 128-char boundary.
		$long_name = str_repeat( 'é', 200 );

		$title = ConversationDisplay::topic_title( $long_name, 'abcdef01-2345-4678-9abc-def012345678' );

		$this->assertLessThanOrEqual( 128, mb_strlen( $title, 'UTF-8' ) );
		$this->assertSame( $title, mb_convert_encoding( $title, 'UTF-8', 'UTF-8' ), 'Result must remain valid UTF-8 — no split multibyte sequence.' );
	}

	public function test_first_message_context_header_contains_only_name_and_short_ref(): void {
		$header = ConversationDisplay::first_message_context_header( 'Alice', 'abcdef01-2345-4678-9abc-def012345678' );

		$this->assertSame( '[Alice · abcdef01]', $header );
	}
}
