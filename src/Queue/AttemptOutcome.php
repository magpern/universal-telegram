<?php
/**
 * Shared delivery-attempt outcome.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

/**
 * The result of one non-throwing `try_once()` delivery attempt, shared
 * unmodified between the durable queue worker (`SendMessageHandler`,
 * `TopicCreationHandler`) and the in-process immediate/fallback attempt
 * layers (M06.2 corrective plan v2 §3.1–§3.3, ADR-0023 amendment). Each
 * caller translates this into its own side effects; `try_once()` itself
 * never schedules a durable retry or throws for an ordinary Telegram-API
 * outcome.
 */
enum AttemptOutcome {

	/**
	 * The Telegram call succeeded and the local terminal-state write committed.
	 */
	case DELIVERED;

	/**
	 * Another claimant already holds an unexpired lease on this row; this
	 * caller made no Telegram call at all.
	 */
	case ALREADY_CLAIMED;

	/**
	 * A local precondition (circuit breaker open with a scheduled probe, or
	 * a local rate limiter) deferred the attempt without calling Telegram;
	 * a durable reattempt is already scheduled.
	 */
	case DEFERRED;

	/**
	 * The Telegram call failed with a retryable classification and the
	 * attempt budget is not yet exhausted; a durable reattempt is expected.
	 */
	case PENDING_RETRY;

	/**
	 * The row reached a terminal state (dead-lettered, or — for topic
	 * creation — failed) and will never be reattempted.
	 */
	case TERMINAL;
}
