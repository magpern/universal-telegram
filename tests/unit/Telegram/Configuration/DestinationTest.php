<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Telegram\Configuration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\InvalidDestinationException;

final class DestinationTest extends TestCase {

	public function test_message_thread_id_is_permitted_on_a_supergroup(): void {
		$destination = new Destination( 1, 1, DestinationKind::SUPERGROUP, '-100123', 55, 'Support Topic', true, 'now' );

		$this->assertSame( 55, $destination->message_thread_id() );
		$this->assertSame( DestinationKind::SUPERGROUP, $destination->kind() );
	}

	/**
	 * @dataProvider non_supergroup_kinds_provider
	 */
	public function test_message_thread_id_is_rejected_on_every_other_kind( DestinationKind $kind ): void {
		$this->expectException( InvalidDestinationException::class );

		new Destination( 1, 1, $kind, 'chat-id', 55, 'Label', true, 'now' );
	}

	/**
	 * @return array<string, array{0: DestinationKind}>
	 */
	public function non_supergroup_kinds_provider(): array {
		return array(
			'private' => array( DestinationKind::PRIVATE ),
			'group'   => array( DestinationKind::GROUP ),
			'channel' => array( DestinationKind::CHANNEL ),
		);
	}

	public function test_no_message_thread_id_is_valid_on_every_kind(): void {
		foreach ( DestinationKind::cases() as $kind ) {
			$destination = new Destination( 1, 1, $kind, 'chat-id', null, 'Label', true, 'now' );
			$this->assertNull( $destination->message_thread_id() );
		}

		$this->addToAssertionCount( 1 );
	}
}
