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
use UniversalTelegram\Automations\Intelligence\AlertRepository;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiRepository;
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

final class DiagnosticsReportIntelligenceTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_operational_summary_runs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		delete_option( Settings::OPTION_NAME );
	}

	private function build(): DiagnosticsReport {
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
			$eligibility,
			new VisitorDigestCounterRepository( $schema_health ),
			new VisitorDigestStateRepository( $schema_health ),
			1800,
			24,
			new IntelligenceSettings( new Settings() ),
			new OperationalSummaryRepository( $schema_health ),
			new AlertRepository( $schema_health ),
			new SummaryAiRepository( $schema_health, $vault )
		);
	}

	public function test_all_new_intelligence_keys_are_present_with_expected_types(): void {
		$data = $this->build()->generate();

		$this->assertArrayHasKey( 'operational_summary_enabled', $data );
		$this->assertIsBool( $data['operational_summary_enabled'] );
		$this->assertArrayHasKey( 'operational_summary_last_status', $data );
		$this->assertSame( 'never_run', $data['operational_summary_last_status'] );
		$this->assertArrayHasKey( 'operational_summary_last_sent_at', $data );
		$this->assertNull( $data['operational_summary_last_sent_at'] );

		foreach ( IntelligenceSettings::ALERT_TYPES as $alert_type ) {
			$this->assertArrayHasKey( 'alert_' . $alert_type . '_enabled', $data );
			$this->assertIsBool( $data[ 'alert_' . $alert_type . '_enabled' ] );
			$this->assertArrayHasKey( 'alert_' . $alert_type . '_last_fired_at', $data );
			$this->assertNull( $data[ 'alert_' . $alert_type . '_last_fired_at' ] );
		}

		$this->assertArrayHasKey( 'ai_summary_last_status', $data );
		$this->assertSame( 'never_run', $data['ai_summary_last_status'] );
	}

	public function test_body_ciphertext_never_appears_in_any_diagnostics_value(): void {
		$summaries = new OperationalSummaryRepository( new SchemaHealth() );
		$row       = $summaries->create_or_get_for_date( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d 00:00:00' ), gmdate( 'Y-m-d H:i:s' ) );

		$drafts = new SummaryAiRepository( new SchemaHealth(), new CredentialVault() );
		$drafts->request( (int) $row['id'], 1, 'openai', 'gpt', 'v1' );
		$draft = $drafts->find_by_summary_run_id( (int) $row['id'] );
		$claim = $drafts->claim_candidate_row( $draft->draft_uuid(), 90 );
		$drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'Secret internal summary text.' );

		$data   = $this->build()->generate();
		$serialized = wp_json_encode( $data );

		$this->assertStringNotContainsString( 'Secret internal summary text.', (string) $serialized );
		$this->assertSame( 'generated', $data['ai_summary_last_status'] );
	}
}
