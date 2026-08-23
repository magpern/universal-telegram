<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Unit\Integrations\WooCommerce;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;

/**
 * M08 WP5: the exact 500-record/5-page safe-processing-cap boundary
 * `/orders` and `/sales` both rely on, verified in isolation — no
 * WordPress/WooCommerce bootstrap, no live query, no 500-order fixture.
 */
final class WooCommerceCommandQueryServiceCapTest extends TestCase {

	public function test_exactly_the_cap_is_within_bounds(): void {
		$this->assertTrue( WooCommerceCommandQueryService::is_within_safe_cap( 500 ) );
	}

	public function test_one_past_the_cap_is_refused(): void {
		$this->assertFalse( WooCommerceCommandQueryService::is_within_safe_cap( 501 ) );
	}

	public function test_zero_is_within_bounds(): void {
		$this->assertTrue( WooCommerceCommandQueryService::is_within_safe_cap( 0 ) );
	}

	public function test_pages_needed_never_exceeds_five_within_the_cap(): void {
		$this->assertSame( 0, WooCommerceCommandQueryService::pages_needed( 0 ) );
		$this->assertSame( 1, WooCommerceCommandQueryService::pages_needed( 1 ) );
		$this->assertSame( 1, WooCommerceCommandQueryService::pages_needed( 100 ) );
		$this->assertSame( 2, WooCommerceCommandQueryService::pages_needed( 101 ) );
		$this->assertSame( 5, WooCommerceCommandQueryService::pages_needed( 500 ) );
	}

	public function test_a_refused_total_would_have_needed_six_pages(): void {
		// Confirms the cap is set exactly at the 5-page boundary: a
		// refused (501) total is the first one that would require a 6th
		// page, had it not already been refused.
		$this->assertSame( 6, WooCommerceCommandQueryService::pages_needed( 501 ) );
		$this->assertFalse( WooCommerceCommandQueryService::is_within_safe_cap( 501 ) );
	}
}
