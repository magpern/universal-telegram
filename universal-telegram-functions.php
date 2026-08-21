<?php
/**
 * Public procedural functions. The plugin's own convention for the one or
 * two functions meant for direct third-party PHP use rather than
 * WordPress hook wiring.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'universal_telegram_emit_event' ) ) {
	/**
	 * Records one normalized event occurrence. The sole public entry point
	 * for event emission — there is no do_action()-based emission surface,
	 * public or internal (docs/adr/0015). Delegates, unconditionally and
	 * without any additional public surface, to the composition root's
	 * singleton Events\EventEmitter::emit(). Never throws.
	 *
	 * @param string               $event_type      A registered event type, e.g. "wordpress.user_registered".
	 * @param array<string, mixed> $data            actor/subject/context/payload sub-arrays; a missing key defaults to [].
	 * @param string               $idempotency_key A source-supplied idempotency key representing this exact logical occurrence.
	 */
	function universal_telegram_emit_event( string $event_type, array $data, string $idempotency_key ): void {
		$emitter = \UniversalTelegram\Core\Plugin::instance()->event_emitter();

		if ( null === $emitter ) {
			return;
		}

		$emitter->emit( $event_type, $data, $idempotency_key );
	}
}
