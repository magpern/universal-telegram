<?php
/**
 * Unit tests for fail-closed Support Chat Contract client.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;

/**
 * @covers \UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient
 */
final class SupportChatContractClientTest extends TestCase {

	public function test_lifecycle_calls_fail_closed_without_authenticated_server(): void {
		$client = new SupportChatContractClient();

		foreach (
			array(
				$client->ingest_operator_reply( 'a', 'k', 'body', 1 ),
				$client->claim( 'a', 1, 'k' ),
				$client->release( 'a', 1, 'k' ),
				$client->resolve( 'a', 1, 'k' ),
				$client->reopen( 'a', 1, 'k' ),
				$client->report_channel_unavailable( 'a', 'adapter_deactivated' ),
				$client->report_delivery_failure( 'a', 'k', 'failed' ),
			) as $result
		) {
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 503, $result['status'] );
			$this->assertSame( SupportChatContractClient::UNAVAILABLE_REASON, $result['reason'] );
		}
	}
}
