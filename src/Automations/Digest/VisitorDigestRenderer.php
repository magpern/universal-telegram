<?php
/**
 * Fixed visitor digest message rendering.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Digest;

/**
 * Renders the fixed-structure digest message
 * (docs/plans/m11a-visitor-activity-digests-plan-v1.md §6) from a window's
 * own VisitorDigestCounterRepository::for_window() rows. Every value placed
 * into the message is either a fixed label or a bounded non-negative
 * integer — never a raw field, URL, path, search term, or any other
 * variable content — so no MarkdownV2 escaping is required: the fixed
 * `*`/`•` characters here are deliberate formatting, not escaped user
 * content, unlike Automations\TemplateRenderer's own token-substitution
 * escaping, which this class has no need to reuse.
 */
final class VisitorDigestRenderer {

	private const PAGE_TYPES = array( 'home', 'singular', 'search', 'archive', 'other' );

	/**
	 * Renders the fixed digest message for one window.
	 *
	 * @param string                                                                  $window_started_at  The window's own open timestamp.
	 * @param string                                                                  $sent_at            The send timestamp.
	 * @param array<int, array{category: string, page_type: string, event_count: int}> $rows               The window's own counter rows.
	 * @param bool                                                                    $woocommerce_active Whether product-view/cart-intent lines should render at all.
	 *
	 * @return string
	 */
	public function render( string $window_started_at, string $sent_at, array $rows, bool $woocommerce_active ): string {
		$totals = $this->totals( $rows );

		$grand_total = $totals['page_views'] + $totals['product_views'] + $totals['search'] + $totals['cart_intent'] + $totals['other'];
		$duration    = $this->duration_minutes( $window_started_at, $sent_at );

		$lines   = array();
		$lines[] = '📊 *Visitor Activity Digest*';
		$lines[] = sprintf( 'Window: %s – %s (%d min)', $window_started_at, $sent_at, $duration );
		$lines[] = '';
		$lines[] = sprintf( 'Page views: %d', $totals['page_views'] );

		if ( $totals['page_views'] > 0 ) {
			$lines[] = sprintf(
				'  • Home: %d   • Product/post: %d   • Search: %d   • Archive: %d   • Other: %d',
				$totals['page_type']['home'],
				$totals['page_type']['singular'],
				$totals['page_type']['search'],
				$totals['page_type']['archive'],
				$totals['page_type']['other']
			);
		}

		if ( $woocommerce_active ) {
			$lines[] = sprintf( 'Product views: %d', $totals['product_views'] );
		}

		$lines[] = sprintf( 'Searches performed: %d', $totals['search'] );

		if ( $woocommerce_active ) {
			$lines[] = sprintf( 'Cart/checkout intent: %d', $totals['cart_intent'] );
		}

		if ( $totals['other'] > 0 ) {
			$lines[] = sprintf( 'Other activity: %d', $totals['other'] );
		}

		$lines[] = '';
		$lines[] = sprintf( 'Total events: %d', $grand_total );

		return implode( "\n", $lines );
	}

	/**
	 * Aggregates the window's own rows into the fixed category/page-type
	 * totals the message needs.
	 *
	 * @param array<int, array{category: string, page_type: string, event_count: int}> $rows The window's own counter rows.
	 *
	 * @return array{page_views: int, product_views: int, search: int, cart_intent: int, other: int, page_type: array<string, int>}
	 */
	private function totals( array $rows ): array {
		$page_type = array_fill_keys( self::PAGE_TYPES, 0 );

		$totals = array(
			'page_views'    => 0,
			'product_views' => 0,
			'search'        => 0,
			'cart_intent'   => 0,
			'other'         => 0,
			'page_type'     => $page_type,
		);

		foreach ( $rows as $row ) {
			$count = $row['event_count'];

			switch ( $row['category'] ) {
				case 'page_views':
					$totals['page_views'] += $count;
					$key                   = in_array( $row['page_type'], self::PAGE_TYPES, true ) ? $row['page_type'] : 'other';
					$totals['page_type'][ $key ] += $count;
					break;
				case 'product_views':
					$totals['product_views'] += $count;
					break;
				case 'search':
					$totals['search'] += $count;
					break;
				case 'cart_intent':
					$totals['cart_intent'] += $count;
					break;
				default:
					$totals['other'] += $count;
					break;
			}
		}

		return $totals;
	}

	/**
	 * The window's own duration, in whole minutes, floored at zero.
	 *
	 * @param string $window_started_at The window's own open timestamp.
	 * @param string $sent_at           The send timestamp.
	 *
	 * @return int
	 */
	private function duration_minutes( string $window_started_at, string $sent_at ): int {
		$started = strtotime( $window_started_at . ' UTC' );
		$sent    = strtotime( $sent_at . ' UTC' );

		if ( false === $started || false === $sent ) {
			return 0;
		}

		return max( 0, (int) round( ( $sent - $started ) / 60 ) );
	}
}
