<?php
/**
 * Public visitor event ingestion REST route.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Telegram\Reliability\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * `POST universal-telegram/v1/visitor-events`, unauthenticated at the
 * WP-REST layer, mirroring WebhookController's pattern (M04 plan §4.4,
 * docs/adr/0019). The Origin/Referer same-origin check is CSRF friction
 * only, not authentication — this endpoint is treated throughout as
 * writable by any non-browser client that forges those headers. Every
 * accepted-or-silently-suppressed request receives the identical 202, so
 * no response ever distinguishes "recorded" from "dropped".
 */
final class IngestController {

	private const ROUTE_NAMESPACE      = 'universal-telegram/v1';
	private const ROUTE                = '/visitor-events';
	private const RATE_LIMIT_SECRET_OPTION = 'universal_telegram_visitor_rate_limit_secret';

	private const SITE_BUCKET_SCOPE   = 'visitor_site';
	private const SITE_BUCKET_ID      = 0;
	private const SITE_BUCKET_CAPACITY = 300.0;
	private const SITE_BUCKET_REFILL   = 5.0;

	private const CLIENT_BUCKET_SCOPE    = 'visitor_ingest';
	private const CLIENT_BUCKET_CAPACITY = 30.0;
	private const CLIENT_BUCKET_REFILL   = 0.5;

	/**
	 * Event type to the settings family toggle that gates it.
	 *
	 * @var array<string, string>
	 */
	private const EVENT_TYPE_FAMILY = array(
		'visitor.session_started'         => 'visitor_family_page_views',
		'visitor.page_viewed'             => 'visitor_family_page_views',
		'visitor.navigation'              => 'visitor_family_navigation',
		'visitor.search_performed'        => 'visitor_family_search',
		'visitor.click'                   => 'visitor_family_clicks',
		'visitor.javascript_error'        => 'visitor_family_errors',
		'visitor.product_viewed'          => 'visitor_family_commerce',
		'visitor.add_to_cart_intent'      => 'visitor_family_commerce',
		'visitor.checkout_started_intent' => 'visitor_family_commerce',
	);

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth           $schema_health Checked before any processing.
	 * @param Registry               $registry      The current request's event registry.
	 * @param Settings               $settings      Reads the current visitor tracking configuration.
	 * @param RateLimiter            $rate_limiter  The two-tier abuse control.
	 * @param IngestRequestValidator $validator     Strict allow-listed body validation.
	 * @param BotFilter              $bot_filter    Crawler/headless User-Agent detection.
	 * @param Sampler                $sampler       Deterministic per-event sampling.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly Registry $registry,
		private readonly Settings $settings,
		private readonly RateLimiter $rate_limiter,
		private readonly IngestRequestValidator $validator,
		private readonly BotFilter $bot_filter,
		private readonly Sampler $sampler
	) {}

	/**
	 * Registers the REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				// Anonymous browsers cannot authenticate as a WP user;
				// authenticity/abuse controls live entirely inside the
				// callback (M04 plan §4.4).
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The REST route callback.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->schema_health->is_available() ) {
			return new WP_REST_Response( null, 503 );
		}

		if ( ! $this->is_same_origin( $request ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$raw_body = $request->get_body();

		if ( strlen( $raw_body ) > IngestRequestValidator::MAX_BODY_BYTES ) {
			return new WP_REST_Response( null, 413 );
		}

		if ( ! $this->rate_limiter->try_consume( self::SITE_BUCKET_SCOPE, self::SITE_BUCKET_ID, self::SITE_BUCKET_CAPACITY, self::SITE_BUCKET_REFILL ) ) {
			return new WP_REST_Response( null, 429 );
		}

		$client_scope_id = $this->client_bucket_scope_id( $request );

		if ( ! $this->rate_limiter->try_consume( self::CLIENT_BUCKET_SCOPE, $client_scope_id, self::CLIENT_BUCKET_CAPACITY, self::CLIENT_BUCKET_REFILL ) ) {
			return new WP_REST_Response( null, 429 );
		}

		$decoded = json_decode( $raw_body, true );

		$validated = $this->validator->validate( $decoded );

		if ( null === $validated ) {
			return new WP_REST_Response( null, 400 );
		}

		if ( ! $this->every_product_id_is_real( $validated['events'] ) ) {
			return new WP_REST_Response( null, 400 );
		}

		$settings_values = $this->settings->get();

		if ( ! $settings_values['visitor_tracking_enabled'] ) {
			return new WP_REST_Response( null, 202 );
		}

		if ( $settings_values['visitor_exclude_administrators'] && current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return new WP_REST_Response( null, 202 );
		}

		if ( $this->bot_filter->is_bot( $this->raw_user_agent( $request ) ) ) {
			return new WP_REST_Response( null, 202 );
		}

		foreach ( $validated['events'] as $event ) {
			$this->maybe_emit( $event, $validated['visit'], $settings_values );
		}

		return new WP_REST_Response( null, 202 );
	}

	/**
	 * Rejects the whole batch (400) if any product_id-bearing event
	 * references a product that does not actually exist — a
	 * forged/nonexistent id is never recorded (M04 plan §4.6). When
	 * WooCommerce is inactive, `wc_get_product()` does not exist and
	 * these types are unregistered anyway, so they are simply skipped
	 * here and later silently suppressed by the registry check.
	 *
	 * @param array<int, array{uuid: string, event_type: string, fields: array<string, mixed>}> $events Validated events.
	 *
	 * @return bool
	 */
	private function every_product_id_is_real( array $events ): bool {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return true;
		}

		foreach ( $events as $event ) {
			if ( ! isset( $event['fields']['product_id'] ) ) {
				continue;
			}

			if ( ! wc_get_product( $event['fields']['product_id'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Applies family gating, catalog registration, and sampling, then
	 * emits with EventSource::VISITOR if every check passes. Never records
	 * a rejection reason in the response — a suppressed event and a
	 * recorded one are indistinguishable to the caller.
	 *
	 * @param array{uuid: string, event_type: string, fields: array<string, mixed>} $event    One validated event.
	 * @param string                                                                $visit    The batch's visit reference.
	 * @param array<string, mixed>                                                  $settings The current settings snapshot.
	 */
	private function maybe_emit( array $event, string $visit, array $settings ): void {
		$event_type = $event['event_type'];

		if ( ! $this->registry->is_registered( $event_type ) ) {
			return;
		}

		$family = self::EVENT_TYPE_FAMILY[ $event_type ] ?? null;

		if ( null === $family || ! $settings[ $family ] ) {
			return;
		}

		if ( ! $this->sampler->admits( $event_type, $event['uuid'], (int) $settings['visitor_sampling_percent'] ) ) {
			return;
		}

		if ( VisitorEventCatalog::CLICK === $event_type ) {
			$allowlist = $settings['visitor_click_target_allowlist'];
			if ( ! in_array( $event['fields']['target_key'], $allowlist, true ) ) {
				return;
			}
		}

		$data = array(
			'subject' => array(),
			'payload' => array(),
		);

		foreach ( $event['fields'] as $field_name => $value ) {
			$section                     = $this->section_for( $event_type, $field_name );
			$data[ $section ][ $field_name ] = $value;
		}

		$idempotency_key = VisitorEventCatalog::SESSION_STARTED === $event_type
			? 'visit:' . $visit
			: 'event:' . $event['uuid'];

		universal_telegram_emit_event( $event_type, $data, $idempotency_key, EventSource::VISITOR );
	}

	/**
	 * Maps a validated field name to the actor/subject/context/payload
	 * section it belongs to, per the catalog (M04 plan §4.2).
	 *
	 * @param string $event_type The full event type.
	 * @param string $field_name The field name.
	 *
	 * @return string
	 */
	private function section_for( string $event_type, string $field_name ): string {
		if ( 'visitor.search_performed' === $event_type || 'visitor.javascript_error' === $event_type ) {
			return 'payload';
		}

		return 'subject';
	}

	/**
	 * Same-origin check via Origin (preferred) or Referer (fallback) —
	 * CSRF friction only, per M04 plan §4.4.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return bool
	 */
	private function is_same_origin( WP_REST_Request $request ): bool {
		$home = wp_parse_url( home_url() );

		if ( ! is_array( $home ) || ! isset( $home['host'] ) ) {
			return false;
		}

		$home_origin = ( $home['scheme'] ?? 'https' ) . '://' . $home['host'];

		$origin = $request->get_header( 'Origin' );

		if ( null !== $origin && '' !== $origin ) {
			return 0 === strcasecmp( $origin, $home_origin );
		}

		$referer = $request->get_header( 'Referer' );

		if ( null === $referer || '' === $referer ) {
			return false;
		}

		$referer_parts = wp_parse_url( $referer );

		if ( ! is_array( $referer_parts ) || ! isset( $referer_parts['host'] ) ) {
			return false;
		}

		$referer_origin = ( $referer_parts['scheme'] ?? 'https' ) . '://' . $referer_parts['host'];

		return 0 === strcasecmp( $referer_origin, $home_origin );
	}

	/**
	 * The raw User-Agent header, read transiently.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return string|null
	 */
	private function raw_user_agent( WP_REST_Request $request ): ?string {
		$value = $request->get_header( 'User-Agent' );

		return null === $value ? null : (string) $value;
	}

	/**
	 * Derives the per-client fairness bucket's scope_id: an HMAC of
	 * IP+UA+day, keyed with a per-install secret, truncated to a 31-bit
	 * integer. Never reversible back to the raw IP/UA (M04 plan §4.4);
	 * this is transient security processing, never visitor tracking data.
	 *
	 * @param WP_REST_Request $request The inbound request.
	 *
	 * @return int
	 */
	private function client_bucket_scope_id( WP_REST_Request $request ): int {
		$ip         = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_agent = $this->raw_user_agent( $request ) ?? '';
		$day        = gmdate( 'Y-m-d' );

		$hmac = hash_hmac( 'sha256', $ip . "\x1f" . $user_agent . "\x1f" . $day, $this->rate_limit_secret() );

		return (int) ( hexdec( substr( $hmac, 0, 8 ) ) & 0x7FFFFFFF );
	}

	/**
	 * The per-install HMAC secret, generated once via random_bytes() and
	 * stored in a non-autoloaded option, never exposed in any UI or export.
	 *
	 * @return string
	 */
	private function rate_limit_secret(): string {
		$stored = get_option( self::RATE_LIMIT_SECRET_OPTION, '' );

		if ( is_string( $stored ) && '' !== $stored ) {
			return $stored;
		}

		$secret = bin2hex( random_bytes( 32 ) );
		add_option( self::RATE_LIMIT_SECRET_OPTION, $secret, '', false );

		return $secret;
	}
}
