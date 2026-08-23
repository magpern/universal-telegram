<?php
/**
 * AI provider diagnostics panel.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\AI;

use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Draft\AIDraftGenerationHandler;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use UniversalTelegram\Telegram\Reliability\CircuitBreakerState;

/**
 * The Diagnostics tab's read-only AI panel (docs/adr/0028 decision 6): the
 * 'ai_provider' circuit-breaker state and site-wide queued/generating/
 * failed draft counts. Never renders draft body content, a credential, or
 * a model identifier — aggregate counts and a state label only. One of
 * exactly two `Administration\AI\*` classes permitted to reference
 * AiDraftRepository, and the only one restricted to read-only access.
 */
final class AIDiagnosticsPanel {

	/**
	 * Constructor.
	 *
	 * @param AiDraftRepository $drafts          Read-only aggregate counts.
	 * @param CircuitBreaker    $circuit_breaker The 'ai_provider' scope's own breaker.
	 */
	public function __construct(
		private readonly AiDraftRepository $drafts,
		private readonly CircuitBreaker $circuit_breaker
	) {}

	/**
	 * Renders the panel.
	 */
	public function render(): void {
		$state = $this->circuit_breaker->state( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID );

		echo '<h2>' . esc_html__( 'AI Provider', 'universal-telegram' ) . '</h2>';
		echo '<table class="widefat"><tbody>';
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Circuit breaker', 'universal-telegram' ), esc_html( $this->state_label( $state ) ) );
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Queued', 'universal-telegram' ), (int) $this->drafts->count_by_status( 'queued' ) );
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Generating', 'universal-telegram' ), (int) $this->drafts->count_by_status( 'generating' ) );
		printf( '<tr><th>%s</th><td>%d</td></tr>', esc_html__( 'Failed', 'universal-telegram' ), (int) $this->drafts->count_by_status( 'failed' ) );
		echo '</tbody></table>';
	}

	/**
	 * @param CircuitBreakerState $state The breaker's current state.
	 */
	private function state_label( CircuitBreakerState $state ): string {
		return match ( $state ) {
			CircuitBreakerState::CLOSED    => __( 'Closed (normal)', 'universal-telegram' ),
			CircuitBreakerState::OPEN      => __( 'Open (provider calls suspended)', 'universal-telegram' ),
			CircuitBreakerState::HALF_OPEN => __( 'Half-open (probing)', 'universal-telegram' ),
		};
	}
}
