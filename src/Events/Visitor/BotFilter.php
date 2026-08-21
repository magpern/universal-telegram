<?php
/**
 * Crawler/headless user-agent detection.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Visitor;

/**
 * Reads the raw User-Agent request header transiently, only for the
 * duration of one substring match — never persisted, never placed in any
 * event field, never part of the rate-limit HMAC bucket's stored output
 * (M04 plan §4.4).
 */
final class BotFilter {

	private const BOT_SUBSTRINGS = array(
		'bot',
		'crawl',
		'spider',
		'headless',
		'curl/',
		'wget/',
		'python-requests',
		'phantomjs',
		'slurp',
		'facebookexternalhit',
		'pingdom',
		'uptimerobot',
		'monitor',
	);

	/**
	 * Whether the given raw User-Agent header looks like a bot, crawler, or
	 * empty/absent client.
	 *
	 * @param string|null $user_agent The raw User-Agent header value.
	 *
	 * @return bool
	 */
	public function is_bot( ?string $user_agent ): bool {
		if ( null === $user_agent || '' === trim( $user_agent ) ) {
			return true;
		}

		$lower = strtolower( $user_agent );

		foreach ( self::BOT_SUBSTRINGS as $substring ) {
			if ( false !== strpos( $lower, $substring ) ) {
				return true;
			}
		}

		return false;
	}
}
