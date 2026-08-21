<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\ChatWidget;

use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class ChatWidgetAvailabilityTest extends WP_UnitTestCase {

	private function availability(): ChatWidgetAvailability {
		$schema_health = new SchemaHealth();
		$bots          = new BotProfileRepository( $schema_health, new CredentialVault() );
		$destinations  = new DestinationRepository( $schema_health );

		return new ChatWidgetAvailability( new Settings(), new ChatProfileResolver( $bots, $destinations ) );
	}

	private function bots(): BotProfileRepository {
		return new BotProfileRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function destinations(): DestinationRepository {
		return new DestinationRepository( new SchemaHealth() );
	}

	public function test_unavailable_when_the_setting_is_disabled(): void {
		update_option( Settings::OPTION_NAME, array( 'chat_widget_enabled' => false ) );

		$bot = $this->bots()->create( 'Support Bot', 'token' );
		$this->destinations()->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$this->assertFalse( $this->availability()->is_available() );
	}

	public function test_unavailable_when_enabled_but_no_bot_is_configured(): void {
		update_option( Settings::OPTION_NAME, array( 'chat_widget_enabled' => true ) );

		$this->assertFalse( $this->availability()->is_available() );
	}

	public function test_unavailable_when_enabled_but_no_eligible_destination_exists(): void {
		update_option( Settings::OPTION_NAME, array( 'chat_widget_enabled' => true ) );

		$this->bots()->create( 'Support Bot', 'token' );

		$this->assertFalse( $this->availability()->is_available() );
	}

	public function test_available_when_enabled_with_an_eligible_bot_and_destination(): void {
		update_option( Settings::OPTION_NAME, array( 'chat_widget_enabled' => true ) );

		$bot = $this->bots()->create( 'Support Bot', 'token' );
		$this->destinations()->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', null, 'Support group' );

		$this->assertTrue( $this->availability()->is_available() );
	}

	public function test_unavailable_when_the_only_destination_is_a_topic_not_the_support_group(): void {
		update_option( Settings::OPTION_NAME, array( 'chat_widget_enabled' => true ) );

		$bot = $this->bots()->create( 'Support Bot', 'token' );
		$this->destinations()->create( $bot->id(), DestinationKind::SUPERGROUP, '-100123', 42, 'A specific topic' );

		$this->assertFalse( $this->availability()->is_available() );
	}
}
