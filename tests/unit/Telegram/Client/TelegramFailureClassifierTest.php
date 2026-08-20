<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Telegram\Client;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Client\FailureClassification;
use UniversalTelegram\Telegram\Client\TelegramApiResult;
use UniversalTelegram\Telegram\Client\TelegramFailureClassifier;

final class TelegramFailureClassifierTest extends TestCase {

	/**
	 * @var TelegramFailureClassifier
	 */
	private TelegramFailureClassifier $classifier;

	protected function setUp(): void {
		$this->classifier = new TelegramFailureClassifier();
	}

	public function test_network_transport_failure_is_retryable(): void {
		$result = new TelegramApiResult( false, null, null, null, null, null, true );

		$this->assertSame( FailureClassification::RETRYABLE, $this->classifier->classify( $result ) );
	}

	public function test_429_is_rate_limited(): void {
		$result = new TelegramApiResult( false, 429, null, 429, 'Too Many Requests', array( 'retry_after' => 5 ), false );

		$this->assertSame( FailureClassification::RATE_LIMITED, $this->classifier->classify( $result ) );
	}

	public function test_401_is_token_invalid(): void {
		$result = new TelegramApiResult( false, 401, null, 401, 'Unauthorized', null, false );

		$this->assertSame( FailureClassification::TOKEN_INVALID, $this->classifier->classify( $result ) );
	}

	public function test_403_is_terminal(): void {
		$result = new TelegramApiResult( false, 403, null, 403, 'Forbidden: bot was blocked by the user', null, false );

		$this->assertSame( FailureClassification::TERMINAL, $this->classifier->classify( $result ) );
	}

	public function test_400_is_terminal(): void {
		$result = new TelegramApiResult( false, 400, null, 400, 'Bad Request: chat not found', null, false );

		$this->assertSame( FailureClassification::TERMINAL, $this->classifier->classify( $result ) );
	}

	public function test_500_is_retryable(): void {
		$result = new TelegramApiResult( false, 500, null, null, null, null, false );

		$this->assertSame( FailureClassification::RETRYABLE, $this->classifier->classify( $result ) );
	}

	public function test_retry_after_is_read_when_present_and_valid(): void {
		$result = new TelegramApiResult( false, 429, null, 429, 'Too Many Requests', array( 'retry_after' => 12 ), false );

		$this->assertSame( 12, $result->retry_after() );
	}

	/**
	 * @dataProvider invalid_retry_after_provider
	 */
	public function test_retry_after_falls_back_to_null_when_absent_non_integer_or_non_positive( ?array $parameters ): void {
		$result = new TelegramApiResult( false, 429, null, 429, 'Too Many Requests', $parameters, false );

		$this->assertNull( $result->retry_after() );
	}

	/**
	 * @return array<string, array{0: array<string, mixed>|null}>
	 */
	public function invalid_retry_after_provider(): array {
		return array(
			'absent'      => array( null ),
			'zero'        => array( array( 'retry_after' => 0 ) ),
			'negative'    => array( array( 'retry_after' => -5 ) ),
			'non_integer' => array( array( 'retry_after' => 'soon' ) ),
		);
	}
}
