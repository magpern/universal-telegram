<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Diagnostics;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Administration\Diagnostics\SelfTest;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Audit\AuditLogRepository;
use UniversalTelegram\Automations\DispatchLogRepository;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

final class DiagnosticsPageTest extends WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		// The test bootstrap loads the plugin as an MU-plugin, bypassing
		// WordPress' real activation flow, so the capability Activator
		// would normally grant is never actually granted here.
		( new CapabilityRegistrar() )->grant_to_administrator();
	}

	private function make_page( SchemaHealth $schema_health ): DiagnosticsPage {
		$vault                = new CredentialVault();
		$audit_logger         = new AuditLogger( $schema_health, new Redactor() );
		$audit_log_repository = new AuditLogRepository( $schema_health );
		$queue_health         = new QueueHealth();
		$woocommerce_support  = new WooCommerceSupport();
		$dispatcher           = new Dispatcher( $schema_health );
		$bots                 = new BotProfileRepository( $schema_health, $vault );
		$destinations         = new DestinationRepository( $schema_health );
		$messages             = new OutboundMessageRepository( $schema_health, $vault );
		$breaker              = new CircuitBreaker( $schema_health, new RetryPolicy() );
		$alert                = new QueueHealthAlert( $messages, $breaker, $bots );
		$registry             = new Registry();
		$event_history        = new EventHistoryRepository( $schema_health, $registry, new Redactor() );
		$notification_rules   = new NotificationRuleRepository( $schema_health, $registry );
		$dispatch_log         = new DispatchLogRepository( $schema_health );

		$report    = new DiagnosticsReport( $queue_health, $audit_log_repository, $woocommerce_support, $schema_health, $bots, $destinations, $alert, $event_history, $notification_rules, $dispatch_log, new \UniversalTelegram\Core\Configuration\Settings() );
		$self_test = new SelfTest( $schema_health, $dispatcher, $vault, $audit_logger );

		return new DiagnosticsPage( $report, $schema_health, $self_test, $alert );
	}

	public function test_a_user_lacking_the_capability_is_denied_by_wordpress_itself(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$this->expectException( \WPDieException::class );
		$this->make_page( new SchemaHealth() )->render_tab_content();
	}

	public function test_an_html_injection_shaped_audit_value_is_escaped_never_raw(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$schema_health = new SchemaHealth();
		$audit_logger  = new AuditLogger( $schema_health, new Redactor() );
		$audit_logger->record( '<script>alert(1)</script>', 'system', null, array(), array(), Classification::PUBLIC );

		ob_start();
		$this->make_page( $schema_health )->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
	}

	public function test_a_degraded_schema_renders_only_the_stable_failure_code(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$schema_health = new SchemaHealth();
		$schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

		ob_start();
		$this->make_page( $schema_health )->render_tab_content();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'step_failed', $output );
		$this->assertStringNotContainsStringIgnoringCase( 'mysql', $output );
		$this->assertStringNotContainsStringIgnoringCase( 'sql syntax', $output );
	}

	public function test_the_alert_banner_is_absent_when_healthy(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		delete_transient( 'universal_telegram_queue_health_alert_active' );

		ob_start();
		$this->make_page( new SchemaHealth() )->render_admin_notice();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	public function test_the_alert_banner_is_present_once_a_dead_letter_is_seeded(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$schema_health = new SchemaHealth();
		$vault         = new CredentialVault();
		$bots          = new BotProfileRepository( $schema_health, $vault );
		$destinations  = new DestinationRepository( $schema_health );
		$messages      = new OutboundMessageRepository( $schema_health, $vault );

		$bot         = $bots->create( 'Bot', 'token' );
		$destination = $destinations->create( $bot->id(), DestinationKind::PRIVATE, '123', null, 'Chat' );
		$message     = $messages->create( $bot->id(), $destination->id(), 'hi', null );
		$messages->mark_dead_letter( $message->id(), 'telegram_terminal_rejection' );

		delete_transient( 'universal_telegram_queue_health_alert_active' );

		ob_start();
		$this->make_page( $schema_health )->render_admin_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'delivery problem needs attention', $output );
		// Only the fixed alert text, never raw internal detail.
		$this->assertStringNotContainsString( 'telegram_terminal_rejection', $output );
	}
}
