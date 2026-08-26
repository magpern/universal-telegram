<?php
/**
 * Thrown when LegacyExportServiceV1 is invoked outside a WP-CLI process.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Migration;

/**
 * The real security boundary is operating-system authority to execute
 * WP-CLI against this install (Support Chat ADR-0008 §4, ADR-0039 §2).
 * This exception is thrown, never silently swallowed, so a caller that
 * somehow reaches export_batch() outside WP-CLI cannot mistake a hard
 * refusal for an empty result set.
 */
final class LegacyExportContextRejectedException extends \RuntimeException {

}
