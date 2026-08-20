<?php
/**
 * Unclassified event field.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use Exception;

/**
 * Thrown when EventEnvelope is constructed with a field in actor/subject/
 * context/payload that has no classification-map entry (fail-closed,
 * mirroring Queue\JobEnvelope and Privacy\Redactor), or when
 * Registry::register() is given an allowed_variable_fields or
 * history_projection_fields entry not present in its own
 * field_classification_map (M02 plan §5.1, §5.2).
 */
final class UnclassifiedFieldException extends Exception {}
