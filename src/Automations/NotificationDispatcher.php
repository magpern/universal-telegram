<?php
/**
 * Idempotent notification dispatch sequence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Automations;

use UniversalTelegram\Events\EventEnvelope;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Queue\DispatchState;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * Called only by RuleEvaluator for a matched rule. Performs, in order
 * (M02 plan §7.5): (1) atomic claim; if skipped_duplicate, stop — no
 * further write of any kind. (2) cooldown check against the rule's most
 * recent handed_off_to_m01 row only. (3) re-validate bot/destination.
 * (4) render the template and call Telegram\Outbound\MessageDispatcher::
 * send() unchanged — no new queue job type, no parallel dispatch path.
 * (5) update to the honest terminal state based on the returned
 * DispatchResult. Has no dependency on Queue\JobEnvelope or Queue\Dispatcher
 * — the falsifiable evidence that M01's opaque-payload rule remains
 * enforced by construction.
 */
final class NotificationDispatcher {

	/**
	 * Constructor.
	 *
	 * @param DispatchLogRepository $dispatch_log       The idempotent dispatch-log state machine.
	 * @param BotProfileRepository  $bots               Re-validated at dispatch time.
	 * @param DestinationRepository $destinations        Re-validated at dispatch time.
	 * @param Registry              $registry            Supplies the event type's allowed template fields.
	 * @param TemplateRenderer      $template_renderer   Renders the rule's own template.
	 * @param MessageDispatcher     $message_dispatcher  M01's own, unchanged outbound transport.
	 */
	public function __construct(
		private readonly DispatchLogRepository $dispatch_log,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly Registry $registry,
		private readonly TemplateRenderer $template_renderer,
		private readonly MessageDispatcher $message_dispatcher
	) {}

	/**
	 * Executes the full dispatch sequence for one matched rule.
	 *
	 * @param \UniversalTelegram\Automations\NotificationRule $rule  The matched rule.
	 * @param EventEnvelope                                   $event The event occurrence.
	 */
	public function dispatch( NotificationRule $rule, EventEnvelope $event ): void {
		$claim = $this->dispatch_log->claim_or_reject( $rule->id(), $event->event_id(), DispatchLogResult::CLAIMED );

		if ( null === $claim || DispatchLogResult::CLAIMED !== $claim ) {
			// Either skipped_duplicate (another row already exists for
			// this pair) or the schema is unavailable — either way,
			// nothing else runs for this (rule, event) pair.
			return;
		}

		if ( $rule->cooldown_seconds() > 0 && $this->within_cooldown( $rule ) ) {
			$this->dispatch_log->update( $rule->id(), $event->event_id(), DispatchLogResult::SKIPPED_COOLDOWN );
			return;
		}

		$bot         = $this->bots->find( $rule->bot_id() );
		$destination = $this->destinations->find( $rule->destination_id() );

		if ( null === $bot || BotStatus::ACTIVE !== $bot->status() || null === $destination || ! $destination->enabled() ) {
			$this->dispatch_log->update( $rule->id(), $event->event_id(), DispatchLogResult::SKIPPED_DISABLED_REFERENCE );
			return;
		}

		$allowed_fields = $this->registry->allowed_variable_fields_for( $event->event_type() );
		$text           = $this->template_renderer->render( $rule->template(), $event, $allowed_fields );

		$result = $this->message_dispatcher->send( $rule->bot_id(), $rule->destination_id(), $text, 'MarkdownV2' );

		if ( null !== $result && DispatchState::SCHEDULED === $result->state() ) {
			// M01's MessageDispatcher::send() does not return the created
			// message's own UUID, so outbound_message_uuid remains
			// unpopulated here; the terminal state itself is the
			// authoritative, honest signal that M01 accepted durable queue
			// ownership.
			$this->dispatch_log->update( $rule->id(), $event->event_id(), DispatchLogResult::HANDED_OFF_TO_M01 );
			return;
		}

		$reason_code = null === $result ? 'schema_unavailable' : $result->state()->name;
		$this->dispatch_log->update( $rule->id(), $event->event_id(), DispatchLogResult::FAILED_BEFORE_HANDOFF, null, $reason_code );
	}

	/**
	 * Whether the rule's cooldown window is currently active, checked
	 * against its own most recent handed_off_to_m01 row only.
	 *
	 * @param NotificationRule $rule The rule.
	 *
	 * @return bool
	 */
	private function within_cooldown( NotificationRule $rule ): bool {
		$last = $this->dispatch_log->most_recent_handoff_at( $rule->id() );

		if ( null === $last ) {
			return false;
		}

		$last_timestamp = strtotime( $last . ' UTC' );

		if ( false === $last_timestamp ) {
			return false;
		}

		return ( time() - $last_timestamp ) < $rule->cooldown_seconds();
	}
}
