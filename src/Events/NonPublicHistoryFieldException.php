<?php
/**
 * Non-PUBLIC history-projection field.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events;

use Exception;

/**
 * Thrown by Registry::register() when a field listed in
 * history_projection_fields is not classified exactly PUBLIC in the same
 * call's field_classification_map (docs/adr/0017). INTERNAL fields may be
 * used transiently for rule conditions and message templates but can never
 * reach the durable history projection.
 */
final class NonPublicHistoryFieldException extends Exception {}
