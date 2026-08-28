<?php
/**
 * Destination-eligibility filter for bot/destination pair selection.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

/**
 * The set of a bot's destinations that may be offered as a selectable
 * pair — for the Support Chat adapter's parent forum/supergroup, and any
 * admin UI that lets an operator pick a destination.
 *
 * Since ADR-0044 removed the legacy website chat, there is no
 * "conversation-linked" destination class to exclude; eligibility is now
 * simply "the destination is enabled and belongs to an active bot".
 */
class DestinationEligibility {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository  $bots         Bot lookup.
	 * @param DestinationRepository $destinations Destination lookup.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * Whether one destination is a valid pair choice for one bot.
	 *
	 * @param int $bot_id         The bot's primary key.
	 * @param int $destination_id The destination's primary key.
	 *
	 * @return bool
	 */
	public function destination_is_eligible( int $bot_id, int $destination_id ): bool {
		$bot = $this->bots->find( $bot_id );

		if ( null === $bot || BotStatus::ACTIVE !== $bot->status() ) {
			return false;
		}

		$destination = $this->destinations->find( $destination_id );

		return null !== $destination
			&& $destination->enabled()
			&& $destination->bot_id() === $bot_id;
	}

	/**
	 * Every enabled destination belonging to the given bot.
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return array<int, Destination>
	 */
	public function eligible_destinations_for_bot( int $bot_id ): array {
		return array_values(
			array_filter(
				$this->destinations->for_bot( $bot_id ),
				static fn ( $destination ): bool => $destination->enabled()
			)
		);
	}
}
