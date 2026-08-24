<?php
/**
 * AI draft generation queue handler.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Draft;

use RuntimeException;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Provider\AiFailureClassification;
use UniversalTelegram\AI\Provider\AiFailureClassifier;
use UniversalTelegram\AI\Provider\OpenAi\OpenAiAdapter;
use UniversalTelegram\AI\Provider\ProviderConcurrencyGate;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;

/**
 * The queue's registered handler for JOB_TYPE (docs/adr/0028 decisions 4
 * and 5). Re-derives everything from the draft_uuid at execution time —
 * the JobEnvelope payload never carries conversation content or a
 * credential. Implements the full AI-specific reliability decision tree
 * on top of the generic queue, mirroring
 * Telegram\Outbound\SendMessageHandler's exact structure: circuit-open and
 * concurrency-cap deferrals never throw and never consume
 * Queue\RetryPolicy's attempt budget; TERMINAL and TOKEN_INVALID failures
 * dead-letter immediately without throwing; RETRYABLE failures rethrow so
 * WorkerRunner's own generic sequence runs, except on the final permitted
 * attempt (checked against this draft's own shared attempt_count, not
 * WorkerRunner's per-action attempt counter, so the budget stays shared
 * across a stale-lease sweep's re-enqueue too), where this handler also
 * dead-letters before returning.
 */
class AIDraftGenerationHandler {

	public const JOB_TYPE = 'ai_draft_generate';

	public const LEASE_SECONDS              = 90;
	public const MAX_CONCURRENT             = 2;
	public const CIRCUIT_SCOPE              = 'ai_provider';
	public const CIRCUIT_SCOPE_ID           = 1;
	private const CIRCUIT_THRESHOLD         = 3;
	private const CIRCUIT_WINDOW_SECONDS    = 600;
	private const CONCURRENCY_DEFER_SECONDS = 5;

	/**
	 * Constructor.
	 *
	 * @param AiDraftRepository       $drafts                          Draft persistence, claim, and lease.
	 * @param AIProviderRepository    $provider_config                 Reads enablement/model/credential.
	 * @param PromptBuilder           $prompt_builder                  Assembles the bounded, source-grounded prompt.
	 * @param CircuitBreaker          $circuit_breaker                 Per-provider breaker, 'ai_provider' scope.
	 * @param AiFailureClassifier     $classifier                      Classifies a failed provider result.
	 * @param RetryPolicy             $retry_policy                    Consulted only for its own max_attempts().
	 * @param ProviderConcurrencyGate $concurrency_gate                The shared, cross-feature admission mutex (M11B plan §3) — replaces this handler's own prior direct call to AiDraftRepository::claim_for_generation(), which remains present, unchanged, for backward compatibility with existing direct callers/tests only.
	 * @param array<int, callable(): int> $external_active_count_providers Additional domains' own active-generation counts to sum against the shared cap (e.g. M11B's operational-summary AI drafts). Empty by default.
	 */
	public function __construct(
		private readonly AiDraftRepository $drafts,
		private readonly AIProviderRepository $provider_config,
		private readonly PromptBuilder $prompt_builder,
		private readonly CircuitBreaker $circuit_breaker,
		private readonly AiFailureClassifier $classifier,
		private readonly RetryPolicy $retry_policy,
		private readonly ProviderConcurrencyGate $concurrency_gate,
		private readonly array $external_active_count_providers = array()
	) {}

	/**
	 * The Action Scheduler job handler.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The job.
	 *
	 * @throws RuntimeException On a RETRYABLE failure with attempts remaining, so WorkerRunner's own generic retry sequence runs.
	 */
	public function handle_job( array $job ): void {
		$draft_uuid = (string) ( $job['payload']['draft_uuid'] ?? '' );

		$draft = $this->drafts->find_by_uuid( $draft_uuid );

		if ( null === $draft ) {
			// Purged, or never existed — nothing to do; not a failure of
			// this attempt.
			return;
		}

		if ( ! in_array( $draft->status(), array( 'queued', 'generating' ), true ) ) {
			// Already resolved by another path (e.g. the sweep dead-lettered
			// it, or the provider was disabled mid-flight) — a clean no-op.
			return;
		}

		if ( ! $this->circuit_breaker->may_attempt( self::CIRCUIT_SCOPE, self::CIRCUIT_SCOPE_ID ) ) {
			// No claim is taken; matches immediately from whichever
			// pre-claim status the row is currently in.
			$this->drafts->fail( $draft->id(), null, 'circuit_open' );
			return;
		}

		$claim = $this->concurrency_gate->claim_or_defer(
			self::MAX_CONCURRENT,
			array_merge(
				array( fn() => $this->drafts->count_active_generating() ),
				$this->external_active_count_providers
			),
			fn() => $this->drafts->claim_candidate_row( $draft_uuid, self::LEASE_SECONDS )
		);

		if ( null === $claim ) {
			// Either the concurrency cap is currently reached, or another
			// worker already holds/won this row's claim — deferred, not
			// failed; the same (unincremented) job is rescheduled shortly,
			// exactly like SendMessageHandler's own rate-limit/circuit
			// deferrals, never consuming the retry budget.
			$this->defer_for_capacity( $job );
			return;
		}

		$config = $this->provider_config->get();

		if ( null === $config || ! $config->is_ready() ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_disabled' );
			return;
		}

		$built = $this->prompt_builder->build( $draft->conversation_id(), $config->model() );

		if ( null === $built ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'no_matching_source' );
			return;
		}

		$credential = $this->provider_config->decrypt_api_key();

		if ( null === $credential || CredentialState::AVAILABLE !== $credential->state() || null === $credential->plaintext() ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_disabled' );
			return;
		}

		$adapter = new OpenAiAdapter( $credential->plaintext() );
		$result  = $adapter->complete( $built->request() );

		if ( $result->ok() ) {
			$this->circuit_breaker->record_success( self::CIRCUIT_SCOPE, self::CIRCUIT_SCOPE_ID );

			// A stale/superseded claim (compare-and-set loses) is a silent
			// no-op here — the row's current, newer claimant is
			// authoritative (docs/adr/0028 decision 5, §3.3).
			$this->drafts->complete_generation(
				$claim['draft_id'],
				$draft->draft_uuid(),
				$claim['lease_token'],
				(string) $result->text(),
				$built->source_ids_json(),
				$built->context_fingerprint(),
				PromptBuilder::POLICY_VERSION
			);
			return;
		}

		$classification = $this->classifier->classify( $result );

		if ( AiFailureClassification::TOKEN_INVALID === $classification ) {
			$this->circuit_breaker->open_indefinitely( self::CIRCUIT_SCOPE, self::CIRCUIT_SCOPE_ID );
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'token_invalid' );
			return;
		}

		if ( AiFailureClassification::TERMINAL === $classification ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_terminal_error' );
			return;
		}

		// RETRYABLE.
		$this->circuit_breaker->record_failure( self::CIRCUIT_SCOPE, self::CIRCUIT_SCOPE_ID, self::CIRCUIT_THRESHOLD, self::CIRCUIT_WINDOW_SECONDS );

		if ( $claim['attempt_count'] >= $this->retry_policy->max_attempts() ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_timeout' );
			return;
		}

		$this->drafts->release_to_queued( $claim['draft_id'], $claim['lease_token'] );

		throw new RuntimeException( 'ai_draft_generation_failed' );
	}

	/**
	 * Non-throwing, budget-preserving deferral for a concurrency-cap miss:
	 * reschedules the same (unincremented-attempt) job at a short delay,
	 * mirroring SendMessageHandler::defer_locally()'s exact precedent.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The current job, rescheduled unchanged.
	 */
	private function defer_for_capacity( array $job ): void {
		as_schedule_single_action( time() + self::CONCURRENCY_DEFER_SECONDS, WorkerRunner::HOOK, array( $job ), WorkerRunner::GROUP );
	}
}
