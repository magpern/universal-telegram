<?php
/**
 * Support-ticket digest settings.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

use Closure;

/**
 * One option holding the digest's bot, destination, interval and time of
 * day. The reader/writer are injectable so the class is pure under test.
 */
final class TicketDigestSettings {

	public const OPTION_NAME = 'universal_telegram_support_digest';

	/**
	 * Constructor.
	 *
	 * @param Closure|null $reader Returns the stored value; defaults to get_option().
	 * @param Closure|null $writer Persists a value; defaults to update_option().
	 */
	public function __construct(
		private readonly ?Closure $reader = null,
		private readonly ?Closure $writer = null
	) {}

	/**
	 * The stored settings merged over the defaults, always well-formed.
	 *
	 * @return array{enabled: bool, bot_id: int|null, destination_id: int|null, interval: string, time: string}
	 */
	public function get(): array {
		$stored = null !== $this->reader ? ( $this->reader )() : get_option( self::OPTION_NAME, array() );

		return self::sanitize( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitizes and stores new settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return array{enabled: bool, bot_id: int|null, destination_id: int|null, interval: string, time: string}
	 */
	public function save( array $input ): array {
		$clean = self::sanitize( $input );

		if ( null !== $this->writer ) {
			( $this->writer )( $clean );
		} else {
			update_option( self::OPTION_NAME, $clean );
		}

		return $clean;
	}

	/**
	 * Normalizes arbitrary input to a valid settings array.
	 *
	 * @param array<string, mixed> $input Raw input.
	 *
	 * @return array{enabled: bool, bot_id: int|null, destination_id: int|null, interval: string, time: string}
	 */
	public static function sanitize( array $input ): array {
		$bot_id         = isset( $input['bot_id'] ) && is_numeric( $input['bot_id'] ) ? (int) $input['bot_id'] : 0;
		$destination_id = isset( $input['destination_id'] ) && is_numeric( $input['destination_id'] ) ? (int) $input['destination_id'] : 0;
		$interval       = isset( $input['interval'] ) && DigestSchedule::INTERVAL_WEEKLY === $input['interval']
			? DigestSchedule::INTERVAL_WEEKLY
			: DigestSchedule::INTERVAL_DAILY;
		$time           = isset( $input['time'] ) && is_string( $input['time'] ) && 1 === preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $input['time'] )
			? $input['time']
			: '09:00';

		return array(
			'enabled'        => ! empty( $input['enabled'] ),
			'bot_id'         => $bot_id > 0 ? $bot_id : null,
			'destination_id' => $destination_id > 0 ? $destination_id : null,
			'interval'       => $interval,
			'time'           => $time,
		);
	}
}
