<?php
/**
 * Operational-summary AI generation queue handler.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

use RuntimeException;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AIDraftGenerationHandler;
use UniversalTelegram\AI\Provider\AiFailureClassification;
use UniversalTelegram\AI\Provider\AiFailureClassifier;
use UniversalTelegram\AI\Provider\OpenAi\OpenAiAdapter;
use UniversalTelegram\AI\Provider\ProviderConcurrencyGate;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Queue\WorkerRunner;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;

/**
 * The queue's registered handler for JOB_TYPE
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §3).
 * Mirrors AI\Draft\AIDraftGenerationHandler's exact reliability structure —
 * circuit-open and concurrency-cap deferrals never throw and never consume
 * Queue\RetryPolicy's attempt budget; TERMINAL/TOKEN_INVALID failures
 * dead-letter immediately; RETRYABLE failures rethrow for WorkerRunner's own
 * bounded retry sequence — but admission is governed by the shared
 * AI\Provider\ProviderConcurrencyGate, summing this domain's own active
 * count together with AI\Draft\AiDraftRepository's, against M09's unchanged
 * cap of 2.
 */
class SummaryAiGenerationHandler {

	public const JOB_TYPE = 'operational_summary_ai_generate';

	public const LEASE_SECONDS              = 90;
	public const MAX_CONCURRENT             = 2;
	private const CIRCUIT_THRESHOLD         = 3;
	private const CIRCUIT_WINDOW_SECONDS    = 600;
	private const CONCURRENCY_DEFER_SECONDS = 5;

	/**
	 * Constructor.
	 *
	 * @param SummaryAiRepository             $drafts                 Draft persistence, claim, and lease.
	 * @param AIProviderRepository            $provider_config        Reads enablement/model/credential (M09's own config, reused).
	 * @param OperationalSummaryPromptBuilder $prompt_builder         Assembles the bounded, aggregate-only prompt.
	 * @param OperationalSummaryRepository    $summary_repository     Reads the typed source row.
	 * @param CircuitBreaker                  $circuit_breaker        Per-provider breaker, the shared 'ai_provider' scope.
	 * @param AiFailureClassifier             $classifier             Classifies a failed provider result.
	 * @param RetryPolicy                     $retry_policy           Consulted only for its own max_attempts().
	 * @param ProviderConcurrencyGate         $concurrency_gate       The shared, cross-feature admission mutex (§3).
	 * @param array<int, callable(): int>     $external_active_count_providers Additional domains' own active-generation counts (e.g. M09's ai_drafts).
	 */
	public function __construct(
		private readonly SummaryAiRepository $drafts,
		private readonly AIProviderRepository $provider_config,
		private readonly OperationalSummaryPromptBuilder $prompt_builder,
		private readonly OperationalSummaryRepository $summary_repository,
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
	 * @throws RuntimeException On a RETRYABLE failure with attempts remaining.
	 */
	public function handle_job( array $job ): void {
		$draft_uuid = (string) ( $job['payload']['draft_uuid'] ?? '' );

		$draft = $this->drafts->find_by_uuid( $draft_uuid );

		if ( null === $draft ) {
			return;
		}

		if ( ! in_array( $draft->status(), array( 'queued', 'generating' ), true ) ) {
			return;
		}

		if ( ! $this->circuit_breaker->may_attempt( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID ) ) {
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
			$this->defer_for_capacity( $job );
			return;
		}

		$config = $this->provider_config->get();

		if ( null === $config || ! $config->is_ready() ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_disabled' );
			return;
		}

		$row = $this->summary_repository->find_typed( $draft->summary_run_id() );

		if ( null === $row ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'summary_not_found' );
			return;
		}

		$credential = $this->provider_config->decrypt_api_key();

		if ( null === $credential || CredentialState::AVAILABLE !== $credential->state() || null === $credential->plaintext() ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_disabled' );
			return;
		}

		$request = $this->prompt_builder->build( $row, $config->model() );

		$adapter = new OpenAiAdapter( $credential->plaintext() );
		$result  = $adapter->complete( $request );

		if ( $result->ok() ) {
			$this->circuit_breaker->record_success( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID );

			$this->drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], (string) $result->text() );
			return;
		}

		$classification = $this->classifier->classify( $result );

		if ( AiFailureClassification::TOKEN_INVALID === $classification ) {
			$this->circuit_breaker->open_indefinitely( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID );
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'token_invalid' );
			return;
		}

		if ( AiFailureClassification::TERMINAL === $classification ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_terminal_error' );
			return;
		}

		// RETRYABLE.
		$this->circuit_breaker->record_failure( AIDraftGenerationHandler::CIRCUIT_SCOPE, AIDraftGenerationHandler::CIRCUIT_SCOPE_ID, self::CIRCUIT_THRESHOLD, self::CIRCUIT_WINDOW_SECONDS );

		if ( $claim['attempt_count'] >= $this->retry_policy->max_attempts() ) {
			$this->drafts->fail( $claim['draft_id'], $claim['lease_token'], 'provider_timeout' );
			return;
		}

		$this->drafts->release_to_queued( $claim['draft_id'], $claim['lease_token'] );

		throw new RuntimeException( 'operational_summary_ai_generation_failed' );
	}

	/**
	 * Non-throwing, budget-preserving deferral for a concurrency-cap miss.
	 *
	 * @param array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>} $job The current job, rescheduled unchanged.
	 */
	private function defer_for_capacity( array $job ): void {
		as_schedule_single_action( time() + self::CONCURRENCY_DEFER_SECONDS, WorkerRunner::HOOK, array( $job ), WorkerRunner::GROUP );
	}
}
