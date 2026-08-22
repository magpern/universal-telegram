<?php
/**
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\Integration\Support;

use UniversalTelegram\Queue\ExpeditedDispatchTrigger;

/**
 * Counts calls instead of performing any real dependency check or loopback
 * request — keeps every controller test that does not specifically target
 * expedited dispatch deterministic and network-free, while letting the
 * dedicated tests assert on call placement (docs/adr/0023).
 */
final class SpyExpeditedDispatchTrigger extends ExpeditedDispatchTrigger {

	public int $calls = 0;

	public function trigger(): void {
		++$this->calls;
	}
}
