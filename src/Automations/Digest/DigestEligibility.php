<?php
/**
 * Shared active/eligibility gate for the visitor activity digest.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations\Digest;

use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * The single shared gate RuleEvaluator's suppression guard and the digest
 * counter increment both consult (docs/plans/m11a-visitor-activity-digests-plan-v1.md
 * §3.1): is_active() is true only while visitor_digest_enabled is set AND
 * the saved bot/destination pair passes the full eligibility rule (§4).
 * Cached in a short-TTL transient so the per-visitor-event check never
 * performs a live bot/destination lookup; the cache is invalidated
 * immediately by every settings save and by
 * BotProfileRepository::CHANGED_ACTION, fired from inside the bot/
 * destination repositories themselves after every successful write,
 * regardless of caller. The transient's own TTL is retained only as a
 * bounded fallback for an out-of-band database change neither of those
 * two triggers can observe.
 *
 * Not declared final: tests/unit/Automations/RuleEvaluatorTest.php,
 * RuleSimulatorTest.php, and their integration-test counterparts double
 * this class via PHPUnit's createMock(), which cannot double a final class
 * (the same precedent Automations\NotificationDispatcher already
 * documents).
 */
class DigestEligibility {

	public const TRANSIENT_KEY = 'universal_telegram_visitor_digest_active';

	private const TRANSIENT_TTL_SECONDS = 30;

	/**
	 * The seven digest-eligible, low-severity visitor event types
	 * suppressed from direct individual dispatch while is_active() is true
	 * (§3, §3.1). visitor.javascript_error is deliberately excluded — §3.3.
	 *
	 * @var array<int, string>
	 */
	public const SUPPRESSED_EVENT_TYPES = array(
		'visitor.page_viewed',
		'visitor.navigation',
		'visitor.product_viewed',
		'visitor.search_performed',
		'visitor.add_to_cart_intent',
		'visitor.checkout_started_intent',
		'visitor.session_started',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings               $settings     Supplies the five visitor_digest_* fields.
	 * @param BotProfileRepository   $bots         Live bot-status lookup.
	 * @param DestinationRepository  $destinations Live destination lookup.
	 * @param ConversationRepository $conversations Excludes conversation-linked destinations.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly ConversationRepository $conversations
	) {}

	/**
	 * Registers the change-triggered cache-invalidation listener. Called
	 * once at bootstrap (Core\Plugin::init()).
	 */
	public function register(): void {
		add_action( BotProfileRepository::CHANGED_ACTION, array( $this, 'invalidate' ) );
	}

	/**
	 * Deletes the cached is_active() value unconditionally. Safe to call
	 * even when nothing actually changed — the next read simply recomputes.
	 */
	public function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}

	/**
	 * Whether suppression and aggregation are currently active: the digest
	 * is enabled and its saved target is genuinely eligible. Backed by a
	 * short-TTL transient (see class docblock); a cache miss recomputes and
	 * repopulates it.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		$cached = get_transient( self::TRANSIENT_KEY );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		return $this->refresh();
	}

	/**
	 * Recomputes and unconditionally repopulates the cache, regardless of
	 * whether a prior cached value existed. Called by is_active() on a
	 * cache miss, and unconditionally by the sweep (§5) on every tick so a
	 * target-validity regression is caught even during a quiet period with
	 * no visitor traffic to trigger a natural recompute.
	 *
	 * @return bool
	 */
	public function refresh(): bool {
		$active = $this->compute();

		set_transient( self::TRANSIENT_KEY, $active ? '1' : '0', self::TRANSIENT_TTL_SECONDS );

		return $active;
	}

	/**
	 * Whether visitor_digest_enabled is currently set, independent of
	 * target validity. Used only for diagnostics (§7) to distinguish
	 * "intentionally disabled" from "enabled but target invalid".
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		return true === (bool) $this->settings->get()['visitor_digest_enabled'];
	}

	/**
	 * Whether the currently saved bot/destination pair passes the full
	 * eligibility rule (§4), independent of visitor_digest_enabled. Used
	 * both by is_active()'s own computation and by diagnostics/UI.
	 *
	 * @return bool
	 */
	public function target_valid(): bool {
		$values = $this->settings->get();

		$bot_id         = $values['visitor_digest_bot_id'] ?? null;
		$destination_id = $values['visitor_digest_destination_id'] ?? null;

		if ( null === $bot_id || null === $destination_id ) {
			return false;
		}

		return $this->destination_is_eligible( (int) $bot_id, (int) $destination_id );
	}

	/**
	 * The exact digest-target destination eligibility rule (§4): belongs to
	 * the given bot, enabled, and not a chat-widget-conversation-created
	 * destination (checked both bot-scoped, ADR-0024's own hygiene method,
	 * and via the unscoped backstop for a cross-bot data inconsistency).
	 *
	 * @param int $bot_id         The candidate bot's primary key.
	 * @param int $destination_id The candidate destination's primary key.
	 *
	 * @return bool
	 */
	public function destination_is_eligible( int $bot_id, int $destination_id ): bool {
		$bot = $this->bots->find( $bot_id );

		if ( null === $bot || BotStatus::ACTIVE !== $bot->status() ) {
			return false;
		}

		$destination = $this->destinations->find( $destination_id );

		if ( null === $destination || ! $destination->enabled() || $destination->bot_id() !== $bot_id ) {
			return false;
		}

		if ( in_array( $destination_id, $this->conversations->destination_ids_for_bot( $bot_id ), true ) ) {
			return false;
		}

		if ( null !== $destination->message_thread_id() && $this->conversations->is_destination_referenced( $destination_id ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Every destination belonging to the given bot that currently passes
	 * the eligibility rule — the exact set the settings-page dropdown (§4)
	 * and any digest-target admin UI must offer, so a conversation-linked
	 * destination is never a selectable choice in the first place.
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return array<int, \UniversalTelegram\Telegram\Configuration\Destination>
	 */
	public function eligible_destinations_for_bot( int $bot_id ): array {
		$conversation_linked = $this->conversations->destination_ids_for_bot( $bot_id );

		return array_values(
			array_filter(
				$this->destinations->for_bot( $bot_id ),
				static function ( $destination ) use ( $conversation_linked ) {
					if ( ! $destination->enabled() ) {
						return false;
					}

					if ( in_array( $destination->id(), $conversation_linked, true ) ) {
						return false;
					}

					return true;
				}
			)
		);
	}

	/**
	 * Whether visitor_digest_enabled is true but the saved target does not
	 * (or no longer) pass the eligibility rule — the specific
	 * misconfiguration state Diagnostics (§7) surfaces as an explicit
	 * warning, distinct from "intentionally disabled".
	 *
	 * @return bool
	 */
	public function paused_for_invalid_target(): bool {
		return $this->enabled() && ! $this->target_valid();
	}

	/**
	 * Combines enabled() and target_valid() into the single gate value.
	 *
	 * @return bool
	 */
	private function compute(): bool {
		return $this->enabled() && $this->target_valid();
	}
}
