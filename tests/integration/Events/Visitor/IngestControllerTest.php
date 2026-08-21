<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Events\Visitor;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Core\Plugin;
use UniversalTelegram\Events\Visitor\BotFilter;
use UniversalTelegram\Events\Visitor\IngestController;
use UniversalTelegram\Events\Visitor\IngestRequestValidator;
use UniversalTelegram\Events\Visitor\Sampler;
use UniversalTelegram\Events\Visitor\VisitorEventCatalog;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Privacy\Redactor;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_REST_Request;
use WP_UnitTestCase;

final class IngestControllerTest extends WP_UnitTestCase {

	private IngestController $controller;

	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::RATE_LIMIT_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$schema_health = new SchemaHealth();

		update_option(
			Settings::OPTION_NAME,
			array_merge(
				( new Settings() )->defaults(),
				array(
					'visitor_tracking_enabled'       => true,
					'visitor_family_page_views'      => true,
					'visitor_family_clicks'          => true,
					'visitor_click_target_allowlist' => array( 'hero-cta' ),
				)
			)
		);

		$this->controller = new IngestController(
			$schema_health,
			Plugin::instance()->event_registry(),
			new Settings(),
			new RateLimiter( $schema_health ),
			new IngestRequestValidator(),
			new BotFilter(),
			new Sampler(),
			new AuditLogger( $schema_health, new Redactor() )
		);
	}

	private function request( string $body, ?string $origin = null, ?string $user_agent = 'Mozilla/5.0 (test browser)' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/universal-telegram/v1/visitor-events' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'Origin', $origin ?? home_url() );

		if ( null !== $user_agent ) {
			$request->set_header( 'User-Agent', $user_agent );
		}

		$request->set_body( $body );

		return $request;
	}

	private function valid_batch(): string {
		return wp_json_encode(
			array(
				'v'      => 1,
				'visit'  => str_repeat( 'a', 32 ),
				'events' => array(
					array(
						'uuid' => '11111111-1111-4111-8111-111111111111',
						'type' => 'pv',
						'data' => array(
							'path'      => '/hello',
							'page_type' => 'singular',
						),
					),
				),
			)
		);
	}

	public function test_a_valid_batch_returns_202(): void {
		$response = $this->controller->handle_request( $this->request( $this->valid_batch() ) );

		$this->assertSame( 202, $response->get_status() );
	}

	public function test_a_malformed_body_returns_400(): void {
		$response = $this->controller->handle_request( $this->request( '{"not":"valid"}' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_a_cross_origin_request_returns_400(): void {
		$response = $this->controller->handle_request( $this->request( $this->valid_batch(), 'https://evil.example' ) );

		$this->assertSame( 400, $response->get_status() );
	}

	public function test_an_oversized_body_returns_413(): void {
		$oversized = str_repeat( 'x', IngestRequestValidator::MAX_BODY_BYTES + 1 );

		$response = $this->controller->handle_request( $this->request( $oversized ) );

		$this->assertSame( 413, $response->get_status() );
	}

	public function test_a_bot_user_agent_is_silently_suppressed_with_202(): void {
		$response = $this->controller->handle_request( $this->request( $this->valid_batch(), null, 'Googlebot/2.1' ) );

		$this->assertSame( 202, $response->get_status() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", VisitorEventCatalog::PAGE_VIEWED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $count );
	}

	public function test_disabled_tracking_returns_202_and_records_nothing(): void {
		update_option(
			Settings::OPTION_NAME,
			array_merge( ( new Settings() )->defaults(), array( 'visitor_tracking_enabled' => false ) )
		);

		$response = $this->controller->handle_request( $this->request( $this->valid_batch() ) );

		$this->assertSame( 202, $response->get_status() );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", VisitorEventCatalog::PAGE_VIEWED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 0, $count );
	}

	public function test_a_resubmitted_batch_with_the_same_uuid_is_deduplicated(): void {
		$this->controller->handle_request( $this->request( $this->valid_batch() ) );
		$this->controller->handle_request( $this->request( $this->valid_batch() ) );

		global $wpdb;
		$table = $wpdb->prefix . Migrator::EVENT_HISTORY_TABLE;
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", VisitorEventCatalog::PAGE_VIEWED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$this->assertSame( 1, $count );
	}

	public function test_the_site_wide_bucket_rejects_requests_regardless_of_forged_headers_or_rotated_identifiers(): void {
		global $wpdb;
		$table = $wpdb->prefix . Migrator::RATE_LIMIT_TABLE;
		$wpdb->insert(
			$table,
			array(
				'scope_type'       => 'visitor_site',
				'scope_id'         => 0,
				'tokens_available' => 0,
				'last_refill_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$batch = wp_json_encode(
				array(
					'v'      => 1,
					'visit'  => bin2hex( random_bytes( 16 ) ),
					'events' => array(
						array(
							'uuid' => sprintf( '%08x-1111-4111-8111-111111111111', $i ),
							'type' => 'pv',
							'data' => array(
								'path'      => '/rotated-' . $i,
								'page_type' => 'other',
							),
						),
					),
				)
			);

			// Origin is set to a genuine match — an attacker can forge this
			// header freely since it is not authentication, only friction
			// (M04 plan §4.4) — while User-Agent and visit/uuid rotate on
			// every attempt, proving the site-wide bucket cannot be evaded
			// by varying any client-supplied value.
			$response = $this->controller->handle_request(
				$this->request( $batch, null, 'RotatedAgent/' . $i )
			);

			$this->assertSame( 429, $response->get_status() );
		}
	}
}
