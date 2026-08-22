<?php
/**
 * Bot setup wizard progress derivation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\ChatWidget\ChatWidgetAvailability;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * Read-only derivation of the setup wizard's five-step progress from
 * already-persisted state (M06.1 plan §"Progress / completion derivation").
 * Never writes anything and stores no wizard state of its own — every
 * completion signal is recomputed on each call from BotProfileRepository,
 * ChatProfileResolver, and ChatWidgetAvailability, all reused exactly as M05
 * and the chat widget itself already use them. Steps 2 and 3 (creating the
 * Telegram support group and adding the bot as its administrator) happen
 * entirely inside Telegram, which this plugin has no way to observe or
 * verify, so they deliberately have no completion predicate here at all —
 * inventing one would be a false positive.
 */
final class BotSetupWizardState {

	/**
	 * Constructor.
	 *
	 * @param ChatProfileResolver     $chat_profiles            Resolves the default bot and its eligible destination.
	 * @param ChatWidgetAvailability $chat_widget_availability The plugin's own single "is the widget usable" predicate.
	 * @param DestinationRepository  $destinations             Used only to look up the connected destination's own row (id) once ChatProfileResolver has already confirmed one is eligible.
	 */
	public function __construct(
		private readonly ChatProfileResolver $chat_profiles,
		private readonly ChatWidgetAvailability $chat_widget_availability,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * The bot this wizard instance is for — the first configured bot, the
	 * same one ChatWidgetAvailability and M05's own start handler already
	 * treat as authoritative. Never a picker across multiple bots.
	 *
	 * @return BotProfile|null Null if no bot is configured at all.
	 */
	public function default_bot(): ?BotProfile {
		return $this->chat_profiles->default_bot();
	}

	/**
	 * Step 1 (create bot): complete once the default bot's token has been
	 * validated against Telegram at least once (create_bot/test_connection's
	 * existing getMe call populates telegram_username()).
	 *
	 * @return bool
	 */
	public function step_one_complete(): bool {
		$bot = $this->default_bot();

		return null !== $bot && null !== $bot->telegram_username();
	}

	/**
	 * Step 4 (connect group): complete once the default bot has one enabled
	 * supergroup destination with no message_thread_id of its own — the
	 * exact eligibility rule ChatProfileResolver::conversation_chat_id()
	 * already enforces.
	 *
	 * @return bool
	 */
	public function step_four_complete(): bool {
		$bot = $this->default_bot();

		if ( null === $bot ) {
			return false;
		}

		return null !== $this->chat_profiles->conversation_chat_id( $bot->id() );
	}

	/**
	 * Step 5 (activate chat widget): complete only once the widget is
	 * actually usable end-to-end (ChatWidgetAvailability::is_available(),
	 * reused whole) and the webhook is registered — is_available() alone
	 * does not guarantee the webhook side is done.
	 *
	 * @return bool
	 */
	public function step_five_complete(): bool {
		$bot = $this->default_bot();

		if ( null === $bot ) {
			return false;
		}

		return $this->chat_widget_availability->is_available()
			&& 'registered' === $bot->webhook_registration_state();
	}

	/**
	 * The first incomplete step among the wizard's verifiable steps
	 * (1, 4, 5); steps 2 and 3 never gate this, since they carry no
	 * completion state of their own. Returns 5 once all three are complete.
	 *
	 * @return int
	 */
	public function current_step(): int {
		if ( ! $this->step_one_complete() ) {
			return 1;
		}

		if ( ! $this->step_four_complete() ) {
			return 4;
		}

		return 5;
	}

	/**
	 * Whether every verifiable step (1, 4, 5) is complete.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return $this->step_one_complete() && $this->step_four_complete() && $this->step_five_complete();
	}

	/**
	 * The destination row backing step 4's connected group, once eligible
	 * (ChatProfileResolver::conversation_chat_id() has already confirmed
	 * one exists) — used only so the wizard's "Send test message" action
	 * can target its destination_id. The eligibility rule itself stays
	 * owned by ChatProfileResolver; this only correlates its result back to
	 * the destination row.
	 *
	 * @return Destination|null
	 */
	public function connected_destination(): ?Destination {
		$bot = $this->default_bot();

		if ( null === $bot ) {
			return null;
		}

		$chat_id = $this->chat_profiles->conversation_chat_id( $bot->id() );

		if ( null === $chat_id ) {
			return null;
		}

		foreach ( $this->destinations->for_bot( $bot->id() ) as $destination ) {
			if ( $destination->chat_id() === $chat_id
				&& DestinationKind::SUPERGROUP === $destination->kind()
				&& null === $destination->message_thread_id() ) {
				return $destination;
			}
		}

		return null;
	}
}
