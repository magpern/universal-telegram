<?php
/**
 * Unit tests for Support Chat adapter discovery.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\DiscoveryClient
 */
final class DiscoveryClientTest extends TestCase {

	public function test_disabled_when_flag_off(): void {
		$client = new DiscoveryClient();
		$this->assertSame( AdapterAvailability::Disabled, $client->resolve( false ) );
	}

	public function test_contract_constants_pin(): void {
		$this->assertSame( 'support-channel-contract/v1', ContractConstants::CONTRACT_VERSION_ID );
		$this->assertSame( 'dff2730e24b7d3f70f15f706305e12e14fdcc6c8', ContractConstants::CONTRACT_PIN_SHA );
		$this->assertStringContainsString( '0005-canonical-support-channel-contract-v1.md', ContractConstants::CONTRACT_PIN_URL );
	}
}
