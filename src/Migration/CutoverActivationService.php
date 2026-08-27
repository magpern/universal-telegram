<?php
/**
 * SC-M03 final-cutover whole-cohort activation saga.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use UniversalTelegram\SupportChatAdapter\ChannelBinding;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;

/**
 * Implements docs/adr/0042 §2 exactly: a read-only whole-cohort preflight,
 * then an all-or-nothing commit phase — one lock-scoped, CAS-guarded
 * transaction per candidate, reusing `QuiescenceGate::with_quiescence_lock()`
 * unchanged — with automatic, in-run compensation the instant any
 * candidate's commit-time re-check fails. Never activates a partial cohort;
 * never restores a compensated candidate's `cas_version` to its pre-run
 * value (strictly monotonic, `+2` after a compensated attempt).
 */
final class CutoverActivationService {

	/**
	 * Constructor.
	 *
	 * @param ChannelBindingRepository $bindings   Binding reads/writes.
	 * @param QuiescenceGate           $quiescence Owns the atomic, lock-scoped per-candidate check-and-write.
	 * @param CutoverRunRepository     $runs       Run state, transitions, and activation audit.
	 */
	public function __construct(
		private readonly ChannelBindingRepository $bindings,
		private readonly QuiescenceGate $quiescence,
		private readonly CutoverRunRepository $runs
	) {}

	/**
	 * Whole-cohort, read-only preflight (docs/adr/0042 §2): every candidate
	 * must currently resolve to a real, `prepared` binding. Performs no
	 * write of any kind — safe to call repeatedly, including from `status`/
	 * `validate`-style read-only commands.
	 *
	 * @param array<int, string> $conversation_uuids Cohort candidates, identified by Support Chat `support_conversation_uuid`.
	 *
	 * @return array{eligible: bool, results: array<int, array{conversation_uuid: string, eligible: bool, reason: ?string, binding_uuid: ?string}>}
	 */
	public function preflight( array $conversation_uuids ): array {
		$results  = array();
		$eligible = true;

		foreach ( $conversation_uuids as $conversation_uuid ) {
			$binding = $this->bindings->find_by_conversation_uuid( $conversation_uuid );

			if ( null === $binding ) {
				$eligible  = false;
				$results[] = array(
					'conversation_uuid' => $conversation_uuid,
					'eligible'          => false,
					'reason'            => 'no_prepared_binding',
					'binding_uuid'      => null,
				);
				continue;
			}

			if ( ChannelBinding::STATUS_PREPARED !== $binding->status() ) {
				$eligible  = false;
				$results[] = array(
					'conversation_uuid' => $conversation_uuid,
					'eligible'          => false,
					'reason'            => 'not_prepared_status_' . $binding->status(),
					'binding_uuid'      => $binding->binding_uuid(),
				);
				continue;
			}

			$results[] = array(
				'conversation_uuid' => $conversation_uuid,
				'eligible'          => true,
				'reason'            => null,
				'binding_uuid'      => $binding->binding_uuid(),
			);
		}

		return array(
			'eligible' => $eligible && array() !== $results,
			'results'  => $results,
		);
	}

	/**
	 * The commit phase (docs/adr/0042 §2): activates every candidate one
	 * lock-scoped transaction at a time. On the first commit-time failure,
	 * halts immediately and compensates every candidate already activated
	 * in this same call — each compensation is itself its own lock-scoped,
	 * CAS-guarded transaction, run synchronously within this same still-
	 * quiescent invocation (the property that makes compensation provably
	 * traffic-safe, docs/adr/0042 §2).
	 *
	 * Caller contract: must only be invoked after `preflight()` reported
	 * `eligible: true` for the identical cohort, and the run must already
	 * be in `activating` state (`CutoverRunRepository::transition_to_activating()`
	 * already called) — this method does not itself manage run-state
	 * transitions beyond recording the activation-audit rows.
	 *
	 * @param int                $run_id      The run's primary key, for audit correlation.
	 * @param array<int, string> $binding_uuids Cohort candidates, as `binding_uuid`s (already resolved by a prior, matching `preflight()` call).
	 *
	 * @return array{success: bool, activated: array<int, string>, failed_candidate: ?string, compensated: array<int, string>}
	 */
	public function commit( int $run_id, array $binding_uuids ): array {
		$activated = array();

		foreach ( $binding_uuids as $binding_uuid ) {
			$binding = $this->bindings->find_by_uuid( $binding_uuid );

			if ( null === $binding || ChannelBinding::STATUS_PREPARED !== $binding->status() ) {
				return $this->fail_and_compensate( $run_id, $binding_uuid, $activated );
			}

			$from_cas    = $binding->cas_version();
			$lock_result = $this->quiescence->with_quiescence_lock(
				function () use ( $binding_uuid, $from_cas ): bool {
					return $this->bindings->activate_prepared( $binding_uuid, $from_cas );
				}
			);

			if ( QuiescenceGate::LOCK_RESULT_COMMITTED !== $lock_result ) {
				return $this->fail_and_compensate( $run_id, $binding_uuid, $activated );
			}

			$this->runs->record_activation_audit( $run_id, $binding_uuid, 'activate', $from_cas, $from_cas + 1 );
			$activated[] = $binding_uuid;
		}

		return array(
			'success'          => true,
			'activated'        => $activated,
			'failed_candidate' => null,
			'compensated'      => array(),
		);
	}

	/**
	 * Compensates every already-activated candidate in this run back to
	 * `prepared`, in reverse order, then reports the whole cohort's
	 * failure.
	 *
	 * @param int                $run_id           The run's primary key.
	 * @param string             $failed_candidate The candidate whose commit failed.
	 * @param array<int, string> $activated        Candidates already committed `active` in this same call.
	 *
	 * @return array{success: bool, activated: array<int, string>, failed_candidate: string, compensated: array<int, string>}
	 */
	private function fail_and_compensate( int $run_id, string $failed_candidate, array $activated ): array {
		$compensated = array();

		foreach ( array_reverse( $activated ) as $binding_uuid ) {
			$binding = $this->bindings->find_by_uuid( $binding_uuid );

			if ( null === $binding || ChannelBinding::STATUS_ACTIVE !== $binding->status() ) {
				continue;
			}

			$from_cas    = $binding->cas_version();
			$lock_result = $this->quiescence->with_quiescence_lock(
				function () use ( $binding_uuid, $from_cas ): bool {
					return $this->bindings->revert_activation( $binding_uuid, $from_cas );
				}
			);

			if ( QuiescenceGate::LOCK_RESULT_COMMITTED === $lock_result ) {
				$this->runs->record_activation_audit( $run_id, $binding_uuid, 'compensate', $from_cas, $from_cas + 1 );
				$compensated[] = $binding_uuid;
			}
		}

		return array(
			'success'          => false,
			'activated'        => $activated,
			'failed_candidate' => $failed_candidate,
			'compensated'      => $compensated,
		);
	}
}
