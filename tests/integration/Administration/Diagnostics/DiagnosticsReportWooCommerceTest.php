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

final class DiagnosticsReportWooCommerceTest extends WP_UnitTestCase {

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
			new \UniversalTelegram\Core\Configuration\Settings(),
			new DigestEligibility( new \UniversalTelegram\Core\Configuration\Settings(), $bots, $destinations, new ConversationRepository( $schema_health, $vault, new VisitorTokenGenerator() ) ),
			new VisitorDigestCounterRepository( $schema_health ),
			new VisitorDigestStateRepository( $schema_health )
		);
	}

	public function test_woocommerce_diagnostics_keys_are_present(): void {
		$data = $this->report()->generate();

		$this->assertArrayHasKey( 'woocommerce_hpos_enabled', $data );
		$this->assertArrayHasKey( 'woocommerce_event_emitters_registered', $data );
		$this->assertIsBool( $data['woocommerce_hpos_enabled'] );
		$this->assertIsBool( $data['woocommerce_event_emitters_registered'] );
	}

	public function test_woocommerce_diagnostics_match_the_current_configuration(): void {
		$expected_active = (bool) getenv( 'UT_TEST_WC_ACTIVE' );
		$data            = $this->report()->generate();

		$this->assertSame( $expected_active, $data['woocommerce_event_emitters_registered'] );

		if ( ! $expected_active ) {
			$this->assertFalse( $data['woocommerce_hpos_enabled'], 'HPOS must report false when WooCommerce is absent.' );
		}
	}
}
