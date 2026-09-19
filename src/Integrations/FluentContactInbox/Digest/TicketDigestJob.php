<?php
/**
 * Periodic open-ticket digest.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Integrations\FluentContactInbox\Digest;

use Closure;
use DateTimeZone;
use UniversalTelegram\Integrations\FluentContactInbox\SupportDeskGateway;

/**
 * A chain of one-shot Action Scheduler actions (docs/adr/0046). Each
 * occurrence is computed from the wall clock in the site timezone, so it is
 * DST-correct; `run()` clears any other pending occurrence and schedules the
 * next before doing anything that could fail, so exactly one future action
 * exists and a failed send never breaks the chain.
 */
final class TicketDigestJob {

	public const HOOK = 'universal_telegram_fluent_contact_inbox_digest';

	/**
	 * Constructor.
	 *
	 * @param SupportDeskGateway    $desk      The support desk.
	 * @param TicketDigestSettings  $settings  Digest settings.
	 * @param DigestSender          $sender    Delivery.
	 * @param DigestActionScheduler $scheduler Action Scheduler port.
	 * @param Closure|null          $clock     Returns the current UTC timestamp; defaults to time().
	 * @param Closure|null          $timezone  Returns the site DateTimeZone; defaults to wp_timezone().
	 */
	public function __construct(
		private readonly SupportDeskGateway $desk,
		private readonly TicketDigestSettings $settings,
		private readonly DigestSender $sender,
		private readonly DigestActionScheduler $scheduler,
		private readonly ?Closure $clock = null,
		private readonly ?Closure $timezone = null
	) {}

	/**
	 * Ensures the chain exists (or is cleared when disabled). Idempotent; safe on every request.
	 */
	public function ensure_scheduled(): void {
		$config = $this->settings->get();

		if ( ! $this->is_configured( $config ) ) {
			if ( $this->scheduler->has_pending() ) {
				$this->scheduler->unschedule_all();
			}

			return;
		}

		if ( ! $this->scheduler->has_pending() ) {
			$this->schedule_next( $config );
		}
	}

	/**
	 * Replaces the pending occurrence with one computed from the current settings (called on settings save).
	 */
	public function reschedule(): void {
		$this->scheduler->unschedule_all();

		$config = $this->settings->get();

		if ( $this->is_configured( $config ) ) {
			$this->schedule_next( $config );
		}
	}

	/**
	 * The Action Scheduler callback.
	 */
	public function run(): void {
		$config = $this->settings->get();

		$this->scheduler->unschedule_all();

		if ( ! $this->is_configured( $config ) ) {
			return;
		}

		$this->schedule_next( $config );

		$now  = $this->now();
		$text = TicketDigestFormatter::format(
			$this->desk->count_by_status( 'open' ),
			$this->desk->count_by_status( 'pending' ),
			$this->desk->oldest_open( TicketDigestFormatter::MAX_ITEMS ),
			$now,
			$this->site_timezone()
		);

		$this->sender->send( (int) $config['bot_id'], (int) $config['destination_id'], $text );
	}

	/**
	 * Whether the digest is enabled and fully configured.
	 *
	 * @param array{enabled: bool, bot_id: int|null, destination_id: int|null, interval: string, time: string} $config Settings.
	 *
	 * @return bool
	 */
	private function is_configured( array $config ): bool {
		return $config['enabled'] && null !== $config['bot_id'] && null !== $config['destination_id'];
	}

	/**
	 * Schedules the next occurrence.
	 *
	 * @param array{enabled: bool, bot_id: int|null, destination_id: int|null, interval: string, time: string} $config Settings.
	 */
	private function schedule_next( array $config ): void {
		$this->scheduler->schedule_single(
			DigestSchedule::next_occurrence( $this->now(), $config['interval'], $config['time'], $this->site_timezone() )
		);
	}

	/**
	 * Current UTC timestamp.
	 *
	 * @return int
	 */
	private function now(): int {
		return null !== $this->clock ? (int) ( $this->clock )() : time();
	}

	/**
	 * The site timezone.
	 *
	 * @return DateTimeZone
	 */
	private function site_timezone(): DateTimeZone {
		return null !== $this->timezone ? ( $this->timezone )() : wp_timezone();
	}
}
