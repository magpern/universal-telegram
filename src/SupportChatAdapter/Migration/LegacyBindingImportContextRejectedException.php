<?php
/**
 * Thrown when LegacyBindingImportServiceV1 is invoked outside a WP-CLI process.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Migration;

/**
 * The real security boundary is operating-system authority to execute
 * WP-CLI against this install (Support Chat ADR-0009 §7, ADR-0041 §2). This
 * exception is thrown, never silently swallowed, mirroring
 * LegacyExportContextRejectedException's identical role for the read-side
 * boundary.
 */
final class LegacyBindingImportContextRejectedException extends \RuntimeException {

}
