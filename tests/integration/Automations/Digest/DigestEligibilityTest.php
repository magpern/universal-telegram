<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Digest;

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

final class DigestEligibilityTest extends WP_UnitTestCase {

	private function bots(): BotProfileRepository {
		return new BotProfileRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function destinations(): DestinationRepository {
		return new DestinationRepository( new SchemaHealth() );
	}

	private function conversations(): ConversationRepository {
		return new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
	}

	private function eligibility( ?ConversationRepository $conversations = null ): DigestEligibility {
		return new DigestEligibility( new Settings(), $this->bots(), $this->destinations(), $conversations ?? $this->conversations() );
	}

	public function set_up(): void {
		parent::set_up();
		delete_transient( DigestEligibility::TRANSIENT_KEY );
		delete_option( Settings::OPTION_NAME );
	}

	private function active_bot(): int {
		$bots = $this->bots();
		$bot  = $bots->create( 'Digest Bot', 'token' );
		$bots->set_status( $bot->id(), BotStatus::ACTIVE );

		return $bot->id();
	}

	public function test_is_active_is_false_when_digest_disabled(): void {
		$bot_id         = $this->active_bot();
		$destination    = $this->destinations()->create( $bot_id, DestinationKind::CHANNEL, '@chan', null, 'Channel' );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'         => false,
					'visitor_digest_bot_id'          => $bot_id,
					'visitor_digest_destination_id'  => $destination->id(),
				)
			)
		);

		$this->assertFalse( $this->eligibility()->is_active() );
	}

	public function test_is_active_is_true_when_enabled_with_a_valid_target(): void {
		$bot_id      = $this->active_bot();
		$destination = $this->destinations()->create( $bot_id, DestinationKind::CHANNEL, '@chan', null, 'Channel' );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot_id,
					'visitor_digest_destination_id' => $destination->id(),
				)
			)
		);

		$this->assertTrue( $this->eligibility()->is_active() );
	}

	public function test_is_active_is_false_when_bot_or_destination_is_invalid(): void {
		$bot_id      = $this->active_bot();
		$destination = $this->destinations()->create( $bot_id, DestinationKind::CHANNEL, '@chan', null, 'Channel' );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot_id,
					'visitor_digest_destination_id' => 999999,
				)
			)
		);

		$this->assertFalse( $this->eligibility()->is_active() );
		$this->assertTrue( $this->eligibility()->paused_for_invalid_target() );

		$this->destinations()->set_enabled( $destination->id(), false );

		delete_transient( DigestEligibility::TRANSIENT_KEY );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot_id,
					'visitor_digest_destination_id' => $destination->id(),
				)
			)
		);

		$this->assertFalse( $this->eligibility()->is_active() );
	}

	public function test_is_active_is_false_for_a_conversation_linked_destination(): void {
		$bot_id        = $this->active_bot();
		$destination   = $this->destinations()->create( $bot_id, DestinationKind::SUPERGROUP, '-100123', 42, 'Topic' );
		$conversations = $this->conversations();

		$conversation = $conversations->create( wp_generate_uuid4(), hash( 'sha256', 'secret' ), $bot_id, null );
		$conversations->set_destination( $conversation->id(), $destination->id() );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot_id,
					'visitor_digest_destination_id' => $destination->id(),
				)
			)
		);

		$this->assertFalse( $this->eligibility( $conversations )->is_active() );
		$this->assertFalse( $this->eligibility( $conversations )->target_valid() );
	}

	public function test_eligible_destinations_for_bot_excludes_conversation_linked_destinations(): void {
		$bot_id        = $this->active_bot();
		$destinations  = $this->destinations();
		$manual        = $destinations->create( $bot_id, DestinationKind::CHANNEL, '@manual', null, 'Manual' );
		$conversation_destination = $destinations->create( $bot_id, DestinationKind::SUPERGROUP, '-100999', 7, 'Conversation topic' );

		$conversations = $this->conversations();
		$conversation  = $conversations->create( wp_generate_uuid4(), hash( 'sha256', 'secret2' ), $bot_id, null );
		$conversations->set_destination( $conversation->id(), $conversation_destination->id() );

		$eligible    = $this->eligibility( $conversations )->eligible_destinations_for_bot( $bot_id );
		$eligible_ids = array_map( static fn( $destination ) => $destination->id(), $eligible );

		$this->assertContains( $manual->id(), $eligible_ids );
		$this->assertNotContains( $conversation_destination->id(), $eligible_ids );
	}

	public function test_transient_is_invalidated_by_bot_or_destination_changed_action_regardless_of_caller(): void {
		$bot_id      = $this->active_bot();
		$destination = $this->destinations()->create( $bot_id, DestinationKind::CHANNEL, '@chan', null, 'Channel' );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot_id,
					'visitor_digest_destination_id' => $destination->id(),
				)
			)
		);

		$eligibility = $this->eligibility();
		$eligibility->register();

		$this->assertTrue( $eligibility->is_active() );

		// Simulate a mutation made through a caller other than
		// BotManagementPage — e.g. the setup wizard, webhook-registration
		// flow, or a cleanup routine — driving the repository directly.
		// This must invalidate the cache exactly as a Bots-tab save would,
		// because the action fires from inside the repository's own
		// mutation method, not from any one admin screen
		// (docs/plans/m11a-visitor-activity-digests-plan-v1.md §3.1/§9 WP1).
		$this->destinations()->set_enabled( $destination->id(), false );

		$this->assertFalse( get_transient( DigestEligibility::TRANSIENT_KEY ) );
		$this->assertFalse( $eligibility->is_active() );
	}

	public function test_transient_is_invalidated_by_bot_status_mutation_outside_bot_management_page(): void {
		$bots        = $this->bots();
		$bot_id      = $this->active_bot();
		$destination = $this->destinations()->create( $bot_id, DestinationKind::CHANNEL, '@chan', null, 'Channel' );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot_id,
					'visitor_digest_destination_id' => $destination->id(),
				)
			)
		);

		$eligibility = $this->eligibility();
		$eligibility->register();

		$this->assertTrue( $eligibility->is_active() );

		// Simulates a caller other than BotManagementPage — e.g. an
		// operator disabling the bot via the webhook-registration/cleanup
		// path, or a future admin screen — calling set_status() directly.
		// The disable must be picked up immediately, without any explicit
		// invalidate() call from this test, proving the action fires from
		// inside the repository's own mutation method.
		$bots->set_status( $bot_id, BotStatus::DISABLED );

		$this->assertFalse( $eligibility->is_active() );
	}
}
