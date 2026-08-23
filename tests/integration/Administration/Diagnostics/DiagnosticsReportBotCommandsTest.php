<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Diagnostics;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

/**
 * M08 WP7: the Diagnostics "Bot commands" panel — bounded rejection-count
 * aggregates only, never a raw Telegram id.
 */
final class DiagnosticsReportBotCommandsTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;
	private AuditLogger $audit;

	protected function setUp(): void {
		parent::setUp();

		$this->schema_health = new SchemaHealth();
		$this->audit         = new AuditLogger( $this->schema_health, new Redactor() );
	}

	private function report(): DiagnosticsReport {
		$vault        = new CredentialVault();
		$bots         = new BotProfileRepository( $this->schema_health, $vault );
		$destinations = new DestinationRepository( $this->schema_health );
		$messages     = new OutboundMessageRepository( $this->schema_health, $vault );
		$breaker      = new CircuitBreaker( $this->schema_health, new RetryPolicy() );
		$alert        = new QueueHealthAlert( $messages, $breaker, $bots );
		$registry     = new Registry();

		return new DiagnosticsReport(
			new QueueHealth(),
			new AuditLogRepository( $this->schema_health ),
			new WooCommerceSupport(),
			$this->schema_health,
			$bots,
			$destinations,
			$alert,
			new EventHistoryRepository( $this->schema_health, $registry, new Redactor() ),
			new NotificationRuleRepository( $this->schema_health, $registry ),
			new DispatchLogRepository( $this->schema_health ),
			new Settings()
		);
	}

	public function test_bot_commands_keys_are_present(): void {
		$data = $this->report()->generate();

		$this->assertArrayHasKey( 'bot_commands_active', $data );
		$this->assertArrayHasKey( 'bot_commands_rejected_unauthorized_24h', $data );
		$this->assertArrayHasKey( 'bot_commands_rejected_wrong_context_24h', $data );
		$this->assertTrue( $data['bot_commands_active'] );
	}

	public function test_rejection_counts_reflect_recent_audit_entries_as_plain_integers(): void {
		$this->audit->record(
			'bot_command.rejected_unauthorized',
			'system',
			null,
			array( 'bot_id' => 7 ),
			array( 'bot_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
		$this->audit->record(
			'bot_command.rejected_wrong_context',
			'system',
			null,
			array(
				'bot_id'  => 7,
				'command' => 'claim',
			),
			array(
				'bot_id'  => Classification::INTERNAL,
				'command' => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);
		$this->audit->record(
			'bot_command.rejected_wrong_context',
			'system',
			null,
			array(
				'bot_id'  => 7,
				'command' => 'presence',
			),
			array(
				'bot_id'  => Classification::INTERNAL,
				'command' => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$data = $this->report()->generate();

		// Both counts are plain integers by construction
		// (AuditLogRepository::count_by_action_24h()'s own return type) —
		// there is no code path through which a raw id could appear here.
		$this->assertSame( 1, $data['bot_commands_rejected_unauthorized_24h'] );
		$this->assertSame( 2, $data['bot_commands_rejected_wrong_context_24h'] );
		$this->assertIsInt( $data['bot_commands_rejected_unauthorized_24h'] );
		$this->assertIsInt( $data['bot_commands_rejected_wrong_context_24h'] );
	}
}
