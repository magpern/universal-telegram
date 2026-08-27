<?php
/**
 * Cohort-aware deferred-update disposition (SC-M03 final cutover).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\Inbound\SupportChatContractClient;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Configuration\BotProfile;

/**
 * Dispositions exactly one buffered, already-decrypted deferred-update row
 * whose topic currently has an **active** Support Chat binding (docs/adr/0042
 * §3–§5) — the caller (`Cli\QuiescenceCommand`'s cohort-aware replay loop)
 * has already made that determination via `ChannelBindingRepository::find_by_bot_topic()`
 * before ever constructing a call into this class; this class does not
 * re-resolve or re-decide it.
 *
 * Reuses the exact same dispatch primitives `InboundAdapterBridge::try_handle()`
 * already calls for live traffic — the same `SupportChatContractClient`,
 * the same idempotency-key schemes — so a replayed row is handled through
 * the identical, already-proven, already-authenticated channel live traffic
 * uses, just time-shifted and carrying provenance.
 *
 * Deliberately **stricter** than `try_handle()`'s own live-traffic
 * fallthrough: `try_handle()` returns `false` for an unsupported command,
 * correctly falling through to the legacy dispatcher for *live* traffic on
 * a topic that might still be legacy-routed. A buffered, historical row has
 * no legitimate legacy destination to fall through to once its topic's
 * binding is active — so this class has no fallthrough branch at all. An
 * update it cannot safely classify becomes a durable, UT-only incident
 * (`CutoverIncidentReason`), never silently dropped and never misrepresented
 * as a message.
 */
final class CutoverReplayDispatcher {

	/** Every buffered row this class successfully hands off to Support Chat. */
	public const OUTCOME_HANDED_OFF = 'handed_off';

	/** A UT-only incident was recorded; no Support Chat call was ever attempted or it was refused. */
	public const OUTCOME_INCIDENT = 'incident';

	/** A transient Support Chat call failure — retryable by the next ordinary replay pass, not an incident. */
	public const OUTCOME_RETRY_TRANSIENT = 'retry_transient';

	private const LIFECYCLE_COMMANDS = array( 'claim', 'release', 'resolve', 'reopen' );

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityRepository $operator_identities Resolves the sender's mapped WP operator.
	 * @param SupportChatContractClient  $sc_client           The same Contract client `InboundAdapterBridge` uses for live traffic.
	 * @param DeferredUpdateRepository   $deferred            Stamps `handed_off_at` / records incidents on this row.
	 * @param AuditLogger                $audit               Non-content audit trail.
	 */
	public function __construct(
		private readonly OperatorIdentityRepository $operator_identities,
		private readonly SupportChatContractClient $sc_client,
		private readonly DeferredUpdateRepository $deferred,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Dispositions one row. Never throws for an ordinary classification
	 * outcome — a caught, unexpected exception from a collaborator is
	 * itself mapped to `OUTCOME_RETRY_TRANSIENT`, never allowed to abort
	 * the caller's own loop over the rest of the cohort's rows.
	 *
	 * @param BotProfile           $bot          The receiving bot.
	 * @param DeferredUpdateRecord $record       The buffered row being dispositioned.
	 * @param ChannelBinding       $binding      The caller's already-resolved, confirmed-active binding for this row's topic.
	 * @param array<string, mixed> $decoded      The decrypted update payload.
	 *
	 * @return string One of the OUTCOME_* constants.
	 */
	public function dispatch( BotProfile $bot, DeferredUpdateRecord $record, ChannelBinding $binding, array $decoded ): string {
		try {
			if ( $this->is_topic_lifecycle_service_message( $decoded ) ) {
				return $this->dispatch_lifecycle_event( $record, $binding, $decoded );
			}

			$message = $decoded['message'] ?? null;
			$parsed  = is_array( $message ) ? CommandParser::parse( $message, $bot->telegram_username() ) : null;

			if ( null !== $parsed ) {
				return $this->dispatch_command( $record, $binding, $parsed->command(), $decoded );
			}

			return $this->dispatch_message( $bot, $record, $binding, $decoded );
		} catch ( \Throwable $exception ) {
			return self::OUTCOME_RETRY_TRANSIENT;
		}
	}

	/**
	 * Reply/message → SC message import via `ingest_operator_reply`, the
	 * identical call and idempotency-key scheme `try_handle()` uses live
	 * (`'tg-update-{bot_id}-{update_id}'`).
	 *
	 * @param BotProfile           $bot     The receiving bot.
	 * @param DeferredUpdateRecord $record  The buffered row.
	 * @param ChannelBinding       $binding The confirmed-active binding.
	 * @param array<string, mixed> $decoded The decrypted update payload.
	 */
	private function dispatch_message( BotProfile $bot, DeferredUpdateRecord $record, ChannelBinding $binding, array $decoded ): string {
		$sender_id = $this->extract_sender_id( $decoded );

		if ( null === $sender_id ) {
			return $this->incident( $record, CutoverIncidentReason::PARSE_FAILED );
		}

		$identity = $this->operator_identities->find_by_telegram_user_id( $sender_id );

		if ( null === $identity ) {
			return $this->incident( $record, CutoverIncidentReason::UNMAPPED_SENDER );
		}

		$text = $this->extract_text( $decoded );

		if ( null === $text || '' === $text ) {
			return $this->incident( $record, CutoverIncidentReason::PARSE_FAILED );
		}

		$key    = 'tg-update-' . $bot->id() . '-' . $record->update_id();
		$result = $this->sc_client->ingest_operator_reply(
			$binding->support_conversation_uuid(),
			$key,
			$text,
			$identity->wp_user_id(),
			array( 'telegram_update_id' => $record->update_id() ),
			$record->bot_id(),
			$record->update_id()
		);

		return $this->finish( $record, $result );
	}

	/**
	 * A supported lifecycle command (`claim`/`release`/`resolve`/`reopen`)
	 * → the matching Contract call, the identical idempotency-key scheme
	 * `try_handle()->handle_command()` uses live (`'tg-cmd-{update_id}'`).
	 * `$command` outside this set is a durable incident, never a silent
	 * fallthrough — `try_handle()`'s own live fallthrough to the legacy
	 * dispatcher is not a safe choice for a buffered row (see class
	 * docblock).
	 *
	 * @param DeferredUpdateRecord $record  The buffered row.
	 * @param ChannelBinding       $binding The confirmed-active binding.
	 * @param string               $command The parsed command name.
	 * @param array<string, mixed> $decoded The decrypted update payload.
	 */
	private function dispatch_command( DeferredUpdateRecord $record, ChannelBinding $binding, string $command, array $decoded ): string {
		if ( ! in_array( $command, self::LIFECYCLE_COMMANDS, true ) ) {
			return $this->incident( $record, CutoverIncidentReason::UNSUPPORTED_COMMAND );
		}

		// Sender mapping still applies to lifecycle commands, mirroring
		// try_handle()->handle_command()'s own live-traffic identity gate
		// (InboundAdapterBridge.php:88-106): an unmapped sender is a
		// durable incident here, since a buffered row has no live fallback
		// to silently no-op into.
		$sender_id = $this->extract_sender_id( $decoded );

		if ( null === $sender_id ) {
			return $this->incident( $record, CutoverIncidentReason::PARSE_FAILED );
		}

		$identity = $this->operator_identities->find_by_telegram_user_id( $sender_id );

		if ( null === $identity ) {
			return $this->incident( $record, CutoverIncidentReason::UNMAPPED_SENDER );
		}

		$key    = 'tg-cmd-' . $record->update_id();
		$ref    = $binding->support_conversation_uuid();
		$result = match ( $command ) {
			// The `in_array( $command, self::LIFECYCLE_COMMANDS, true )`
			// guard above already narrows $command to exactly these four
			// literals — no default arm is reachable, and PHPStan proves
			// exhaustiveness over that narrowed type.
			'claim'   => $this->sc_client->claim( $ref, $identity->wp_user_id(), $key, $record->bot_id(), $record->update_id() ),
			'release' => $this->sc_client->release( $ref, $identity->wp_user_id(), $key, $record->bot_id(), $record->update_id() ),
			'resolve' => $this->sc_client->resolve( $ref, $identity->wp_user_id(), $key, $record->bot_id(), $record->update_id() ),
			'reopen'  => $this->sc_client->reopen( $ref, $identity->wp_user_id(), $key, $record->bot_id(), $record->update_id() ),
		};

		return $this->finish( $record, $result );
	}

	/**
	 * A `forum_topic_closed`/`forum_topic_deleted` service message → the
	 * existing, already-idempotent `report_channel_unavailable` Contract
	 * call, reusing the identical fixed `reason_code` vocabulary legacy
	 * already emits (`WebhookController::maybe_mark_topic_unavailable()`).
	 *
	 * @param DeferredUpdateRecord $record  The buffered row.
	 * @param ChannelBinding       $binding The confirmed-active binding.
	 * @param array<string, mixed> $decoded The decrypted update payload.
	 */
	private function dispatch_lifecycle_event( DeferredUpdateRecord $record, ChannelBinding $binding, array $decoded ): string {
		$message = $decoded['message'] ?? array();
		$deleted = is_array( $message ) && isset( $message['forum_topic_deleted'] );
		$reason  = $deleted ? 'telegram_topic_deleted' : 'telegram_topic_closed';

		$result = $this->sc_client->report_channel_unavailable(
			$binding->support_conversation_uuid(),
			$reason,
			$record->bot_id(),
			$record->update_id()
		);

		return $this->finish( $record, $result );
	}

	/**
	 * Exhaustively classifies one Contract call's result after an active
	 * binding has already been selected (docs/adr/0043 §3). Every outcome
	 * maps to a named retryable outcome or a named incident — there is no
	 * generic "everything else is transient" fallback: an unrecognised
	 * `ok:false` reason fails closed to `handoff_rejected`.
	 *
	 * - `{ok: true}` (incl. an already-in-target-state Support Chat
	 *   short-circuit) → stamp `handed_off_at`, `OUTCOME_HANDED_OFF`.
	 * - `409 handoff_provenance_conflict` → durable `handoff_provenance_conflict` incident.
	 * - `404 not_found` (Support Chat could not resolve the conversation
	 *   UUID) → durable `unresolved_case_reference` incident.
	 * - Any explicitly transient condition (`503 request_failed`,
	 *   `401 contract_auth_failed`, and this plugin's own client-side
	 *   not-paired / unavailable / discovery-incompatible / signing-
	 *   unavailable / transport-failed gates) → `OUTCOME_RETRY_TRANSIENT`,
	 *   never an incident.
	 * - Every other deterministic refusal, and every unrecognised
	 *   `ok:false` reason → durable `handoff_rejected` incident (fail closed).
	 *
	 * A caught collaborator exception is mapped to `OUTCOME_RETRY_TRANSIENT`
	 * in `dispatch()` itself, and surfaced in every replay pass's retryable
	 * count by the caller — never a silent unbounded loop.
	 *
	 * @param DeferredUpdateRecord                              $record The buffered row.
	 * @param array{ok: bool, status: int, reason: string|null} $result The Contract call's result.
	 */
	private function finish( DeferredUpdateRecord $record, array $result ): string {
		if ( true === $result['ok'] ) {
			$this->deferred->mark_handed_off( $record->id() );

			return self::OUTCOME_HANDED_OFF;
		}

		$classification = CutoverReplayFailureClassifier::classify( $result['status'], $result['reason'] );

		if ( CutoverReplayFailureClassifier::RETRYABLE === $classification ) {
			return self::OUTCOME_RETRY_TRANSIENT;
		}

		return $this->incident( $record, $classification );
	}

	/**
	 * Records a UT-only incident and its non-content audit entry.
	 *
	 * @param DeferredUpdateRecord $record The buffered row.
	 * @param string               $reason One of `CutoverIncidentReason`'s constants.
	 */
	private function incident( DeferredUpdateRecord $record, string $reason ): string {
		$this->deferred->record_incident( $record->id(), $reason );

		$this->audit->record(
			'cutover.deferred_update.incident_recorded',
			'system',
			null,
			array(
				'bot_id'    => $record->bot_id(),
				'update_id' => $record->update_id(),
				'reason'    => $reason,
			),
			array(
				'bot_id'    => Classification::INTERNAL,
				'update_id' => Classification::INTERNAL,
				'reason'    => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		return self::OUTCOME_INCIDENT;
	}

	/**
	 * Whether a decoded update is a `forum_topic_closed`/`forum_topic_deleted`
	 * service message — the identical check
	 * `WebhookController::maybe_mark_topic_unavailable()` already performs.
	 *
	 * @param array<string, mixed> $decoded The decrypted update payload.
	 */
	private function is_topic_lifecycle_service_message( array $decoded ): bool {
		$message = $decoded['message'] ?? null;

		return is_array( $message ) && ( isset( $message['forum_topic_closed'] ) || isset( $message['forum_topic_deleted'] ) );
	}

	/**
	 * Reads the inbound sender's numeric Telegram user id — identical
	 * extraction to `InboundAdapterBridge::extract_sender_id()`.
	 *
	 * @param array<string, mixed> $decoded The decrypted update payload.
	 */
	private function extract_sender_id( array $decoded ): ?int {
		$message = $decoded['message'] ?? null;

		if ( ! is_array( $message ) ) {
			return null;
		}

		$from = $message['from'] ?? null;

		if ( ! is_array( $from ) || ! isset( $from['id'] ) || ! is_int( $from['id'] ) ) {
			return null;
		}

		return $from['id'];
	}

	/**
	 * Reads plaintext message text or caption — identical extraction to
	 * `InboundAdapterBridge::extract_text()`.
	 *
	 * @param array<string, mixed> $decoded The decrypted update payload.
	 */
	private function extract_text( array $decoded ): ?string {
		$message = $decoded['message'] ?? null;

		if ( ! is_array( $message ) ) {
			return null;
		}

		if ( isset( $message['text'] ) && is_string( $message['text'] ) ) {
			return $message['text'];
		}

		if ( isset( $message['caption'] ) && is_string( $message['caption'] ) ) {
			return $message['caption'];
		}

		return null;
	}
}
