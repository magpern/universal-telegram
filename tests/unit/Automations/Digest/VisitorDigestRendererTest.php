<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Automations\Digest;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Automations\Digest\VisitorDigestRenderer;

final class VisitorDigestRendererTest extends TestCase {

	public function test_fixed_structure_and_totals(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:15:00',
			array(
				array( 'category' => 'page_views', 'page_type' => 'home', 'event_count' => 4 ),
				array( 'category' => 'page_views', 'page_type' => 'singular', 'event_count' => 6 ),
				array( 'category' => 'search', 'page_type' => '', 'event_count' => 2 ),
			),
			true
		);

		$this->assertStringContainsString( '📊 *Visitor Activity Digest*', $text );
		$this->assertStringContainsString( 'Window: 2026-01-01 00:00:00 – 2026-01-01 00:15:00 (15 min)', $text );
		$this->assertStringContainsString( 'Page views: 10', $text );
		$this->assertStringContainsString( '• Home: 4', $text );
		$this->assertStringContainsString( '• Product/post: 6', $text );
		$this->assertStringContainsString( 'Searches performed: 2', $text );
		$this->assertStringContainsString( 'Total events: 12', $text );
	}

	public function test_top_level_category_lines_render_as_zero_rather_than_being_hidden(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:05:00',
			array( array( 'category' => 'search', 'page_type' => '', 'event_count' => 1 ) ),
			true
		);

		$this->assertStringContainsString( 'Page views: 0', $text );
		$this->assertStringContainsString( 'Product views: 0', $text );
		$this->assertStringContainsString( 'Cart/checkout intent: 0', $text );
	}

	public function test_page_type_breakdown_line_is_omitted_when_page_views_is_zero(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:05:00',
			array( array( 'category' => 'search', 'page_type' => '', 'event_count' => 1 ) ),
			true
		);

		$this->assertStringNotContainsString( '• Home:', $text );
	}

	public function test_commerce_lines_are_fully_omitted_when_woocommerce_is_inactive(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:05:00',
			array( array( 'category' => 'search', 'page_type' => '', 'event_count' => 1 ) ),
			false
		);

		$this->assertStringNotContainsString( 'Product views:', $text );
		$this->assertStringNotContainsString( 'Cart/checkout intent:', $text );
	}

	public function test_other_activity_line_only_appears_when_non_zero(): void {
		$renderer = new VisitorDigestRenderer();

		$without_other = $renderer->render( '2026-01-01 00:00:00', '2026-01-01 00:05:00', array( array( 'category' => 'search', 'page_type' => '', 'event_count' => 1 ) ), true );
		$this->assertStringNotContainsString( 'Other activity:', $without_other );

		$with_other = $renderer->render( '2026-01-01 00:00:00', '2026-01-01 00:05:00', array( array( 'category' => 'other', 'page_type' => '', 'event_count' => 1 ) ), true );
		$this->assertStringContainsString( 'Other activity: 1', $with_other );
	}

	public function test_an_unrecognized_page_type_folds_into_other(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:05:00',
			array( array( 'category' => 'page_views', 'page_type' => 'not-a-real-type', 'event_count' => 3 ) ),
			true
		);

		$this->assertStringContainsString( '• Other: 3', $text );
	}

	public function test_grand_total_sums_every_category(): void {
		$renderer = new VisitorDigestRenderer();

		$text = $renderer->render(
			'2026-01-01 00:00:00',
			'2026-01-01 00:05:00',
			array(
				array( 'category' => 'page_views', 'page_type' => 'home', 'event_count' => 2 ),
				array( 'category' => 'product_views', 'page_type' => '', 'event_count' => 3 ),
				array( 'category' => 'search', 'page_type' => '', 'event_count' => 1 ),
				array( 'category' => 'cart_intent', 'page_type' => '', 'event_count' => 4 ),
				array( 'category' => 'other', 'page_type' => '', 'event_count' => 5 ),
			),
			true
		);

		$this->assertStringContainsString( 'Total events: 15', $text );
	}
}
