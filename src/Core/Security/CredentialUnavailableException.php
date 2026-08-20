<?php
/**
 * Credential key resolution failure.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Core\Security;

use Exception;

/**
 * Thrown when no key material can be resolved at all, or when an
 * explicitly configured key is malformed. There is no fourth, always
 * available, hardcoded fallback key anywhere in production code.
 */
final class CredentialUnavailableException extends Exception {}
