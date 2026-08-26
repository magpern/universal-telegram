<?php
/**
 * Narrow, versioned, in-process legacy binding preparation boundary.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Migration;

use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;

/**
 * Implements Support Chat ADR-0009 §2-§5, pinned and scoped for this
 * repository by ADR-0041. Symmetric in shape to LegacyExportServiceV1 but
 * for the write direction: a plain PHP class, never a REST route, Ajax
 * handler, cron path, or Contract v1 operation, called in-process by
 * Support Chat's own `legacy-bind` WP-CLI command, which this repository
 * does not implement or register.
 *
 * Support Chat's migration-map candidate data is treated as input only,
 * never as sufficient proof: every candidate is re-validated here, against
 * this plugin's own live conversation/topic state and its own binding
 * table, inside the same atomically-locked transaction that performs the
 * write (QuiescenceGate::with_quiescence_lock()). Every binding this
 * service creates carries ChannelBinding::STATUS_PREPARED — never
 * STATUS_ACTIVE — under any condition.
 */
final class LegacyBindingImportServiceV1 {

	/**
	 * Server-side batch ceiling, enforced regardless of what the caller
	 * requests, mirroring LegacyExportServiceV1::MAX_BATCH_SIZE.
	 */
	public const MAX_BATCH_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository   $conversations Owns the live topic/lifecycle re-check.
	 * @param ChannelBindingRepository $bindings      Owns every binding read/write.
	 * @param QuiescenceGate           $quiescence    Owns the atomic, lock-scoped quiescence assertion.
	 * @param SchemaHealth             $schema_health Checked before every candidate.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly ChannelBindingRepository $bindings,
		private readonly QuiescenceGate $quiescence,
		private readonly SchemaHealth $schema_health
	) {}

	/**
	 * Prepares up to `min( count( $candidates ), MAX_BATCH_SIZE )` bindings,
	 * one independent, typed, per-candidate result each — never a thrown
	 * exception that aborts the rest of the batch (ADR-0009 §2). `$dry_run`
	 * exercises the full pipeline, including this plugin's live re-check and
	 * its lock-scoped quiescence assertion, but performs no commit on either
	 * check outcome (ADR-0009 §4/§7).
	 *
	 * @param array<int, array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string}> $candidates Candidate batch, capped server-side.
	 * @param bool                                                                                                                                   $dry_run    When true, never commits a write.
	 *
	 * @throws LegacyBindingImportContextRejectedException If called outside a WP-CLI process.
	 *
	 * @return array<int, array{source_conversation_id:int, outcome:string, binding_uuid:?string}>
	 */
	public function import_batch( array $candidates, bool $dry_run = false ): array {
		$this->assert_wp_cli_context();

		$capped = array_slice( $candidates, 0, self::MAX_BATCH_SIZE );

		$results = array();
		foreach ( $capped as $candidate ) {
			$results[] = $this->import_one( $candidate, $dry_run );
		}

		return $results;
	}

	/**
	 * Prepares (or dry-run previews) a single candidate.
	 *
	 * @param array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string} $candidate The candidate to prepare.
	 * @param bool                                                                                                                       $dry_run   When true, never commits a write.
	 *
	 * @return array{source_conversation_id:int, outcome:string, binding_uuid:?string}
	 */
	private function import_one( array $candidate, bool $dry_run ): array {
		$source_conversation_id = (int) $candidate['source_conversation_id'];

		try {
			if ( ! $this->schema_health->is_available() ) {
				return $this->result( $source_conversation_id, BindingImportOutcome::RETRY_UT_UNAVAILABLE_OR_INDETERMINATE );
			}

			$existing = $this->find_existing( $candidate );
			if ( null !== $existing ) {
				return $this->result( $source_conversation_id, $this->outcome_for_existing( $candidate, $existing ) );
			}

			$conversation = $this->conversations->find( $source_conversation_id );
			if ( null === $conversation || ! $this->live_topic_is_bindable( $conversation ) ) {
				return $this->result( $source_conversation_id, BindingImportOutcome::SKIP_TOPIC_STATE_CHANGED );
			}

			$outcome      = null;
			$binding_uuid = null;

			$lock_result = $this->quiescence->with_quiescence_lock(
				function () use ( $candidate, $conversation, $dry_run, &$outcome, &$binding_uuid ) {
					// Re-verify inside the lock: a concurrent writer may have
					// created a matching binding between the pre-check above
					// and this closure running.
					$existing_under_lock = $this->find_existing( $candidate );
					if ( null !== $existing_under_lock ) {
						$outcome = $this->outcome_for_existing( $candidate, $existing_under_lock );
						return false;
					}

					if ( $dry_run ) {
						$outcome = BindingImportOutcome::CREATED;
						return false;
					}

					$new_binding = $this->bindings->create(
						wp_generate_uuid4(),
						(string) $candidate['support_conversation_uuid'],
						'legacy-bind:' . $candidate['support_conversation_uuid'],
						$conversation->bot_id(),
						(int) $conversation->destination_id(),
						(int) $conversation->telegram_topic_id(),
						ChannelBinding::STATUS_PREPARED
					);

					if ( null === $new_binding ) {
						// A real DB-level collision: someone else won the race.
						$collided = $this->find_existing( $candidate );
						$outcome  = null !== $collided
							? $this->outcome_for_existing( $candidate, $collided )
							: BindingImportOutcome::RETRY_TRANSIENT_ERROR;
						return false;
					}

					$outcome      = BindingImportOutcome::CREATED;
					$binding_uuid = $new_binding->binding_uuid();
					return true;
				}
			);

			if ( QuiescenceGate::LOCK_RESULT_NOT_QUIESCENT === $lock_result || QuiescenceGate::LOCK_RESULT_BACKLOG_NONEMPTY === $lock_result ) {
				return $this->result( $source_conversation_id, BindingImportOutcome::RETRY_NOT_QUIESCENT );
			}

			return $this->result( $source_conversation_id, (string) $outcome, $binding_uuid );
		} catch ( \Throwable $exception ) {
			return $this->result( $source_conversation_id, BindingImportOutcome::RETRY_TRANSIENT_ERROR );
		}
	}

	/**
	 * Any existing binding matching this candidate's identity, by either
	 * unique key.
	 *
	 * @param array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string} $candidate The candidate to look up.
	 */
	private function find_existing( array $candidate ): ?ChannelBinding {
		return $this->bindings->find_by_conversation_uuid( (string) $candidate['support_conversation_uuid'] )
			?? $this->bindings->find_by_bot_topic( (int) $candidate['bot_id'], (int) $candidate['telegram_topic_id'] );
	}

	/**
	 * Support Chat ADR-0009 §4's status-specific existing-binding rule: a
	 * matching-identity binding is idempotent success only when its status
	 * is `prepared`. Never treats an `active`, `unavailable`, or `closed`
	 * match as this boundary's own prior success.
	 *
	 * @param array{source_conversation_id:int, bot_id:int, destination_id:int, telegram_topic_id:int, support_conversation_uuid:string} $candidate The candidate being checked.
	 * @param ChannelBinding                                                                                                             $existing  The matching existing binding.
	 */
	private function outcome_for_existing( array $candidate, ChannelBinding $existing ): string {
		$matches_identity = $existing->support_conversation_uuid() === (string) $candidate['support_conversation_uuid']
			&& $existing->bot_id() === (int) $candidate['bot_id']
			&& $existing->telegram_topic_id() === (int) $candidate['telegram_topic_id'];

		if ( ! $matches_identity ) {
			return BindingImportOutcome::CONFLICT_EXISTING_MISMATCHED;
		}

		return match ( $existing->status() ) {
			ChannelBinding::STATUS_PREPARED => BindingImportOutcome::SKIP_ALREADY_BOUND,
			ChannelBinding::STATUS_ACTIVE => BindingImportOutcome::CONFLICT_EXISTING_ACTIVE,
			default => BindingImportOutcome::CONFLICT_EXISTING_STATUS_UNRESOLVED,
		};
	}

	/**
	 * The live re-check (ADR-0009 §2 item 7): a topic is bindable only if
	 * it is currently, right now, `created` and `active` — never trusting
	 * Support Chat's Phase-A-time snapshot as sufficient proof by itself.
	 *
	 * @param Conversation $conversation The live conversation row to check.
	 */
	private function live_topic_is_bindable( Conversation $conversation ): bool {
		return 'created' === $conversation->topic_creation_state()
			&& 'active' === $conversation->topic_lifecycle_state()
			&& null !== $conversation->destination_id()
			&& null !== $conversation->telegram_topic_id();
	}

	/**
	 * The real security boundary is operating-system authority to execute
	 * WP-CLI against this install (Support Chat ADR-0009 §7); this check
	 * only closes off every externally reachable path — mirrors
	 * LegacyExportServiceV1::assert_wp_cli_context() exactly.
	 *
	 * @throws LegacyBindingImportContextRejectedException Always, when not in a WP-CLI process.
	 */
	private function assert_wp_cli_context(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new LegacyBindingImportContextRejectedException(
				'LegacyBindingImportServiceV1::import_batch() may only be invoked from a WP-CLI process.'
			);
		}
	}

	/**
	 * Assembles one typed per-candidate result.
	 *
	 * @param int         $source_conversation_id The candidate's map-row identity.
	 * @param string      $outcome                One of BindingImportOutcome's constants.
	 * @param string|null $binding_uuid            The created binding's UUID, when $outcome is CREATED.
	 *
	 * @return array{source_conversation_id:int, outcome:string, binding_uuid:?string}
	 */
	private function result( int $source_conversation_id, string $outcome, ?string $binding_uuid = null ): array {
		return array(
			'source_conversation_id' => $source_conversation_id,
			'outcome'                => $outcome,
			'binding_uuid'           => $binding_uuid,
		);
	}
}
