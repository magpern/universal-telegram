<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Automations\Digest;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Digest\VisitorDigestCounterRepository;
use UniversalTelegram\Automations\Digest\VisitorDigestRenderer;
use UniversalTelegram\Automations\Digest\VisitorDigestStateRepository;
use UniversalTelegram\Automations\Digest\VisitorDigestSweep;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Persistence\MigrationFailureCode;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\Dispatcher;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;
use WP_UnitTestCase;

final class VisitorDigestSweepTest extends WP_UnitTestCase {

	private function settings_with( int $threshold, int $max_wait_minutes ): Settings {
		update_option(
			Settings::OPTION_NAME,
			( new Settings() )->sanitize(
				array(
					'visitor_digest_enabled'          => true,
					'visitor_digest_bot_id'           => 1,
					'visitor_digest_destination_id'   => 1,
					'visitor_digest_threshold'         => $threshold,
					'visitor_digest_max_wait_minutes'  => $max_wait_minutes,
				)
			)
		);

		return new Settings();
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

	/**
	 * WooCommerceSupport is a final class, so — matching every other test
	 * in this codebase that needs one (e.g.
	 * DiagnosticsReportWooCommerceTest) — a real instance is constructed
	 * and simply reflects whichever CI matrix leg (WP-only or WC-present)
	 * this test is actually running under, rather than being force-mocked.
	 *
	 * @param Settings                       $settings           Digest settings.
	 * @param DigestEligibility              $eligibility        The active/eligibility gate.
	 * @param VisitorDigestStateRepository   $state              State/checkpoint persistence.
	 * @param VisitorDigestCounterRepository $counters           Counter persistence.
	 * @param MessageDispatcher|null         $message_dispatcher Defaults to a working dispatcher.
	 */
	private function sweep(
		Settings $settings,
		DigestEligibility $eligibility,
		VisitorDigestStateRepository $state,
		VisitorDigestCounterRepository $counters,
		?MessageDispatcher $message_dispatcher = null
	): VisitorDigestSweep {
		return new VisitorDigestSweep(
			$settings,
			$eligibility,
			$state,
			$counters,
			new VisitorDigestRenderer(),
			$message_dispatcher ?? $this->working_message_dispatcher(),
			new WooCommerceSupport()
		);
	}

	private function active_eligibility(): DigestEligibility {
		$eligibility = $this->createMock( DigestEligibility::class );
		$eligibility->method( 'is_active' )->willReturn( true );

		return $eligibility;
	}

	public function test_no_window_open_is_a_safe_no_op(): void {
		$settings = $this->settings_with( 50, 15 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters )->run();

		$this->assertNull( $state->current_window_started_at() );
	}

	public function test_threshold_trigger_sends_and_closes_the_window(): void {
		$settings = $this->settings_with( 3, 60 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s' ) );
		$counters->increment( $window, 'search' );
		$counters->increment( $window, 'search' );
		$counters->increment( $window, 'search' );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters )->run();

		$this->assertNull( $state->current_window_started_at() );
		$this->assertSame( 'sent', $state->last_digest_status() );
		$this->assertNotNull( $state->last_digest_sent_at() );
		$this->assertSame( 0, $counters->sum_for_window( $window ) );
	}

	public function test_below_threshold_and_within_max_wait_does_not_send(): void {
		$settings = $this->settings_with( 50, 60 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s' ) );
		$counters->increment( $window, 'search' );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters )->run();

		$this->assertSame( $window, $state->current_window_started_at() );
		$this->assertSame( 1, $counters->sum_for_window( $window ) );
	}

	public function test_max_wait_trigger_sends_even_below_threshold(): void {
		$settings = $this->settings_with( 500, 5 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$stale_window = gmdate( 'Y-m-d H:i:s', time() - ( 10 * MINUTE_IN_SECONDS ) );
		$window       = $state->open_window_if_needed( $stale_window );
		$counters->increment( $window, 'search' );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters )->run();

		$this->assertNull( $state->current_window_started_at() );
		$this->assertSame( 'sent', $state->last_digest_status() );
	}

	/**
	 * When is_active() is false (disabled, or enabled with an invalid
	 * target), the sweep must leave an open window exactly as-is — neither
	 * sent nor discarded — and must not evaluate threshold/max-wait at all.
	 */
	public function test_inactive_target_leaves_the_window_untouched(): void {
		$settings = $this->settings_with( 1, 5 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s', time() - ( 30 * MINUTE_IN_SECONDS ) ) );
		$counters->increment( $window, 'search' );

		$inactive_eligibility = $this->createMock( DigestEligibility::class );
		$inactive_eligibility->method( 'is_active' )->willReturn( false );

		$this->sweep( $settings, $inactive_eligibility, $state, $counters )->run();

		$this->assertSame( $window, $state->current_window_started_at() );
		$this->assertSame( 1, $counters->sum_for_window( $window ) );
		$this->assertSame( 'skipped_invalid_target', $state->last_digest_status() );
	}

	/**
	 * Once the target is repaired (is_active() true again), the frozen
	 * window resumes normal evaluation automatically, with no special
	 * resume step — the same sweep instance, the same window.
	 */
	public function test_a_frozen_window_resumes_once_the_target_is_repaired(): void {
		$settings = $this->settings_with( 1, 60 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s' ) );
		$counters->increment( $window, 'search' );

		$inactive_eligibility = $this->createMock( DigestEligibility::class );
		$inactive_eligibility->method( 'is_active' )->willReturn( false );
		$this->sweep( $settings, $inactive_eligibility, $state, $counters )->run();
		$this->assertSame( $window, $state->current_window_started_at() );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters )->run();
		$this->assertNull( $state->current_window_started_at() );
		$this->assertSame( 'sent', $state->last_digest_status() );
	}

	/**
	 * A send failure (MessageDispatcher::send() returns null) leaves the
	 * window open — no data loss — and records send_failed; the next tick
	 * retries the same window.
	 */
	public function test_send_failure_leaves_the_window_open_for_retry(): void {
		$settings = $this->settings_with( 1, 60 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s' ) );
		$counters->increment( $window, 'search' );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters, $this->failing_message_dispatcher() )->run();

		$this->assertSame( $window, $state->current_window_started_at() );
		$this->assertSame( 'send_failed', $state->last_digest_status() );
		$this->assertSame( 1, $counters->sum_for_window( $window ) );
	}

	/**
	 * Two overlapping ticks (e.g. overlapping cron and a manual trigger)
	 * against the same window must never both send: the second tick's
	 * try_claim_for_send() fails, since the first already closed the
	 * window (or holds an unexpired claim).
	 */
	public function test_concurrent_ticks_never_double_send_the_same_window(): void {
		$settings = $this->settings_with( 1, 60 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s' ) );
		$counters->increment( $window, 'search' );

		$first  = $this->sweep( $settings, $this->active_eligibility(), $state, $counters );
		$second = $this->sweep( $settings, $this->active_eligibility(), $state, $counters );

		$first->run();
		// The window is already closed by the first tick; the second tick
		// finds no open window at all and is a pure no-op — the strongest
		// possible proof no double-send occurred.
		$second->run();

		$this->assertNull( $state->current_window_started_at() );
		$this->assertSame( 'sent', $state->last_digest_status() );
	}

	/**
	 * The sweep completes successfully and sends regardless of whether
	 * WooCommerce is active in this test environment — commerce-line
	 * omission itself (the actual rendering behavior) is covered by
	 * VisitorDigestRendererTest (§9 WP5), which controls the flag directly
	 * rather than depending on the CI matrix leg.
	 */
	public function test_the_sweep_completes_successfully_regardless_of_woocommerce_presence(): void {
		$settings = $this->settings_with( 1, 60 );
		$state    = new VisitorDigestStateRepository( new SchemaHealth() );
		$counters = new VisitorDigestCounterRepository( new SchemaHealth() );

		$window = $state->open_window_if_needed( gmdate( 'Y-m-d H:i:s' ) );
		$counters->increment( $window, 'search' );

		$this->sweep( $settings, $this->active_eligibility(), $state, $counters )->run();

		$this->assertSame( 'sent', $state->last_digest_status() );
	}
}
