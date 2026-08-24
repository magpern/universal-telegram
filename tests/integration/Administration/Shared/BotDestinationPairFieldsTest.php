<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Shared;

use UniversalTelegram\Administration\Shared\BotDestinationPairFields;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use WP_UnitTestCase;

final class BotDestinationPairFieldsTest extends WP_UnitTestCase {

	protected function tearDown(): void {
		BotDestinationPairFields::reset_script_guard_for_tests();
		parent::tearDown();
	}

	public function test_renders_eligible_destinations_for_every_active_bot_before_a_bot_is_saved(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$conversations = new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() );

		$bot = $bots->create( 'BioPentra Support', 'token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );

		$manual = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1003981752144', null, 'Website Support' );
		$topic  = $destinations->create( $bot->id(), DestinationKind::SUPERGROUP, '-1003981752144', 92, 'bp_manager · 937739bd' );

		$conversation = $conversations->create( wp_generate_uuid4(), hash( 'sha256', 'secret' ), $bot->id(), null );
		$conversations->set_destination( $conversation->id(), $topic->id() );

		$other_bot = $bots->create( 'Other Bot', 'token2' );
		$bots->set_status( $other_bot->id(), BotStatus::ACTIVE );
		$other_destination = $destinations->create( $other_bot->id(), DestinationKind::CHANNEL, '@other', null, 'Other Channel' );

		$renderer = new BotDestinationPairFields(
			$bots,
			new DigestEligibility( new Settings(), $bots, $destinations, $conversations )
		);

		ob_start();
		$renderer->render(
			'intelligence_settings',
			'operational_summary_bot_id',
			'operational_summary_destination_id',
			array(
				'operational_summary_bot_id'         => null,
				'operational_summary_destination_id' => null,
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="' . $manual->id() . '"', $output );
		$this->assertStringContainsString( 'data-bot-id="' . $bot->id() . '"', $output );
		$this->assertStringContainsString( 'Website Support', $output );
		$this->assertStringNotContainsString( 'bp_manager · 937739bd', $output );
		$this->assertStringContainsString( 'data-bot-id="' . $other_bot->id() . '"', $output );
		$this->assertStringContainsString( 'Other Channel', $output );
		$this->assertStringContainsString( 'value="' . $other_destination->id() . '"', $output );
		$this->assertStringContainsString( 'data-ut-bot-select', $output );
		$this->assertStringContainsString( 'data-ut-destination-select', $output );
		$this->assertStringContainsString( 'syncDestinationOptions', $output );
	}

	public function test_disabled_destinations_are_not_rendered(): void {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );

		$bot = $bots->create( 'Bot', 'token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );
		$enabled  = $destinations->create( $bot->id(), DestinationKind::CHANNEL, '@enabled', null, 'Enabled Channel' );
		$disabled = $destinations->create( $bot->id(), DestinationKind::CHANNEL, '@disabled', null, 'Disabled Channel' );
		$destinations->set_enabled( $disabled->id(), false );

		$renderer = new BotDestinationPairFields(
			$bots,
			new DigestEligibility( new Settings(), $bots, $destinations, new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() ) )
		);

		ob_start();
		$renderer->render(
			'visitor_settings',
			'visitor_digest_bot_id',
			'visitor_digest_destination_id',
			array(
				'visitor_digest_bot_id'         => $bot->id(),
				'visitor_digest_destination_id' => $enabled->id(),
			)
		);
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Enabled Channel', $output );
		$this->assertStringNotContainsString( 'Disabled Channel', $output );
	}
}
