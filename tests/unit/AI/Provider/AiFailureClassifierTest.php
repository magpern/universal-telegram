<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\AI\Provider;

use UniversalTelegram\AI\Provider\AiFailureClassification;
use UniversalTelegram\AI\Provider\AiFailureClassifier;
use UniversalTelegram\AI\Provider\AiResult;
use PHPUnit\Framework\TestCase;

/**
 * docs/adr/0028 decision 5: mirrors TelegramFailureClassifierTest's exact
 * structure. No WordPress bootstrap needed — pure classification logic.
 */
final class AiFailureClassifierTest extends TestCase {

	private function classifier(): AiFailureClassifier {
		return new AiFailureClassifier();
	}

	public function test_network_error_is_retryable(): void {
		$result = new AiResult( false, null, false, null, true );

		$this->assertSame( AiFailureClassification::RETRYABLE, $this->classifier()->classify( $result ) );
	}

	public function test_401_is_token_invalid(): void {
		$result = new AiResult( false, null, false, 401, false );

		$this->assertSame( AiFailureClassification::TOKEN_INVALID, $this->classifier()->classify( $result ) );
	}

	public function test_429_is_retryable(): void {
		$result = new AiResult( false, null, false, 429, false );

		$this->assertSame( AiFailureClassification::RETRYABLE, $this->classifier()->classify( $result ) );
	}

	public function test_400_is_terminal(): void {
		$result = new AiResult( false, null, false, 400, false );

		$this->assertSame( AiFailureClassification::TERMINAL, $this->classifier()->classify( $result ) );
	}

	public function test_403_is_terminal(): void {
		$result = new AiResult( false, null, false, 403, false );

		$this->assertSame( AiFailureClassification::TERMINAL, $this->classifier()->classify( $result ) );
	}

	public function test_500_is_retryable(): void {
		$result = new AiResult( false, null, false, 500, false );

		$this->assertSame( AiFailureClassification::RETRYABLE, $this->classifier()->classify( $result ) );
	}

	public function test_503_is_retryable(): void {
		$result = new AiResult( false, null, false, 503, false );

		$this->assertSame( AiFailureClassification::RETRYABLE, $this->classifier()->classify( $result ) );
	}
}
