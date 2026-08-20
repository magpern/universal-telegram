<?php
/**
 * Unit-test bootstrap. Carries no WordPress bootstrap of any kind.
 *
 * @package UniversalTelegram
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// A deterministic fallback key may be injected only by tests. This is the
// one and only place in the entire codebase that defines
// UNIVERSAL_TELEGRAM_CREDENTIAL_KEY; production code never does.
define( 'UNIVERSAL_TELEGRAM_CREDENTIAL_KEY', str_repeat( 'ab', 32 ) );
