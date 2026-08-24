<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\Diagnostics;

use ActionScheduler;
use UniversalTelegram\Administration\Diagnostics\DiagnosticsReport;
use UniversalTelegram\Audit\AuditLogger;
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
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\QueueHealthAlert;
use WP_UnitTestCase;

final class DiagnosticsReportQueueTest extends WP_UnitTestCase {

	private SchemaHealth $schema_health;

	protected function setUp(): void {
		parent::setUp();
		$this->schema_health = new SchemaHealth();

		// Action Scheduler's own tables are not wrapped by
		// WP_UnitTestCase's per-test transaction rollback, so a pending
		// action enqueued by an earlier test in the same run can otherwise
		// leak into this class' own "nothing pending" assertion.
		$ids = ActionScheduler::store()->query_actions( array( 'group' => WorkerRunner::GROUP ) );

		foreach ( (array) $ids as $id ) {
			ActionScheduler::store()->delete_action( (int) $id );
		}
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
			new Settings(),
			new DigestEligibility( new Settings(), $bots, $destinations, new ConversationRepository( $this->schema_health, $vault, new VisitorTokenGenerator() ) ),
			new VisitorDigestCounterRepository( $this->schema_health ),
			new VisitorDigestStateRepository( $this->schema_health )
		);
	}

	public function test_queue_diagnostics_keys_are_present_and_aggregation_only(): void {
		$data = $this->report()->generate();

		$this->assertArrayHasKey( 'queue_oldest_pending_age_seconds', $data );
		$this->assertArrayHasKey( 'queue_expedited_dispatch_declined_concurrency_24h', $data );
		$this->assertArrayHasKey( 'queue_expedited_dispatch_unavailable_24h', $data );

		$this->assertIsInt( $data['queue_oldest_pending_age_seconds'] );
		$this->assertIsInt( $data['queue_expedited_dispatch_declined_concurrency_24h'] );
		$this->assertIsInt( $data['queue_expedited_dispatch_unavailable_24h'] );
	}

	public function test_expedited_dispatch_counts_reflect_only_the_two_fixed_abnormal_codes(): void {
		$audit = new AuditLogger( $this->schema_health, new Redactor() );
		$audit->record( 'expedited_dispatch_declined_concurrency', 'system', null, array(), array(), Classification::INTERNAL );
		$audit->record( 'expedited_dispatch_declined_concurrency', 'system', null, array(), array(), Classification::INTERNAL );
		$audit->record( 'expedited_dispatch_unavailable', 'system', null, array(), array(), Classification::INTERNAL );

		$data = $this->report()->generate();

		$this->assertSame( 2, $data['queue_expedited_dispatch_declined_concurrency_24h'] );
		$this->assertSame( 1, $data['queue_expedited_dispatch_unavailable_24h'] );
	}

	public function test_oldest_pending_age_is_zero_when_nothing_is_pending(): void {
		$data = $this->report()->generate();

		$this->assertSame( 0, $data['queue_oldest_pending_age_seconds'] );
	}
}
