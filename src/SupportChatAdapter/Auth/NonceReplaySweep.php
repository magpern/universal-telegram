<?php
/**
 * Contract v1 nonce replay-store housekeeping (ADR-0007 §3).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Auth;

use UniversalTelegram\Queue\WorkerRunner;

/**
 * Purges nonce-replay records older than the ADR-0007 §3 600-second
 * retention window. Mirrors this plugin's existing scheduled-cleanup
 * pattern: registered
 * once, at plugin init, as a fixed, idempotently-scheduled Action Scheduler
 * recurring action.
 */
final class NonceReplaySweep {

	public const JOB_TYPE         = 'support_chat_contract_nonce_sweep';
	public const INTERVAL_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * @param NonceReplayRepository $nonces Nonce replay store.
	 */
	public function __construct( private readonly NonceReplayRepository $nonces ) {}

	/**
	 * Idempotently registers the recurring sweep action.
	 */
	public function register(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::JOB_TYPE, array(), WorkerRunner::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time(), self::INTERVAL_SECONDS, self::JOB_TYPE, array(), WorkerRunner::GROUP );
	}

	/**
	 * The Action Scheduler hook callback.
	 */
	public function run(): void {
		$this->nonces->purge_expired();
	}
}
