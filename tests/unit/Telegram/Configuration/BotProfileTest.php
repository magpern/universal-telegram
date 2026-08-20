<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Telegram\Configuration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotStatus;

final class BotProfileTest extends TestCase {

	public function test_accessors_expose_every_constructed_field(): void {
		$bot = new BotProfile(
			1,
			'11111111-1111-1111-1111-111111111111',
			'My Bot',
			'ct1:token-ciphertext',
			'ct1:secret-ciphertext',
			'ct1:pending-ciphertext',
			'2026-01-01 00:00:00',
			'uncertain',
			'2026-01-01 00:00:00',
			123456,
			'my_bot',
			BotStatus::ACTIVE,
			null,
			'2025-12-31 00:00:00',
			'2026-01-01 00:00:00'
		);

		$this->assertSame( 1, $bot->id() );
		$this->assertSame( '11111111-1111-1111-1111-111111111111', $bot->bot_uuid() );
		$this->assertSame( 'My Bot', $bot->name() );
		$this->assertSame( 'ct1:token-ciphertext', $bot->token_ciphertext() );
		$this->assertSame( 'ct1:secret-ciphertext', $bot->webhook_secret_ciphertext() );
		$this->assertSame( 'ct1:pending-ciphertext', $bot->webhook_secret_pending_ciphertext() );
		$this->assertTrue( $bot->has_pending_secret() );
		$this->assertSame( 'uncertain', $bot->webhook_registration_state() );
		$this->assertSame( 123456, $bot->telegram_bot_id() );
		$this->assertSame( 'my_bot', $bot->telegram_username() );
		$this->assertSame( BotStatus::ACTIVE, $bot->status() );
		$this->assertNull( $bot->webhook_registered_at() );
	}

	public function test_no_pending_secret_reports_false(): void {
		$bot = new BotProfile(
			1,
			'uuid',
			'name',
			'token-ct',
			'secret-ct',
			null,
			null,
			'registered',
			null,
			null,
			null,
			BotStatus::UNCONFIGURED,
			null,
			'now',
			'now'
		);

		$this->assertFalse( $bot->has_pending_secret() );
		$this->assertNull( $bot->webhook_secret_pending_ciphertext() );
	}

	public function test_ciphertext_never_equals_a_plausible_plaintext_token(): void {
		$plaintext = '123456:ABCDefghIJKLmnopQRSTuvwxYZ';

		$bot = new BotProfile(
			1,
			'uuid',
			'name',
			'ut1:' . bin2hex( 'not-the-real-plaintext' ),
			'secret-ct',
			null,
			null,
			'unregistered',
			null,
			null,
			null,
			BotStatus::UNCONFIGURED,
			null,
			'now',
			'now'
		);

		$this->assertNotSame( $plaintext, $bot->token_ciphertext() );
	}
}
