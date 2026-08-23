<?php
/**
 * AI draft value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Draft;

/**
 * Immutable read model of one row of universal_telegram_ai_drafts
 * (docs/adr/0028 decisions 4–5). Never carries decrypted body text —
 * only the ciphertext envelope, which AiDraftRepository decrypts on
 * demand for a specific, purpose-bound caller (the operator review UI).
 */
final class AiDraft {

	/**
	 * Constructor.
	 *
	 * @param int         $id                          Primary key.
	 * @param string      $draft_uuid                  Opaque queue/reference identifier.
	 * @param int         $conversation_id             The owning conversation.
	 * @param string      $status                      One of the fixed lifecycle states.
	 * @param string      $provider                    Traceability copy at request time.
	 * @param string      $model                       Traceability copy at request time.
	 * @param string      $prompt_policy_version       The prompt policy version used.
	 * @param string|null $source_ids_json             JSON array of approved source ids/revisions.
	 * @param string|null $context_fingerprint         SHA-256 of the submitted context.
	 * @param string|null $body_ciphertext             CredentialVault-encrypted draft text, null until generated.
	 * @param string|null $failure_class               Fixed taxonomy code, set only when status is failed.
	 * @param int|null    $requested_by_user_id        The requesting operator, or null once anonymized on account deletion.
	 * @param int|null    $reviewed_by_user_id         The reviewing operator, once set.
	 * @param string|null $job_reference               The current Action Scheduler action id.
	 * @param string|null $lease_token                 The current generation-claim token.
	 * @param string|null $generation_lease_expires_at When the current claim expires.
	 * @param string|null $claimed_at                  When the current claim was taken.
	 * @param int         $attempt_count               Total generation attempts across all recovery paths.
	 * @param string      $created_at                  Creation timestamp.
	 * @param string|null $generated_at                When the draft body was produced.
	 * @param string|null $reviewed_at                 When the operator marked it reviewed/approved/discarded.
	 * @param string      $updated_at                  Last status-transition timestamp.
	 */
	public function __construct(
		private readonly int $id,
		private readonly string $draft_uuid,
		private readonly int $conversation_id,
		private readonly string $status,
		private readonly string $provider,
		private readonly string $model,
		private readonly string $prompt_policy_version,
		private readonly ?string $source_ids_json,
		private readonly ?string $context_fingerprint,
		private readonly ?string $body_ciphertext,
		private readonly ?string $failure_class,
		private readonly ?int $requested_by_user_id,
		private readonly ?int $reviewed_by_user_id,
		private readonly ?string $job_reference,
		private readonly ?string $lease_token,
		private readonly ?string $generation_lease_expires_at,
		private readonly ?string $claimed_at,
		private readonly int $attempt_count,
		private readonly string $created_at,
		private readonly ?string $generated_at,
		private readonly ?string $reviewed_at,
		private readonly string $updated_at
	) {}

	public function id(): int {
		return $this->id;
	}

	public function draft_uuid(): string {
		return $this->draft_uuid;
	}

	public function conversation_id(): int {
		return $this->conversation_id;
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

	public function source_ids_json(): ?string {
		return $this->source_ids_json;
	}

	public function context_fingerprint(): ?string {
		return $this->context_fingerprint;
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

	public function job_reference(): ?string {
		return $this->job_reference;
	}

	public function lease_token(): ?string {
		return $this->lease_token;
	}

	public function generation_lease_expires_at(): ?string {
		return $this->generation_lease_expires_at;
	}

	public function claimed_at(): ?string {
		return $this->claimed_at;
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

	public function reviewed_at(): ?string {
		return $this->reviewed_at;
	}

	public function updated_at(): string {
		return $this->updated_at;
	}

	public function is_active(): bool {
		return in_array( $this->status, array( 'queued', 'generating' ), true );
	}

	public function is_retained(): bool {
		return in_array( $this->status, array( 'generated', 'reviewed', 'approved' ), true );
	}
}
