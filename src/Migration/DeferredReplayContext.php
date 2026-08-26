<?php
/**
 * Narrow, unforgeable replay authority.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use LogicException;

/**
 * A capability object, never a global "ignore quiescence" flag
 * (docs/adr/0040 §3, Alternative 8). Grants passage through exactly one
 * gate (`BotCommandDispatcher::handle()`), for exactly one caller (the
 * internal replayer driven by `wp universal-telegram quiescence
 * replay-deferred-updates`), for exactly one epoch (Table 1's `token` at
 * the moment `state === 'replaying'`).
 *
 * The private constructor alone cannot stop `issue()` itself from being
 * called by arbitrary code, since PHP has no cross-class "friend" access;
 * `issue()` therefore verifies its own immediate caller at runtime and
 * refuses construction for anyone but `QuiescenceGate::issue_replay_context()`,
 * making this genuinely unforgeable rather than merely encapsulated by
 * convention.
 */
final class DeferredReplayContext {

	/**
	 * Constructor. Never called directly outside this class — use issue().
	 *
	 * @param string $token The epoch token this context is bound to.
	 */
	private function __construct( private readonly string $token ) {}

	/**
	 * The sole construction path. Restricted at runtime to
	 * `QuiescenceGate::issue_replay_context()`.
	 *
	 * @param string $token The current epoch's token, from Table 1.
	 *
	 * @return self
	 *
	 * @throws LogicException If called from anywhere other than
	 *                          `QuiescenceGate::issue_replay_context()`.
	 */
	public static function issue( string $token ): self {
		$caller = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 2 )[1] ?? null;

		if ( null === $caller
			|| ( $caller['class'] ?? null ) !== QuiescenceGate::class
			|| $caller['function'] !== 'issue_replay_context'
		) {
			throw new LogicException( 'DeferredReplayContext may only be issued by QuiescenceGate::issue_replay_context().' );
		}

		return new self( $token );
	}

	/**
	 * The epoch token this context is bound to.
	 *
	 * @return string
	 */
	public function token(): string {
		return $this->token;
	}
}
