<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Diagnostics;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Digest\VisitorDigestCounterRepository;
use UniversalTelegram\Automations\Digest\VisitorDigestStateRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

final class DiagnosticsReportVisitorDigestTest extends WP_UnitTestCase {

	/**
	 * @return array{report: DiagnosticsReport, state: VisitorDigestStateRepository, counters: VisitorDigestCounterRepository, bots: BotProfileRepository, destinations: DestinationRepository}
	 */
	private function build(): array {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );
		$alert         = new QueueHealthAlert( $messages, $breaker, $bots );
		$registry      = new Registry();
		$conversations = new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() );
		$eligibility   = new DigestEligibility( new Settings(), $bots, $destinations, $conversations );
		$state         = new VisitorDigestStateRepository( $schema_health );
		$counters      = new VisitorDigestCounterRepository( $schema_health );

		$report = new DiagnosticsReport(
			new QueueHealth(),
			new AuditLogRepository( $schema_health ),
			new WooCommerceSupport(),
			$schema_health,
			$bots,
			$destinations,
			$alert,
			new EventHistoryRepository( $schema_health, $registry, new Redactor() ),
			new NotificationRuleRepository( $schema_health, $registry ),
			new DispatchLogRepository( $schema_health ),
			new Settings(),
			$eligibility,
			$counters,
			$state
		);

		return compact( 'report', 'state', 'counters', 'bots', 'destinations' );
	}

	public function test_all_nine_digest_keys_are_present_with_expected_types(): void {
		$data = $this->build()['report']->generate();

		foreach (
			array(
				'visitor_digest_enabled',
				'visitor_digest_target_valid',
				'visitor_digest_active',
				'visitor_digest_paused_invalid_target',
			) as $bool_key
		) {
			$this->assertArrayHasKey( $bool_key, $data );
			$this->assertIsBool( $data[ $bool_key ] );
		}

		$this->assertArrayHasKey( 'visitor_digest_pending_event_count', $data );
		$this->assertIsInt( $data['visitor_digest_pending_event_count'] );
		$this->assertArrayHasKey( 'visitor_digest_oldest_pending_age_seconds', $data );
		$this->assertArrayHasKey( 'visitor_digest_last_sent_at', $data );
		$this->assertArrayHasKey( 'visitor_digest_last_status', $data );
		$this->assertIsString( $data['visitor_digest_last_status'] );
		$this->assertArrayHasKey( 'visitor_digest_currently_suppressed_rules_count', $data );
		$this->assertIsInt( $data['visitor_digest_currently_suppressed_rules_count'] );
	}

	public function test_default_disabled_state_reports_never_run_and_no_pending_data(): void {
		delete_option( Settings::OPTION_NAME );

		$data = $this->build()['report']->generate();

		$this->assertFalse( $data['visitor_digest_enabled'] );
		$this->assertFalse( $data['visitor_digest_active'] );
		$this->assertFalse( $data['visitor_digest_paused_invalid_target'] );
		$this->assertSame( 0, $data['visitor_digest_pending_event_count'] );
		$this->assertNull( $data['visitor_digest_oldest_pending_age_seconds'] );
		$this->assertNull( $data['visitor_digest_last_sent_at'] );
		$this->assertSame( 'never_run', $data['visitor_digest_last_status'] );
		$this->assertSame( 0, $data['visitor_digest_currently_suppressed_rules_count'] );
	}

	/**
	 * The specific misconfiguration case the corrected suppression design
	 * exists to make visible: enabled but the target is invalid.
	 */
	public function test_enabled_with_an_invalid_target_reports_paused_not_active(): void {
		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => 999999,
					'visitor_digest_destination_id' => 999999,
				)
			)
		);

		$data = $this->build()['report']->generate();

		$this->assertTrue( $data['visitor_digest_enabled'] );
		$this->assertFalse( $data['visitor_digest_target_valid'] );
		$this->assertFalse( $data['visitor_digest_active'] );
		$this->assertTrue( $data['visitor_digest_paused_invalid_target'] );
	}

	public function test_pending_event_count_and_oldest_age_reflect_an_open_window(): void {
		$built = $this->build();

		$window = $built['state']->open_window_if_needed( gmdate( 'Y-m-d H:i:s', time() - 120 ) );
		$built['counters']->increment( $window, 'search' );
		$built['counters']->increment( $window, 'search' );

		$data = $built['report']->generate();

		$this->assertSame( 2, $data['visitor_digest_pending_event_count'] );
		$this->assertGreaterThanOrEqual( 120, $data['visitor_digest_oldest_pending_age_seconds'] );
	}

	/**
	 * The suppressed-rules count is live and only non-zero while
	 * visitor_digest_active is actually true — never a static
	 * "these rules are affected" total.
	 */
	public function test_suppressed_rules_count_is_zero_unless_the_digest_is_active(): void {
		$built = $this->build();

		$registry = new Registry();
		$registry->register( 'visitor.search_performed', 1, array(), array(), array() );
		$rules = new NotificationRuleRepository( new SchemaHealth(), $registry );
		$rules->save( null, 'Rule', 'visitor.search_performed', 1, array(), 1, 1, 'x', true, 100, 0 );

		$data_inactive = $built['report']->generate();
		$this->assertSame( 0, $data_inactive['visitor_digest_currently_suppressed_rules_count'] );

		$bot = $built['bots']->create( 'Bot', 'token' );
		$built['bots']->set_status( $bot->id(), BotStatus::ACTIVE );
		$destination = $built['destinations']->create( $bot->id(), DestinationKind::CHANNEL, '@chan', null, 'Channel' );

		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'        => true,
					'visitor_digest_bot_id'         => $bot->id(),
					'visitor_digest_destination_id' => $destination->id(),
				)
			)
		);

		$data_active = $this->build()['report']->generate();
		$this->assertSame( 1, $data_active['visitor_digest_currently_suppressed_rules_count'] );
	}
}
