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
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

final class DiagnosticsReportVisitorTest extends WP_UnitTestCase {

	private function report(): DiagnosticsReport {
		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$messages      = new OutboundMessageRepository( $schema_health, $vault );
		$breaker       = new CircuitBreaker( $schema_health, new RetryPolicy() );
		$alert         = new QueueHealthAlert( $messages, $breaker, $bots );
		$registry      = new Registry();

		return new DiagnosticsReport(
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
			new DigestEligibility( new Settings(), $bots, $destinations, new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() ) ),
			new VisitorDigestCounterRepository( $schema_health ),
			new VisitorDigestStateRepository( $schema_health )
		);
	}

	public function test_visitor_diagnostics_keys_are_present_and_aggregation_only(): void {
		$data = $this->report()->generate();

		$this->assertArrayHasKey( 'visitor_tracking_enabled', $data );
		$this->assertArrayHasKey( 'visitor_tracker_asset_available', $data );
		$this->assertArrayHasKey( 'visitor_events_recorded_24h', $data );
		$this->assertArrayHasKey( 'visitor_events_rejected_24h', $data );
		$this->assertArrayHasKey( 'visitor_events_rate_limited_24h', $data );
		$this->assertArrayHasKey( 'visitor_events_bot_filtered_24h', $data );

		$this->assertIsBool( $data['visitor_tracking_enabled'] );
		$this->assertIsBool( $data['visitor_tracker_asset_available'] );
		$this->assertIsInt( $data['visitor_events_recorded_24h'] );
		$this->assertIsInt( $data['visitor_events_rejected_24h'] );
		$this->assertIsInt( $data['visitor_events_rate_limited_24h'] );
		$this->assertIsInt( $data['visitor_events_bot_filtered_24h'] );
	}

	public function test_visitor_tracking_enabled_reflects_the_current_setting(): void {
		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => true ) ) );
		$this->assertTrue( $this->report()->generate()['visitor_tracking_enabled'] );

		update_option( Settings::OPTION_NAME, array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => false ) ) );
		$this->assertFalse( $this->report()->generate()['visitor_tracking_enabled'] );
	}
}
