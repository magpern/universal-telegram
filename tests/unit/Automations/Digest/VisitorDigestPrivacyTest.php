<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations\Digest;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\Digest\VisitorDigestRenderer;

/**
 * Cross-cutting privacy guard for the rendered digest message
 * (docs/plans/m11a-visitor-activity-digests-plan-v1.md §7), following the
 * same pattern as M04's VisitorCatalogPrivacyAuditTest: the rendered
 * message must never contain a URL, path, query string, referrer,
 * visitor/session identifier, name, email, search term, error text/stack,
 * location, raw payload, credential, or internal database ID — proven
 * structurally, not merely by inspection, since VisitorDigestRenderer's
 * own output is built entirely from fixed labels and bounded integers
 * (§6). This test deliberately feeds deceptive category/page_type values —
 * as if an upstream bug smuggled sensitive-looking data into a counter
 * row — and asserts none of it survives into the rendered text, since the
 * renderer's fixed switch/label structure cannot echo an unrecognized
 * category or page_type value verbatim.
 */
final class VisitorDigestPrivacyTest extends TestCase {

	private const DENYLISTED_SUBSTRINGS = array(
		'visit_ref',
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
		'user_id',
	);

	public function test_rendered_message_never_echoes_a_deceptive_category_or_page_type_value(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:15:00',
			array(
				array(
					'category'    => 'session_id=abc123&user_agent=Mozilla&email=someone@example.com',
					'page_type'   => 'https://example.com/wp-content/secret-token?query=1#fragment',
					'event_count' => 7,
				),
				array(
					'category'    => 'page_views',
					'page_type'   => 'visit_ref=deadbeef&search_term=widgets&location=file.js:12&stack=trace',
					'event_count' => 3,
				),
			),
			true
		);

		foreach ( self::DENYLISTED_SUBSTRINGS as $denied ) {
			$this->assertStringNotContainsString(
				$denied,
				strtolower( $text ),
				"Rendered digest message must never contain '{$denied}'."
			);
		}
	}

	/**
	 * A category value that is not one of the five fixed, recognized
	 * strings ('page_views', 'product_views', 'search', 'cart_intent',
	 * 'other') is never echoed anywhere in the message — it silently
	 * contributes to no top-level line at all (the renderer's switch
	 * statement has no default branch that echoes the input), which is
	 * exactly the structural property the deceptive-input test above
	 * depends on.
	 */
	public function test_an_unrecognized_category_value_never_appears_in_the_output(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:15:00',
			array(
				array(
					'category'    => 'totally-unexpected-category-xyz',
					'page_type'   => '',
					'event_count' => 9,
				),
			),
			true
		);

		$this->assertStringNotContainsString( 'totally-unexpected-category-xyz', $text );
	}
}
