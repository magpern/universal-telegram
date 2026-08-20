<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Diagnostics;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use UniversalTelegram\Queue\RetryPolicy;
use WP_UnitTestCase;

final class DiagnosticsReportAutomationsTest extends WP_UnitTestCase {

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
			new DispatchLogRepository( $schema_health )
		);
	}

	public function test_automations_fields_are_present_and_aggregation_only(): void {
		$data = $this->report()->generate();

		$this->assertArrayHasKey( 'automations_event_count_24h', $data );
		$this->assertArrayHasKey( 'automations_rule_count', $data );
		$this->assertArrayHasKey( 'automations_enabled_rule_count', $data );
		$this->assertArrayHasKey( 'automations_dispatch_failed_count_24h', $data );
		$this->assertArrayHasKey( 'automations_stuck_claim_count', $data );
		$this->assertArrayHasKey( 'automations_stale_fatal_markers_dropped_count', $data );
		$this->assertArrayHasKey( 'automations_last_evaluation_error_code', $data );

		$this->assertIsInt( $data['automations_event_count_24h'] );
		$this->assertIsInt( $data['automations_rule_count'] );
		$this->assertIsInt( $data['automations_enabled_rule_count'] );
		$this->assertIsInt( $data['automations_dispatch_failed_count_24h'] );
		$this->assertIsInt( $data['automations_stuck_claim_count'] );
		$this->assertIsInt( $data['automations_stale_fatal_markers_dropped_count'] );
		$this->assertIsString( $data['automations_last_evaluation_error_code'] );
	}

	public function test_last_evaluation_error_code_defaults_to_a_fixed_none_value(): void {
		delete_option( \UniversalTelegram\Automations\RuleEvaluator::LAST_EVALUATION_ERROR_CODE_OPTION );

		$data = $this->report()->generate();

		$this->assertSame( 'none', $data['automations_last_evaluation_error_code'] );
	}

	public function test_last_evaluation_error_code_is_never_a_raw_message(): void {
		update_option( \UniversalTelegram\Automations\RuleEvaluator::LAST_EVALUATION_ERROR_CODE_OPTION, 'rule_evaluation_exception' );

		$data = $this->report()->generate();

		$this->assertSame( 'rule_evaluation_exception', $data['automations_last_evaluation_error_code'] );
	}
}
