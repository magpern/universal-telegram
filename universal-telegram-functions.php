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

if ( ! function_exists( 'universal_telegram_chat_is_enabled' ) ) {
	/**
	 * Whether chat is enabled for this site (admin toggle only).
	 *
	 * Answers the site-level question for third-party UI such as the Contact
	 * page rail. Does not encode visitor eligibility, login state, bot/destination
	 * readiness, or future anonymous-chat settings.
	 *
	 * @return bool
	 */
	function universal_telegram_chat_is_enabled(): bool {
		$settings = get_option( \UniversalTelegram\Core\Configuration\Settings::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			return false;
		}

		return ! empty( $settings['chat_widget_enabled'] );
	}
}

if ( ! function_exists( 'universal_telegram_emit_event' ) ) {
	/**
	 * Records one normalized event occurrence. The sole public entry point
	 * for event emission — there is no do_action()-based emission surface,
	 * public or internal (docs/adr/0015). Delegates, unconditionally and
	 * without any additional public surface, to the composition root's
	 * singleton Events\EventEmitter::emit(). Never throws.
	 *
	 * @param string                            $event_type      A registered event type, e.g. "wordpress.user_registered".
	 * @param array<string, mixed>              $data            actor/subject/context/payload sub-arrays; a missing key defaults to [].
	 * @param string                            $idempotency_key A source-supplied idempotency key representing this exact logical occurrence.
	 * @param \UniversalTelegram\Events\EventSource $source      The emitting subsystem. Defaults to WORDPRESS_CORE, preserving prior call sites' behavior.
	 */
	function universal_telegram_emit_event( string $event_type, array $data, string $idempotency_key, \UniversalTelegram\Events\EventSource $source = \UniversalTelegram\Events\EventSource::WORDPRESS_CORE ): void {
		$emitter = \UniversalTelegram\Core\Plugin::instance()->event_emitter();

		if ( null === $emitter ) {
			return;
		}

		$emitter->emit( $event_type, $data, $idempotency_key, $source );
	}
}
