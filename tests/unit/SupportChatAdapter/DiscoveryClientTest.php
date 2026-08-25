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

	public function test_version_match_but_channel_unavailable_is_unavailable(): void {
		$client = new DiscoveryClient();
		$result = $client->evaluate(
			array(
				'contract_version'  => ContractConstants::CONTRACT_VERSION_ID,
				'channel_available' => false,
				'operations'        => ContractConstants::required_operations(),
			)
		);
		$this->assertSame( AdapterAvailability::Unavailable, $result );
	}

	public function test_missing_capability_is_unavailable(): void {
		$client     = new DiscoveryClient();
		$operations = ContractConstants::required_operations();
		array_pop( $operations );

		$result = $client->evaluate(
			array(
				'contract_version'  => ContractConstants::CONTRACT_VERSION_ID,
				'channel_available' => true,
				'operations'        => $operations,
			)
		);
		$this->assertSame( AdapterAvailability::Unavailable, $result );
	}

	public function test_incompatible_version_is_unavailable(): void {
		$client = new DiscoveryClient();
		$result = $client->evaluate(
			array(
				'contract_version'  => 'support-channel-contract/v0',
				'channel_available' => true,
				'operations'        => ContractConstants::required_operations(),
			)
		);
		$this->assertSame( AdapterAvailability::Unavailable, $result );
	}

	public function test_fully_compatible_capability_response(): void {
		$client = new DiscoveryClient();
		$result = $client->evaluate(
			array(
				'ok'                => true,
				'contract_version'  => ContractConstants::CONTRACT_VERSION_ID,
				'channel_available' => true,
				'operations'        => ContractConstants::required_operations(),
			)
		);
		$this->assertSame( AdapterAvailability::Compatible, $result );
	}
}
