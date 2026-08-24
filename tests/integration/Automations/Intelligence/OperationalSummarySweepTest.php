<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Intelligence;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\Intelligence\IntelligenceStateRepository;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRenderer;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\OperationalSummarySweep;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

final class OperationalSummarySweepTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
	}

	private function enabled_settings( int $hour_utc = 0 ): IntelligenceSettings {
		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'operational_summary_enabled'         => true,
					'operational_summary_bot_id'          => 1,
					'operational_summary_destination_id'  => 1,
					'operational_summary_hour_utc'         => $hour_utc,
				)
			)
		);

		return new IntelligenceSettings( new Settings() );
	}

	private function eligible_target(): DigestEligibility {
		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'destination_is_eligible' )->willReturn( true );

		return $eligibility;
	}

	private function ineligible_target(): DigestEligibility {
		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'destination_is_eligible' )->willReturn( false );

		return $eligibility;
	}

	private function working_message_dispatcher(): MessageDispatcher {
		$schema_health = new SchemaHealth();

		return new MessageDispatcher( new OutboundMessageRepository( $schema_health, new CredentialVault() ), new Dispatcher( $schema_health ) );
	}

	private function failing_message_dispatcher(): MessageDispatcher {
		$broken_schema_health = new SchemaHealth();
		$broken_schema_health->mark_unavailable( MigrationFailureCode::STEP_FAILED );

		return new MessageDispatcher( new OutboundMessageRepository( $broken_schema_health, new CredentialVault() ), new Dispatcher( $broken_schema_health ) );
	}

	private function sweep(
		IntelligenceSettings $settings,
		DigestEligibility $eligibility,
		?MessageDispatcher $message_dispatcher = null
	): OperationalSummarySweep {
		return new OperationalSummarySweep(
			$settings,
			$eligibility,
			new OperationalSummaryRepository( new SchemaHealth() ),
			new IntelligenceStateRepository( new SchemaHealth() ),
			new OperationalSummaryRenderer(),
			$message_dispatcher ?? $this->working_message_dispatcher(),
			new WooCommerceSupport()
		);
	}

	public function test_disabled_creates_no_row(): void {
		update_option( Settings::OPTION_NAME, ( new Settings() )->defaults() );
		$settings = new IntelligenceSettings( new Settings() );
		$repo     = new OperationalSummaryRepository( new SchemaHealth() );

		$this->sweep( $settings, $this->eligible_target() )->run();

		$this->assertNull( $repo->find_by_date( gmdate( 'Y-m-d' ) ) );
	}

	public function test_before_configured_hour_creates_no_row(): void {
		$future_hour = ( (int) gmdate( 'G' ) + 1 ) % 24;
		$settings    = $this->enabled_settings( $future_hour );
		$repo        = new OperationalSummaryRepository( new SchemaHealth() );

		$this->sweep( $settings, $this->eligible_target() )->run();

		$this->assertNull( $repo->find_by_date( gmdate( 'Y-m-d' ) ) );
	}

	public function test_invalid_target_creates_no_row(): void {
		$settings = $this->enabled_settings();
		$repo     = new OperationalSummaryRepository( new SchemaHealth() );

		$this->sweep( $settings, $this->ineligible_target() )->run();

		$this->assertNull( $repo->find_by_date( gmdate( 'Y-m-d' ) ) );
	}

	public function test_reaching_configured_hour_with_valid_target_creates_and_sends_once(): void {
		$settings = $this->enabled_settings();
		$repo     = new OperationalSummaryRepository( new SchemaHealth() );

		$this->sweep( $settings, $this->eligible_target() )->run();

		$row = $repo->find_by_date( gmdate( 'Y-m-d' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'sent', $row['send_status'] );
		$this->assertNotNull( $row['sent_at'] );
	}

	public function test_duplicate_tick_does_not_create_a_second_row_or_resend(): void {
		$settings = $this->enabled_settings();
		$repo     = new OperationalSummaryRepository( new SchemaHealth() );

		$sweep = $this->sweep( $settings, $this->eligible_target() );
		$sweep->run();
		$first_row = $repo->find_by_date( gmdate( 'Y-m-d' ) );

		// A second, back-to-back tick for the same UTC day must reuse the
		// exact same row (summary_date's own UNIQUE constraint), never
		// insert a second one, and must not resend since send_status is
		// already 'sent'.
		$sweep->run();
		$second_row = $repo->find_by_date( gmdate( 'Y-m-d' ) );

		$this->assertSame( $first_row['id'], $second_row['id'] );
		$this->assertSame( $first_row['sent_at'], $second_row['sent_at'] );

		global $wpdb;
		$table = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::OPERATIONAL_SUMMARY_RUNS_TABLE;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE summary_date = %s", gmdate( 'Y-m-d' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( 1, $count );
	}

	/**
	 * Simulates a crash after row creation but before send (send_status
	 * still NULL): a retried tick must reuse the same row, not create a
	 * second one, and must then complete the claim-lease send-handoff —
	 * which keeps its own separate at-least-once send semantics.
	 */
	public function test_crash_recovery_reuses_the_same_row_and_completes_the_send(): void {
		$settings = $this->enabled_settings();
		$repo     = new OperationalSummaryRepository( new SchemaHealth() );

		$summary_date      = gmdate( 'Y-m-d' );
		$window_started_at = gmdate( 'Y-m-d 00:00:00' );

		// Simulate the crash: the row exists (created by an earlier, now-
		// crashed tick) but was never sent.
		$pre_existing_row = $repo->create_or_get_for_date( $summary_date, $window_started_at, current_time( 'mysql', true ) );
		$this->assertNotNull( $pre_existing_row );
		$this->assertNull( $pre_existing_row['sent_at'] );

		$this->sweep( $settings, $this->eligible_target() )->run();

		$row = $repo->find_by_date( $summary_date );
		$this->assertSame( $pre_existing_row['id'], $row['id'] );
		$this->assertSame( 'sent', $row['send_status'] );
	}

	public function test_send_failure_leaves_the_row_open_for_the_next_tick(): void {
		$settings = $this->enabled_settings();
		$repo     = new OperationalSummaryRepository( new SchemaHealth() );

		$this->sweep( $settings, $this->eligible_target(), $this->failing_message_dispatcher() )->run();

		$row = $repo->find_by_date( gmdate( 'Y-m-d' ) );
		$this->assertNotNull( $row );
		$this->assertSame( 'send_failed', $row['send_status'] );
		$this->assertNull( $row['sent_at'] );
	}

	/**
	 * Each funnel stage count is an independent COUNT(*) against its own
	 * event type — inserting rows for one stage must never inflate another
	 * stage's own count (no cross-event join, no accidental sharing).
	 */
	public function test_funnel_stage_counts_are_independent(): void {
		global $wpdb;

		$settings = $this->enabled_settings();
		$table    = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::EVENT_HISTORY_TABLE;
		$now      = current_time( 'mysql', true );

		$insert = static function ( string $event_type ) use ( $wpdb, $table, $now ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT INTO {$table} (event_id, event_type, schema_version, occurred_at, source, projected_fields_json, created_at) VALUES (%s, %s, %d, %s, %s, %s, %s)",
					wp_generate_uuid4(),
					$event_type,
					1,
					$now,
					'visitor',
					'{}',
					$now
				)
			);
		};

		$insert( 'visitor.product_viewed' );
		$insert( 'visitor.product_viewed' );
		$insert( 'visitor.add_to_cart_intent' );

		$this->sweep( $settings, $this->eligible_target() )->run();

		$repo = new OperationalSummaryRepository( new SchemaHealth() );
		$row  = $repo->find_by_date( gmdate( 'Y-m-d' ) );

		$this->assertSame( 2, (int) $row['funnel_product_views'] );
		$this->assertSame( 1, (int) $row['funnel_cart_intents'] );
		$this->assertSame( 0, (int) $row['funnel_checkout_starts'] );
	}

	/**
	 * JS-error clustering reads only the bounded payload.error_category
	 * field — never raw text, stack, filename, or URL, which do not exist
	 * as columns or JSON keys this counting method ever inspects.
	 */
	public function test_js_error_clustering_counts_by_category_only(): void {
		global $wpdb;

		$settings = $this->enabled_settings();
		$table    = $wpdb->prefix . \UniversalTelegram\Persistence\Migrator::EVENT_HISTORY_TABLE;
		$now      = current_time( 'mysql', true );

		foreach ( array( 'runtime', 'runtime', 'promise_rejection', 'resource_load' ) as $category ) {
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"INSERT INTO {$table} (event_id, event_type, schema_version, occurred_at, source, projected_fields_json, created_at) VALUES (%s, %s, %d, %s, %s, %s, %s)",
					wp_generate_uuid4(),
					'visitor.javascript_error',
					1,
					$now,
					'visitor',
					wp_json_encode( array( 'payload' => array( 'error_category' => $category ) ) ),
					$now
				)
			);
		}

		$this->sweep( $settings, $this->eligible_target() )->run();

		$repo = new OperationalSummaryRepository( new SchemaHealth() );
		$row  = $repo->find_by_date( gmdate( 'Y-m-d' ) );

		$this->assertSame( 2, (int) $row['js_error_runtime'] );
		$this->assertSame( 1, (int) $row['js_error_promise'] );
		$this->assertSame( 1, (int) $row['js_error_resource'] );
	}
}
