<?php
/**
 * Bot setup wizard progress derivation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * Read-only derivation of the bot setup wizard's progress from
 * already-persisted state. Never writes anything and stores no wizard
 * state of its own — every completion signal is recomputed on each call.
 *
 * Since ADR-0044 (transport/adapter only) the wizard no longer has a
 * chat-widget activation step; the verifiable steps are: (1) the bot's
 * token has been validated against Telegram at least once, (4) the bot
 * has at least one enabled destination, and (5) its webhook is registered.
 */
final class BotSetupWizardState {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository  $bots         Resolves the default (first configured) bot.
	 * @param DestinationRepository $destinations The bot's configured destinations.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * The first configured bot.
	 *
	 * @return BotProfile|null Null if no bot is configured at all.
	 */
	public function default_bot(): ?BotProfile {
		$all = $this->bots->all();

		return array() === $all ? null : $all[0];
	}

	/**
	 * Whether the given bot is the first configured bot.
	 *
	 * @param BotProfile $bot The bot to check.
	 *
	 * @return bool
	 */
	public function is_default_bot( BotProfile $bot ): bool {
		$default = $this->default_bot();

		return null !== $default && $default->id() === $bot->id();
	}

	/**
	 * Step 1 (create bot): complete once the bot's token has been validated
	 * against Telegram at least once (which populates telegram_username()).
	 *
	 * @param BotProfile $bot The bot being configured.
	 *
	 * @return bool
	 */
	public function step_one_complete( BotProfile $bot ): bool {
		return null !== $bot->telegram_username();
	}

	/**
	 * Step 4 (connect a destination): complete once the bot has at least
	 * one enabled destination.
	 *
	 * @param BotProfile $bot The bot being configured.
	 *
	 * @return bool
	 */
	public function step_four_complete( BotProfile $bot ): bool {
		foreach ( $this->destinations->for_bot( $bot->id() ) as $destination ) {
			if ( $destination->enabled() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Step 5 (register webhook): complete once the webhook is registered.
	 *
	 * @param BotProfile $bot The bot being configured.
	 *
	 * @return bool
	 */
	public function step_five_complete( BotProfile $bot ): bool {
		return 'registered' === $bot->webhook_registration_state();
	}

	/**
	 * The first incomplete step among the verifiable steps (1, 4, 5).
	 * Returns 5 once all three are complete.
	 *
	 * @param BotProfile $bot The bot being configured.
	 *
	 * @return int
	 */
	public function current_step( BotProfile $bot ): int {
		if ( ! $this->step_one_complete( $bot ) ) {
			return 1;
		}

		if ( ! $this->step_four_complete( $bot ) ) {
			return 4;
		}

		return 5;
	}

	/**
	 * Whether every verifiable step (1, 4, 5) is complete for the given bot.
	 *
	 * @param BotProfile $bot The bot being configured.
	 *
	 * @return bool
	 */
	public function is_complete( BotProfile $bot ): bool {
		return $this->step_one_complete( $bot ) && $this->step_four_complete( $bot ) && $this->step_five_complete( $bot );
	}

	/**
	 * The first enabled destination row for the bot, used only so the
	 * wizard's "Send test message" action can target its destination_id.
	 *
	 * @param BotProfile $bot The bot being configured.
	 *
	 * @return Destination|null
	 */
	public function connected_destination( BotProfile $bot ): ?Destination {
		foreach ( $this->destinations->for_bot( $bot->id() ) as $destination ) {
			if ( $destination->enabled() ) {
				return $destination;
			}
		}

		return null;
	}
}
