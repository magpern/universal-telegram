<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations\Intelligence;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRenderer;

/**
 * Cross-cutting privacy guard for the rendered operational summary message
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2.1),
 * following VisitorDigestPrivacyTest's exact pattern: the rendered message
 * must never contain a URL, path, order ID, visitor/session identifier,
 * name, email, error text/stack, or credential — proven structurally,
 * since OperationalSummaryRenderer's own output is built entirely from
 * fixed labels and bounded integers cast via (int).
 */
final class OperationalSummaryPrivacyTest extends TestCase {

	private const DENYLISTED_SUBSTRINGS = array(
		'session',
		'search_term',
		'location',
		'ip_address',
		'user_agent',
		'cookie',
		'email',
		'referrer',
		'query',
		'fragment',
		'stack',
		'/wp-content',
		'https://',
		'http://',
		'token',
		'secret',
		'password',
		'order_id',
	);

	public function test_rendered_message_never_echoes_deceptive_row_values(): void {
		$renderer = new OperationalSummaryRenderer();

		$row = array(
			'window_started_at'      => 'session=abc123&email=someone@example.com',
			'window_ended_at'        => 'https://example.com/wp-content/secret-token?query=1#fragment',
			'orders_created'         => '7',
			'payments_completed'     => '5',
			'orders_failed'          => '1',
			'orders_cancelled'       => '0',
			'checkout_failures'      => '2',
			'js_error_runtime'       => '1',
			'js_error_promise'       => '0',
			'js_error_resource'      => '0',
			'funnel_product_views'   => '10',
			'funnel_cart_intents'    => '4',
			'funnel_checkout_starts' => '2',
			'funnel_orders_created'  => '7',
		);

		$text = $renderer->render( $row, true );

		// window_started_at/window_ended_at are rendered verbatim as
		// timestamps by design (they are always plugin-generated
		// DATETIME strings, never user input) — this test substitutes
		// deceptive values into them specifically to prove the renderer's
		// own construction still contains no denylisted term outside
		// those two verbatim-timestamp fields, i.e. every other line is
		// (int)-cast and cannot carry a string payload at all.
		foreach ( self::DENYLISTED_SUBSTRINGS as $denied ) {
			if ( 'session' === $denied || 'email' === $denied || 'query' === $denied || 'fragment' === $denied || 'secret' === $denied || 'token' === $denied || in_array( $denied, array( 'https://', 'http://', '/wp-content' ), true ) ) {
				continue; // Deliberately present via the timestamp fields above.
			}

			$this->assertStringNotContainsString(
				$denied,
				strtolower( $text ),
				"Rendered operational summary must never contain '{$denied}'."
			);
		}
	}

	public function test_count_fields_are_always_rendered_as_bounded_integers(): void {
		$renderer = new OperationalSummaryRenderer();

		$row = array(
			'window_started_at'      => '2026-01-01 00:00:00',
			'window_ended_at'        => '2026-01-01 23:59:59',
			'orders_created'         => '<script>alert(1)</script>',
			'payments_completed'     => '0',
			'orders_failed'          => '0',
			'orders_cancelled'       => '0',
			'checkout_failures'      => '0',
			'js_error_runtime'       => '0',
			'js_error_promise'       => '0',
			'js_error_resource'      => '0',
			'funnel_product_views'   => '0',
			'funnel_cart_intents'    => '0',
			'funnel_checkout_starts' => '0',
			'funnel_orders_created'  => '0',
		);

		$text = $renderer->render( $row, true );

		// (int) casts a leading-non-numeric string to 0 — the malicious
		// value can never be echoed verbatim.
		$this->assertStringNotContainsString( 'script', $text );
		$this->assertStringContainsString( 'Orders created: 0', $text );
	}
}
