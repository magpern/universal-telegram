<?php
/**
 * Host-independent bounded second-layer delivery fallback.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Queue\ExpeditedDispatchTrigger;

/**
 * Runs after a PENDING outcome from the primary ImmediateDeliveryAttempt
 * (M06.2 corrective plan v2 §3.3, ADR-0023 amendment): up to two further
 * bounded attempts, spaced one second apart, within a further five-second
 * ceiling — still inside the same REST callback invocation, before its
 * response object is returned to WordPress. Identical on every supported
 * PHP host: never touches Action Scheduler's shared batch slot, and never
 * uses any SAPI-specific early-response mechanism
 * (`fastcgi_finish_request()` is deliberately not used anywhere in this
 * boundary — a WP_REST_Server callback returns a response object that
 * WordPress itself serializes and emits afterward, so nothing here can
 * reliably flush the JSON body early without risking corrupting or
 * duplicating that output).
 *
 * If both sub-attempts also fail to deliver, the already-durably-enqueued
 * jobs remain exactly as-is; `ExpeditedDispatchTrigger` is called once more
 * as a final best-effort nudge, and Action Scheduler's own queue plus the
 * external cron remain the durable recovery layer — never the sole
 * interactive fallback.
 */
final class PromptDeliveryFallback {

	private const MAX_ATTEMPTS            = 2;
	private const ATTEMPT_SPACING_SECONDS = 1;
	private const TOTAL_BUDGET_SECONDS    = 5.0;

	/**
	 * Constructor.
	 *
	 * @param ImmediateDeliveryAttempt $immediate_attempt   The shared bounded attempt mechanism (§3.2).
	 * @param ExpeditedDispatchTrigger $expedited_dispatch  The final, best-effort, demoted nudge (§3.4).
	 */
	public function __construct(
		private readonly ImmediateDeliveryAttempt $immediate_attempt,
		private readonly ExpeditedDispatchTrigger $expedited_dispatch
	) {}

	/**
	 * Runs the bounded fallback sequence. Never throws.
	 *
	 * @param ConversationMessage $message               The visitor message still not delivered after the primary attempt.
	 * @param Conversation        $conversation           The owning conversation, freshly re-read.
	 * @param bool                $topic_claim_just_won   Whether the original request's own `maybe_create()` call won the topic-creation claim (M06.2 corrective plan v2 §3.1) — carried through unchanged from the primary attempt's own call.
	 *
	 * @return ImmediateDeliveryResult
	 */
	public function run( ConversationMessage $message, Conversation $conversation, bool $topic_claim_just_won ): ImmediateDeliveryResult {
		if ( ! $topic_claim_just_won && ( 'created' !== $conversation->topic_creation_state() || null === $conversation->destination_id() ) ) {
			// This request never held, and still does not hold, any claim
			// it could make forward progress on within a few seconds — the
			// topic belongs to a different, still-unexpired claimant.
			// Retrying locally would be pure waste; go straight to the
			// final best-effort nudge and let durable recovery continue.
			$this->expedited_dispatch->trigger();
			return ImmediateDeliveryResult::PENDING;
		}

		$deadline = microtime( true ) + self::TOTAL_BUDGET_SECONDS;

		for ( $sub_attempt = 1; $sub_attempt <= self::MAX_ATTEMPTS; $sub_attempt++ ) {
			$remaining = $deadline - microtime( true );

			if ( $remaining <= 0 ) {
				break;
			}

			$result = $this->immediate_attempt->attempt( $message, $conversation, $topic_claim_just_won, $remaining );

			if ( ImmediateDeliveryResult::DELIVERED === $result ) {
				return ImmediateDeliveryResult::DELIVERED;
			}

			if ( $sub_attempt < self::MAX_ATTEMPTS && ( $deadline - microtime( true ) ) > self::ATTEMPT_SPACING_SECONDS ) {
				sleep( self::ATTEMPT_SPACING_SECONDS );
			}
		}

		$this->expedited_dispatch->trigger();

		return ImmediateDeliveryResult::PENDING;
	}
}
