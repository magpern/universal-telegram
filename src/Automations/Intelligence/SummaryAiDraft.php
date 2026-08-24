<?php
/**
 * Operational-summary AI draft value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Intelligence;

/**
 * One universal_telegram_operational_summary_ai_drafts row
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2.6/§4).
 * Exactly one ever exists per summary_run_id (UNIQUE constraint).
 */
final class SummaryAiDraft {

	/**
	 * Constructor.
	 *
	 * @param int         $id                          Primary key.
	 * @param string      $draft_uuid                  Opaque queue/reference identifier.
	 * @param int         $summary_run_id              The owning operational_summary_runs row.
	 * @param string      $status                      One of queued|generating|generated|reviewed|discarded|failed.
	 * @param string      $provider                    Traceability copy at request time.
	 * @param string      $model                       Traceability copy at request time.
	 * @param string      $prompt_policy_version        The prompt policy version in effect.
	 * @param string|null $body_ciphertext             Encrypted AI output, null until generated.
	 * @param string|null $failure_class               Fixed taxonomy code, null unless failed.
	 * @param int|null    $requested_by_user_id        Nullable from creation.
	 * @param int|null    $reviewed_by_user_id         Nullable from creation.
	 * @param string|null $lease_token                 Set on claim, cleared on terminal/release.
	 * @param string|null $generation_lease_expires_at Lease expiry, or null.
	 * @param int         $attempt_count               Incremented on every claim.
	 * @param string      $created_at                  Row creation timestamp.
	 * @param string|null $generated_at                Generation-success timestamp, or null.
	 * @param string      $updated_at                  Last status-transition timestamp.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $draft_uuid,
		private readonly int $summary_run_id,
		private readonly string $status,
		private readonly string $provider,
		private readonly string $model,
		private readonly string $prompt_policy_version,
		private readonly ?string $body_ciphertext,
		private readonly ?string $failure_class,
		private readonly ?int $requested_by_user_id,
		private readonly ?int $reviewed_by_user_id,
		private readonly ?string $lease_token,
		private readonly ?string $generation_lease_expires_at,
		private readonly int $attempt_count,
		private readonly string $created_at,
		private readonly ?string $generated_at,
		private readonly string $updated_at
	) {}

	public function id(): int {
		return $this->id;
	}

	public function draft_uuid(): string {
		return $this->draft_uuid;
	}

	public function summary_run_id(): int {
		return $this->summary_run_id;
	}

	public function status(): string {
		return $this->status;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function model(): string {
		return $this->model;
	}

	public function prompt_policy_version(): string {
		return $this->prompt_policy_version;
	}

	public function body_ciphertext(): ?string {
		return $this->body_ciphertext;
	}

	public function failure_class(): ?string {
		return $this->failure_class;
	}

	public function requested_by_user_id(): ?int {
		return $this->requested_by_user_id;
	}

	public function reviewed_by_user_id(): ?int {
		return $this->reviewed_by_user_id;
	}

	public function lease_token(): ?string {
		return $this->lease_token;
	}

	public function generation_lease_expires_at(): ?string {
		return $this->generation_lease_expires_at;
	}

	public function attempt_count(): int {
		return $this->attempt_count;
	}

	public function created_at(): string {
		return $this->created_at;
	}

	public function generated_at(): ?string {
		return $this->generated_at;
	}

	public function updated_at(): string {
		return $this->updated_at;
	}
}
