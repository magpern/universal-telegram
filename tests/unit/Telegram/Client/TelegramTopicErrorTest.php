<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Telegram\Client;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Client\TelegramApiResult;
use UniversalTelegram\Telegram\Client\TelegramTopicError;

final class TelegramTopicErrorTest extends TestCase {

	public function test_send_failure_maps_missing_thread_when_thread_destination(): void {
		$this->assertSame(
			TelegramTopicError::TOPIC_NOT_FOUND,
			TelegramTopicError::classify_send_failure( 'Bad Request: message thread not found' )
		);
	}

	public function test_send_failure_maps_topic_closed(): void {
		$this->assertSame(
			TelegramTopicError::TOPIC_CLOSED,
			TelegramTopicError::classify_send_failure( 'Bad Request: TOPIC_CLOSED' )
		);
	}

	public function test_send_failure_keeps_chat_not_found_generic(): void {
		$this->assertSame(
			TelegramTopicError::TERMINAL_REJECTION,
			TelegramTopicError::classify_send_failure( 'Bad Request: chat not found' )
		);
	}

	public function test_send_failure_keeps_generic_400_generic(): void {
		$this->assertSame(
			TelegramTopicError::TERMINAL_REJECTION,
			TelegramTopicError::classify_send_failure( 'Bad Request: can\'t parse entities' )
		);
	}

	public function test_delete_missing_topic_is_already_absent(): void {
		$result = new TelegramApiResult( false, 400, null, 400, 'Bad Request: message thread not found', null, false );

		$this->assertTrue( TelegramTopicError::is_missing_topic_on_delete( $result ) );
		$this->assertSame( TelegramTopicError::TOPIC_NOT_FOUND, TelegramTopicError::classify_delete_failure( $result ) );
	}

	public function test_delete_chat_not_found_is_not_already_absent(): void {
		$result = new TelegramApiResult( false, 400, null, 400, 'Bad Request: chat not found', null, false );

		$this->assertFalse( TelegramTopicError::is_missing_topic_on_delete( $result ) );
		$this->assertSame( TelegramTopicError::TOPIC_DELETE_CHAT_NOT_FOUND, TelegramTopicError::classify_delete_failure( $result ) );
	}

	public function test_delete_forbidden_maps_to_forbidden_code(): void {
		$result = new TelegramApiResult( false, 403, null, 403, 'Forbidden: not enough rights to manage topics', null, false );

		$this->assertSame( TelegramTopicError::TOPIC_DELETE_FORBIDDEN, TelegramTopicError::classify_delete_failure( $result ) );
	}
}
