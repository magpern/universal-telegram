<?php
/**
 * Event envelope value object.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Immutable, transient carrier of one normalized event occurrence. Never
 * itself serialized whole into any queue payload or database row — only
 * EventHistoryRepository's own PUBLIC-only projection (§5.4) and
 * Automations' transient, in-memory rule evaluation (§7) ever read from an
 * instance of this class. Construction is fail-closed: an unregistered
 * event type, an invalid idempotency key, or any actor/subject/context/
 * payload field with no classification-map entry each reject construction
 * immediately (docs/adr/0015).
 */
final class EventEnvelope {

	/**
	 * E.g. "wordpress.user_registered".
	 *
	 * @var string
	 */
	private string $event_type;

	/**
	 * The event type's registered schema version.
	 *
	 * @var int
	 */
	private int $schema_version;

	/**
	 * Source-supplied, mandatory idempotency key.
	 *
	 * @var string
	 */
	private string $idempotency_key;

	/**
	 * The deterministic identity derived from event_type, schema_version,
	 * and idempotency_key.
	 *
	 * @var string
	 */
	private string $event_id;

	/**
	 * UTC occurrence time.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $occurred_at;

	/**
	 * The emitting subsystem.
	 *
	 * @var EventSource
	 */
	private EventSource $source;

	/**
	 * E.g. ['user_id' => 42].
	 *
	 * @var array<string, mixed>
	 */
	private array $actor;

	/**
	 * E.g. ['post_id' => 17].
	 *
	 * @var array<string, mixed>
	 */
	private array $subject;

	/**
	 * E.g. ['ip_hash' => '...'].
	 *
	 * @var array<string, mixed>
	 */
	private array $context;

	/**
	 * Event-type-specific structured fields.
	 *
	 * @var array<string, mixed>
	 */
	private array $payload;

	/**
	 * Constructor.
	 *
	 * @param Registry               $registry        The current request's event registry.
	 * @param string                 $event_type      e.g. "wordpress.user_registered".
	 * @param string                 $idempotency_key Source-supplied, mandatory, never generated internally.
	 * @param EventSource            $source          The emitting subsystem.
	 * @param array<string, mixed>   $actor           e.g. ['user_id' => 42].
	 * @param array<string, mixed>   $subject         e.g. ['post_id' => 17].
	 * @param array<string, mixed>   $context         e.g. ['ip_hash' => '...'].
	 * @param array<string, mixed>   $payload         Event-type-specific structured fields.
	 * @param DateTimeImmutable|null $occurred_at     UTC occurrence time; defaults to now.
	 *
	 * @throws UnregisteredEventTypeException If $event_type is not registered.
	 * @throws InvalidIdempotencyKeyException If $idempotency_key is not 1-255 bytes. Also propagates
	 *                                         UnclassifiedFieldException, thrown by the private
	 *                                         assert_fields_classified() helper this constructor calls,
	 *                                         if any field has no classification-map entry.
	 */
	public function __construct(
		Registry $registry,
		string $event_type,
		string $idempotency_key,
		EventSource $source,
		array $actor,
		array $subject,
		array $context,
		array $payload,
		?DateTimeImmutable $occurred_at = null
	) {
		if ( ! $registry->is_registered( $event_type ) ) {
			throw new UnregisteredEventTypeException( sprintf( 'Event type "%s" is not registered.', $event_type ) );
		}

		$key_length = strlen( $idempotency_key );
		if ( $key_length < 1 || $key_length > 255 ) {
			throw new InvalidIdempotencyKeyException( 'idempotency_key must be a non-empty string of 1-255 bytes.' );
		}

		$schema_version = $registry->schema_version_for( $event_type );
		if ( null === $schema_version ) {
			throw new UnregisteredEventTypeException( sprintf( 'Event type "%s" is not registered.', $event_type ) );
		}

		$classification_map = $registry->classification_map_for( $event_type );

		$this->assert_fields_classified(
			array(
				'actor'   => $actor,
				'subject' => $subject,
				'context' => $context,
				'payload' => $payload,
			),
			$classification_map,
			''
		);

		$this->event_type      = $event_type;
		$this->schema_version  = $schema_version;
		$this->idempotency_key = $idempotency_key;
		$this->event_id        = EventIdentity::derive( $event_type, $schema_version, $idempotency_key );
		$this->occurred_at     = $occurred_at ?? new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$this->source          = $source;
		$this->actor           = $actor;
		$this->subject         = $subject;
		$this->context         = $context;
		$this->payload         = $payload;
	}

	/**
	 * Recursively confirms every scalar leaf across actor/subject/context/
	 * payload has a classification-map entry at its own dot-notation path,
	 * mirroring Privacy\Redactor's own path-walking convention.
	 *
	 * @param array<string, mixed>                                     $data                The data to validate at this depth.
	 * @param array<string, \UniversalTelegram\Privacy\Classification> $classification_map Dot-notation path to classification.
	 * @param string                                                   $prefix              The dot-notation path prefix so far.
	 *
	 * @throws UnclassifiedFieldException If any leaf field has no classification-map entry.
	 */
	private function assert_fields_classified( array $data, array $classification_map, string $prefix ): void {
		foreach ( $data as $key => $value ) {
			$path = '' === $prefix ? (string) $key : $prefix . '.' . $key;

			if ( is_array( $value ) ) {
				$this->assert_fields_classified( $value, $classification_map, $path );
				continue;
			}

			if ( ! isset( $classification_map[ $path ] ) ) {
				throw new UnclassifiedFieldException( sprintf( 'Event field "%s" has no classification.', $path ) );
			}
		}
	}

	/**
	 * E.g. "wordpress.user_registered".
	 *
	 * @return string
	 */
	public function event_type(): string {
		return $this->event_type;
	}

	/**
	 * The event type's registered schema version.
	 *
	 * @return int
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Source-supplied, mandatory idempotency key.
	 *
	 * @return string
	 */
	public function idempotency_key(): string {
		return $this->idempotency_key;
	}

	/**
	 * The deterministic identity derived from event_type, schema_version,
	 * and idempotency_key.
	 *
	 * @return string 64 lower-case hex characters.
	 */
	public function event_id(): string {
		return $this->event_id;
	}

	/**
	 * UTC occurrence time.
	 *
	 * @return DateTimeImmutable
	 */
	public function occurred_at(): DateTimeImmutable {
		return $this->occurred_at;
	}

	/**
	 * The emitting subsystem.
	 *
	 * @return EventSource
	 */
	public function source(): EventSource {
		return $this->source;
	}

	/**
	 * E.g. ['user_id' => 42].
	 *
	 * @return array<string, mixed>
	 */
	public function actor(): array {
		return $this->actor;
	}

	/**
	 * E.g. ['post_id' => 17].
	 *
	 * @return array<string, mixed>
	 */
	public function subject(): array {
		return $this->subject;
	}

	/**
	 * E.g. ['ip_hash' => '...'].
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * Event-type-specific structured fields.
	 *
	 * @return array<string, mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * Reads one allowed variable field's value by its dot-notation path
	 * (e.g. "subject.order_status"), for use only by rule condition
	 * evaluation and template rendering. Returns null if the path does not
	 * exist in this occurrence's own data.
	 *
	 * @param string $path The dot-notation field path.
	 *
	 * @return mixed
	 */
	public function value_at( string $path ) {
		$segments = explode( '.', $path );
		$section  = array_shift( $segments );

		$data = match ( $section ) {
			'actor' => $this->actor,
			'subject' => $this->subject,
			'context' => $this->context,
			'payload' => $this->payload,
			default => null,
		};

		if ( null === $data ) {
			return null;
		}

		foreach ( $segments as $segment ) {
			if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
				return null;
			}
			$data = $data[ $segment ];
		}

		return $data;
	}
}
