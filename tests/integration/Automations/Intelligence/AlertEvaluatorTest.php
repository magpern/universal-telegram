<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Intelligence;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Intelligence\AlertEvaluator;
use UniversalTelegram\Automations\Intelligence\AlertRepository;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

final class AlertEvaluatorTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION_NAME );
	}

	private function insert_event( string $event_type, string $occurred_at, array $projected_fields = array() ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;

		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"INSERT INTO {$table} (event_id, event_type, schema_version, occurred_at, source, projected_fields_json, created_at) VALUES (%s, %s, %d, %s, %s, %s, %s)",
				wp_generate_uuid4(),
				$event_type,
				1,
				$occurred_at,
				'visitor',
				wp_json_encode( $projected_fields ),
				current_time( 'mysql', true )
			)
		);
	}

	private function settings_with( array $overrides ): IntelligenceSettings {
		update_option( Settings::OPTION_NAME, ( new Settings() )->sanitize( $overrides ) );

		return new IntelligenceSettings( new Settings() );
	}

	private function eligible_target(): DigestEligibility {
		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'destination_is_eligible' )->willReturn( true );

		return $eligibility;
	}

	private function working_message_dispatcher(): MessageDispatcher {
		$schema_health = new SchemaHealth();

		return new MessageDispatcher( new OutboundMessageRepository( $schema_health, new CredentialVault() ), new Dispatcher( $schema_health ) );
	}

	private function evaluator( IntelligenceSettings $settings, bool $woocommerce_active_stub = true ): AlertEvaluator {
		return new AlertEvaluator(
			$settings,
			$this->eligible_target(),
			new OperationalSummaryRepository( new SchemaHealth() ),
			new AlertRepository( new SchemaHealth() ),
			$this->working_message_dispatcher(),
			new WooCommerceSupport()
		);
	}

	public function test_default_disabled_alerts_never_fire(): void {
		$settings = $this->settings_with(
			array(
				'alert_bot_id'                           => 1,
				'alert_destination_id'                   => 1,
				'alert_checkout_failure_count_threshold' => 1,
			)
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_event( 'woocommerce.checkout_validation_failed', current_time( 'mysql', true ) );
		}

		$this->evaluator( $settings )->evaluate();

		$state = new AlertRepository( new SchemaHealth() );
		$this->assertNull( $state->last_fired_at( 'checkout_failure_count' ) );
	}

	public function test_checkout_failure_count_fires_once_threshold_reached_when_woocommerce_active(): void {
		if ( ! ( new WooCommerceSupport() )->is_active() ) {
			$this->markTestSkipped( 'Requires the WooCommerce-present CI leg.' );
		}

		$settings = $this->settings_with(
			array(
				'alert_bot_id'                           => 1,
				'alert_destination_id'                   => 1,
				'alert_checkout_failure_count_enabled'   => true,
				'alert_checkout_failure_count_threshold' => 3,
			)
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$this->insert_event( 'woocommerce.checkout_validation_failed', current_time( 'mysql', true ) );
		}

		$this->evaluator( $settings )->evaluate();

		$state = new AlertRepository( new SchemaHealth() );
		$this->assertNotNull( $state->last_fired_at( 'checkout_failure_count' ) );
	}

	public function test_checkout_failure_count_is_structurally_inert_when_woocommerce_absent(): void {
		if ( ( new WooCommerceSupport() )->is_active() ) {
			$this->markTestSkipped( 'Requires the WP-only CI leg.' );
		}

		$settings = $this->settings_with(
			array(
				'alert_bot_id'                           => 1,
				'alert_destination_id'                   => 1,
				'alert_checkout_failure_count_enabled'   => true,
				'alert_checkout_failure_count_threshold' => 1,
			)
		);

		$this->evaluator( $settings )->evaluate();

		$state = new AlertRepository( new SchemaHealth() );
		$this->assertNull( $state->last_fired_at( 'checkout_failure_count' ) );
	}

	public function test_js_error_spike_fires_on_a_single_category_reaching_threshold(): void {
		$settings = $this->settings_with(
			array(
				'alert_bot_id'                   => 1,
				'alert_destination_id'           => 1,
				'alert_js_error_spike_enabled'   => true,
				'alert_js_error_spike_threshold' => 5,
			)
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_event( 'visitor.javascript_error', current_time( 'mysql', true ), array( 'payload' => array( 'error_category' => 'runtime' ) ) );
		}
		$this->insert_event( 'visitor.javascript_error', current_time( 'mysql', true ), array( 'payload' => array( 'error_category' => 'promise_rejection' ) ) );

		$this->evaluator( $settings )->evaluate();

		$state = new AlertRepository( new SchemaHealth() );
		$this->assertNotNull( $state->last_fired_at( 'js_error_spike' ) );
	}

	public function test_one_hour_cooldown_prevents_a_re_fire_even_if_condition_persists(): void {
		$settings = $this->settings_with(
			array(
				'alert_bot_id'                   => 1,
				'alert_destination_id'           => 1,
				'alert_js_error_spike_enabled'   => true,
				'alert_js_error_spike_threshold' => 5,
			)
		);

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_event( 'visitor.javascript_error', current_time( 'mysql', true ), array( 'payload' => array( 'error_category' => 'runtime' ) ) );
		}

		$evaluator = $this->evaluator( $settings );
		$evaluator->evaluate();

		$state          = new AlertRepository( new SchemaHealth() );
		$first_fired_at = $state->last_fired_at( 'js_error_spike' );
		$this->assertNotNull( $first_fired_at );

		// Condition still holds; a second evaluation within the hour must
		// not update last_fired_at.
		$this->insert_event( 'visitor.javascript_error', current_time( 'mysql', true ), array( 'payload' => array( 'error_category' => 'runtime' ) ) );
		$evaluator->evaluate();

		$this->assertSame( $first_fired_at, $state->last_fired_at( 'js_error_spike' ) );
	}
}
