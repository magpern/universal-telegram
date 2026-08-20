<?php
/**
 * Queued job envelope.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Queue;

use UniversalTelegram\Privacy\Classification;

/**
 * Immutable value object carrying a stable job ID, threaded unchanged
 * through every retry attempt of the same logical job. The constructor
 * enforces a fail-closed payload classification policy: any field left
 * unclassified, or classified SENSITIVE or SECRET, rejects construction
 * immediately (docs/adr/0006).
 */
final class JobEnvelope {

	/**
	 * The stable job ID, unchanged across every retry attempt.
	 *
	 * @var string
	 */
	private string $job_id;

	/**
	 * Identifies which registered handler owns this job.
	 *
	 * @var string
	 */
	private string $job_type;

	/**
	 * The attempt number. 1 for a fresh job.
	 *
	 * @var int
	 */
	private int $attempt;

	/**
	 * The job's own data.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * Constructor.
	 *
	 * @param string                        $job_type            Identifies which registered handler owns this job.
	 * @param array<string, mixed>          $payload             The job's own data.
	 * @param array<string, Classification> $classification_map  Every payload field's classification; no exceptions.
	 * @param int                           $attempt             The attempt number. 1 for a fresh job.
	 * @param string|null                   $job_id              An existing job ID to preserve across a retry; a
	 *                                                            fresh one is generated when omitted.
	 *
	 * @throws PayloadRejectedException If any payload field is unclassified,
	 *                                   or classified SENSITIVE or SECRET.
	 */
	public function __construct(
		string $job_type,
		array $payload,
		array $classification_map,
		int $attempt = 1,
		?string $job_id = null
	) {
		$this->assert_payload_is_safe( $payload, $classification_map );

		$this->job_id   = $job_id ?? wp_generate_uuid4();
		$this->job_type = $job_type;
		$this->attempt  = $attempt;
		$this->payload  = $payload;
	}

	/**
	 * Enforces the fail-closed payload classification policy.
	 *
	 * @param array<string, mixed>          $payload             The job's own data.
	 * @param array<string, Classification> $classification_map  Every payload field's classification.
	 *
	 * @throws PayloadRejectedException If any payload field is unclassified,
	 *                                   or classified SENSITIVE or SECRET.
	 */
	private function assert_payload_is_safe( array $payload, array $classification_map ): void {
		foreach ( $payload as $field => $value ) {
			if ( ! isset( $classification_map[ $field ] ) ) {
				throw new PayloadRejectedException(
					sprintf( 'Queue payload field "%s" has no classification.', $field )
				);
			}

			$classification = $classification_map[ $field ];

			if ( Classification::SENSITIVE === $classification || Classification::SECRET === $classification ) {
				throw new PayloadRejectedException(
					sprintf( 'Queue payload field "%s" is classified %s and cannot be queued.', $field, $classification->value )
				);
			}
		}
	}

	/**
	 * The stable job ID, unchanged across every retry attempt.
	 *
	 * @return string
	 */
	public function job_id(): string {
		return $this->job_id;
	}

	/**
	 * Identifies which registered handler owns this job.
	 *
	 * @return string
	 */
	public function job_type(): string {
		return $this->job_type;
	}

	/**
	 * The attempt number.
	 *
	 * @return int
	 */
	public function attempt(): int {
		return $this->attempt;
	}

	/**
	 * The job's own data.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * The exact shape stored as this job's Action Scheduler action
	 * arguments: a single associative array, never sensitive data.
	 *
	 * @return array{job_id: string, job_type: string, attempt: int, payload: array<string, mixed>}
	 */
	public function to_action_args(): array {
		return array(
			'job_id'   => $this->job_id,
			'job_type' => $this->job_type,
			'attempt'  => $this->attempt,
			'payload'  => $this->payload,
		);
	}
}
