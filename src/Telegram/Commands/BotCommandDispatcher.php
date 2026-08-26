<?php
/**
 * Administrative-bot command authorization, context resolution, and dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\ChatProfileResolver;
use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Conversations\ConversationDisplay;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentity;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Migration\DeferredReplayContext;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * The sole entry point WebhookController calls for a recognized command
 * (M08, ADR-0027): two-factor authorization (Telegram-operator identity
 * mapping plus a freshly evaluated MANAGE_CONVERSATIONS capability check,
 * both failure causes merged into one non-enumerating outcome), context
 * resolution (General topic / a known conversation topic / an unknown
 * topic — the last of which is fully silent for every command), and
 * dispatch to the per-family handler. Every command reply is sent through
 * the existing MessageDispatcher — no second Telegram-send path.
 */
final class BotCommandDispatcher {

	private const CONTEXT_GENERAL      = 'general';
	private const CONTEXT_CONVERSATION = 'conversation';
	private const CONTEXT_UNKNOWN      = 'unknown';

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityRepository     $operator_identities Resolves the inbound sender's mapped WordPress operator.
	 * @param ConversationRepository         $conversations       Resolves conversation-topic context and (later work packages) lifecycle writes.
	 * @param ChatProfileResolver            $chat_profiles       Resolves the bot's configured support chat/destination.
	 * @param OperatorAvailabilityRepository $availability        Resolves an operator's current availability state (for `/whoami`).
	 * @param QueueHealth                    $queue_health        Bounded queue-depth aggregates (for `/status`, `/errors`).
	 * @param EventHistoryRepository         $event_history       Bounded 24h event-count aggregates (for `/status`, `/errors`, `/visitors`).
	 * @param WooCommerceSupport             $woocommerce_support Whether WooCommerce is active (for the Family D WooCommerce-inactive gate).
	 * @param WooCommerceCommandQueryService $woocommerce_queries Bounded, read-only WooCommerce queries (for `/orders`, `/order`, `/stock`, `/sales`).
	 * @param ConfirmationStore              $confirmations       Short-lived confirmation state (for `/resolve`, `/reopen`, `/confirm`).
	 * @param MessageDispatcher              $message_dispatcher  The existing, sole outbound Telegram-send path.
	 * @param AuditLogger                    $audit               Records rejection and success entries.
	 * @param QuiescenceGate|null            $quiescence          Legacy-chat quiescence write-blocking gate (docs/adr/0040). Null only in a not-yet-migrated install.
	 */
	public function __construct(
		private readonly OperatorIdentityRepository $operator_identities,
		private readonly ConversationRepository $conversations,
		private readonly ChatProfileResolver $chat_profiles,
		private readonly OperatorAvailabilityRepository $availability,
		private readonly QueueHealth $queue_health,
		private readonly EventHistoryRepository $event_history,
		private readonly WooCommerceSupport $woocommerce_support,
		private readonly WooCommerceCommandQueryService $woocommerce_queries,
		private readonly ConfirmationStore $confirmations,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly AuditLogger $audit,
		private readonly ?QuiescenceGate $quiescence = null
	) {}

	/**
	 * Handles one recognized command. Called by WebhookController only
	 * after the existing ADR-0013 authenticity/dedup gates, and only in
	 * place of, never in addition to, maybe_route_to_conversation()'s
	 * existing reply-capture path.
	 *
	 * @param BotProfile                 $bot               The receiving bot, already resolved.
	 * @param string|null                $chat_id           The update's chat id, metadata already extracted.
	 * @param int|null                   $message_thread_id The update's forum topic id, metadata already extracted.
	 * @param ParsedCommand              $parsed            The recognized command.
	 * @param array<string, mixed>       $decoded           The full decoded update body (used only for sender-id extraction).
	 * @param DeferredReplayContext|null $replay_context Defense-in-depth quiescence gate (docs/adr/0040 §3): this dispatcher refuses to act outside `idle` on its own, not relying solely on its caller (WebhookController::process_update()) having decided correctly. Non-null only when the internal replayer supplies a context whose token matches the gate's current epoch.
	 */
	public function handle( BotProfile $bot, ?string $chat_id, ?int $message_thread_id, ParsedCommand $parsed, array $decoded, ?DeferredReplayContext $replay_context = null ): void {
		if ( null !== $this->quiescence
			&& ! $this->quiescence->is_idle()
			&& ! $this->quiescence->is_valid_replay_context( $replay_context )
		) {
			// Silent refusal, matching every other gate this method already
			// applies before dispatch — no reply, no state change. Not
			// audited: this is a routine write-blocking refusal, not a
			// security-relevant authorization rejection.
			return;
		}

		$configured_chat_id = $this->chat_profiles->conversation_chat_id( $bot->id() );

		if ( null === $configured_chat_id || $configured_chat_id !== $chat_id ) {
			// Identical silent drop to every other chat-id mismatch in this
			// codebase's inbound webhook handling — no reply, no audit.
			return;
		}

		$sender_telegram_user_id = $this->extract_sender_id( $decoded );

		if ( null === $sender_telegram_user_id ) {
			return;
		}

		$mapped_identity = $this->operator_identities->find_by_telegram_user_id( $sender_telegram_user_id );

		if ( null === $mapped_identity || ! user_can( $mapped_identity->wp_user_id(), CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			// Both failure causes — never mapped, or mapped but the
			// capability has since been revoked — collapse to the
			// identical outcome: no reply, no state change, one merged
			// audit code carrying only bot_id (ADR-0027 decision 2).
			$this->audit->record(
				'bot_command.rejected_unauthorized',
				'system',
				null,
				array( 'bot_id' => $bot->id() ),
				array( 'bot_id' => Classification::INTERNAL ),
				Classification::INTERNAL
			);

			return;
		}

		list( $context, $conversation ) = $this->resolve_context( $bot->id(), $chat_id, $message_thread_id );

		if ( self::CONTEXT_UNKNOWN === $context ) {
			// Fully silent for every command, authorized or not — no
			// acknowledgement is ever sent into a topic this plugin has no
			// record of (ADR-0027 decision 3). Audit-only.
			$this->audit->record(
				'bot_command.rejected_wrong_context',
				'system',
				null,
				array(
					'bot_id'  => $bot->id(),
					'command' => $parsed->command(),
				),
				array(
					'bot_id'  => Classification::INTERNAL,
					'command' => Classification::INTERNAL,
				),
				Classification::INTERNAL
			);

			return;
		}

		$required_context = CommandCatalogue::context_for( $parsed->command() );
		$context_matches  = CommandCatalogue::CONTEXT_ANY === $required_context
			|| ( self::CONTEXT_GENERAL === $context && CommandCatalogue::CONTEXT_GENERAL === $required_context )
			|| ( self::CONTEXT_CONVERSATION === $context && CommandCatalogue::CONTEXT_CONVERSATION === $required_context );

		$destination_id = self::CONTEXT_CONVERSATION === $context && null !== $conversation
			? $conversation->destination_id()
			: $this->chat_profiles->conversation_destination_id( $bot->id() );

		if ( ! $context_matches ) {
			$this->audit->record(
				'bot_command.rejected_wrong_context',
				'system',
				null,
				array(
					'bot_id'  => $bot->id(),
					'command' => $parsed->command(),
				),
				array(
					'bot_id'  => Classification::INTERNAL,
					'command' => Classification::INTERNAL,
				),
				Classification::INTERNAL
			);

			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::WRONG_CONTEXT );

			return;
		}

		if ( ! $parsed->is_argument_valid() ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::MALFORMED );

			return;
		}

		$this->execute( $parsed, $bot, $conversation, $mapped_identity, $destination_id, $context, $replay_context );
	}

	/**
	 * Dispatches an authorized, correct-context, well-formed command to its
	 * own handler — one case per CommandCatalogue literal.
	 *
	 * @param ParsedCommand              $parsed          The recognized command.
	 * @param BotProfile                 $bot             The receiving bot.
	 * @param Conversation|null          $conversation    The resolved conversation, when in conversation-topic context.
	 * @param OperatorIdentity           $mapped_identity The authorized caller's operator identity.
	 * @param int|null                   $destination_id  Where to send the acknowledgement.
	 * @param string                     $context         CONTEXT_GENERAL or CONTEXT_CONVERSATION.
	 * @param DeferredReplayContext|null $replay_context Threaded through from handle(); unused by every case below today, carried only so a future command needing it does not require another signature change.
	 */
	private function execute( ParsedCommand $parsed, BotProfile $bot, ?Conversation $conversation, OperatorIdentity $mapped_identity, ?int $destination_id, string $context, ?DeferredReplayContext $replay_context = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- threaded through per docs/adr/0040 §3 (handle() is execute()'s sole caller); no case below needs it yet, kept so a future command needing it does not require another signature change.
		switch ( $parsed->command() ) {
			case 'help':
				$this->handle_help( $bot, $destination_id, $context );
				break;
			case 'whoami':
				$this->handle_whoami( $bot, $destination_id, $mapped_identity );
				break;
			case 'conversations':
				$this->handle_conversations( $bot, $destination_id );
				break;
			case 'status':
				$this->handle_status( $bot, $destination_id );
				break;
			case 'errors':
				$this->handle_errors( $bot, $destination_id );
				break;
			case 'visitors':
				$this->handle_visitors( $bot, $destination_id );
				break;
			case 'orders':
				$this->handle_orders( $bot, $destination_id );
				break;
			case 'order':
				$this->handle_order( $bot, $destination_id, $parsed );
				break;
			case 'stock':
				$this->handle_stock( $bot, $destination_id, $parsed );
				break;
			case 'sales':
				$this->handle_sales( $bot, $destination_id, $parsed );
				break;
			case 'here':
				$this->handle_here( $bot, $destination_id, $conversation );
				break;
			case 'presence':
				$this->handle_presence( $bot, $destination_id, $parsed, $mapped_identity );
				break;
			case 'claim':
				$this->handle_claim( $bot, $destination_id, $conversation, $mapped_identity );
				break;
			case 'release':
				$this->handle_release( $bot, $destination_id, $conversation, $mapped_identity );
				break;
			case 'resolve':
				$this->handle_resolve( $bot, $destination_id, $conversation, $mapped_identity );
				break;
			case 'reopen':
				$this->handle_reopen( $bot, $destination_id, $conversation, $mapped_identity );
				break;
			case 'confirm':
				$this->handle_confirm( $bot, $destination_id, $conversation, $mapped_identity );
				break;
		}
	}

	/**
	 * `/help` — lists every command valid in the current context.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 * @param string     $context        CONTEXT_GENERAL or CONTEXT_CONVERSATION.
	 */
	private function handle_help( BotProfile $bot, ?int $destination_id, string $context ): void {
		$catalogue_context = self::CONTEXT_GENERAL === $context
			? CommandCatalogue::CONTEXT_GENERAL
			: CommandCatalogue::CONTEXT_CONVERSATION;

		$commands = CommandCatalogue::commands_valid_in( $catalogue_context );
		sort( $commands );

		$lines = array_map(
			static function ( string $command ): string {
				return '/' . $command;
			},
			$commands
		);

		$this->reply( $bot->id(), $destination_id, "Available commands here:\n" . implode( "\n", $lines ) );
	}

	/**
	 * `/whoami` — the caller's own mapped WP display name and current
	 * availability state. Never the raw Telegram id or username.
	 *
	 * @param BotProfile       $bot             The receiving bot.
	 * @param int|null         $destination_id  Where to send the reply.
	 * @param OperatorIdentity $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_whoami( BotProfile $bot, ?int $destination_id, OperatorIdentity $mapped_identity ): void {
		$user         = get_userdata( $mapped_identity->wp_user_id() );
		$display_name = false !== $user ? $user->display_name : __( 'Unknown operator', 'universal-telegram' );

		$state_row = $this->availability->find_for_operator( $mapped_identity->wp_user_id() );
		$state     = null !== $state_row ? $state_row->state() : OperatorAvailability::OFFLINE . ' (never set)';

		$this->reply( $bot->id(), $destination_id, "You are mapped as: {$display_name}\nAvailability: {$state}" );
	}

	/**
	 * `/conversations` — up to 10 currently open conversations, short
	 * reference and status only.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_conversations( BotProfile $bot, ?int $destination_id ): void {
		$open = $this->conversations->for_inbox( ConversationStatus::OPEN, 10, 0, null, $bot->id() );

		if ( array() === $open ) {
			$this->reply( $bot->id(), $destination_id, 'No open conversations.' );

			return;
		}

		$lines = array_map(
			static function ( Conversation $conversation ): string {
				return ConversationDisplay::short_ref( $conversation->conversation_uuid() ) . ' — ' . $conversation->status();
			},
			$open
		);

		$this->reply( $bot->id(), $destination_id, "Open conversations (up to 10):\n" . implode( "\n", $lines ) );
	}

	/**
	 * `/status` — bounded queue-depth and 24h activity aggregates. No
	 * customer, order, or visitor identifier of any kind.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_status( BotProfile $bot, ?int $destination_id ): void {
		$text = sprintf(
			"Queue: %d pending, %d failed, oldest pending %ds\nActivity (24h): WordPress=%d, woocommerce=%d, visitor=%d",
			$this->queue_health->pending_count(),
			$this->queue_health->failed_count(),
			$this->queue_health->oldest_pending_age_seconds(),
			$this->event_history->count_24h_by_source( EventSource::WORDPRESS_CORE->value ),
			$this->event_history->count_24h_by_source( EventSource::WOOCOMMERCE->value ),
			$this->event_history->count_24h_by_source( EventSource::VISITOR->value )
		);

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/errors` — bounded 24h WordPress-core event count (includes
	 * fatal-error-promoted events) plus the current queue failed count.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_errors( BotProfile $bot, ?int $destination_id ): void {
		$text = sprintf(
			"WordPress errors (24h): %d\nQueue failed: %d",
			$this->event_history->count_24h_by_source( EventSource::WORDPRESS_CORE->value ),
			$this->queue_health->failed_count()
		);

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/visitors` — bounded 24h visitor-event count. Fixed 24h window only —
	 * no configurable-window boundary exists (M08 plan §1 Family C).
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_visitors( BotProfile $bot, ?int $destination_id ): void {
		$text = sprintf( 'Visitor events (24h): %d', $this->event_history->count_24h_by_source( EventSource::VISITOR->value ) );

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/orders` — the exact trailing-24h order count (any status), or the
	 * fixed cap-exceeded acknowledgement past 500 matching orders.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_orders( BotProfile $bot, ?int $destination_id ): void {
		if ( ! $this->woocommerce_support->is_active() ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::WOOCOMMERCE_INACTIVE );

			return;
		}

		$count = $this->woocommerce_queries->recent_order_count();

		if ( null === $count ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::TOO_MANY_ORDERS );

			return;
		}

		$this->reply( $bot->id(), $destination_id, sprintf( 'Orders (24h): %d', $count ) );
	}

	/**
	 * `/order <id>` — status, site-timezone date, currency, total, item
	 * count only. Never customer, payment, shipping, coupon, note, or
	 * line-item product-name data.
	 *
	 * @param BotProfile    $bot            The receiving bot.
	 * @param int|null      $destination_id Where to send the reply.
	 * @param ParsedCommand $parsed         Carries the validated numeric order id.
	 */
	private function handle_order( BotProfile $bot, ?int $destination_id, ParsedCommand $parsed ): void {
		if ( ! $this->woocommerce_support->is_active() ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::WOOCOMMERCE_INACTIVE );

			return;
		}

		$summary = $this->woocommerce_queries->order_summary( (int) $parsed->raw_argument() );

		if ( null === $summary ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::NOT_FOUND );

			return;
		}

		$text = sprintf(
			"Status: %s\nDate: %s\nCurrency: %s\nTotal: %s\nItems: %d",
			$summary['status'],
			$summary['date_created'],
			$summary['currency'],
			$summary['total'],
			$summary['item_count']
		);

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/stock <sku>` — product name, stock-managed state, quantity (only
	 * when managed), and stock status only. The submitted SKU is never
	 * echoed back.
	 *
	 * @param BotProfile    $bot            The receiving bot.
	 * @param int|null      $destination_id Where to send the reply.
	 * @param ParsedCommand $parsed         Carries the validated SKU token.
	 */
	private function handle_stock( BotProfile $bot, ?int $destination_id, ParsedCommand $parsed ): void {
		if ( ! $this->woocommerce_support->is_active() ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::WOOCOMMERCE_INACTIVE );

			return;
		}

		$summary = $this->woocommerce_queries->stock_summary( $parsed->raw_argument() );

		if ( null === $summary ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::NOT_FOUND );

			return;
		}

		$text = sprintf(
			"Product: %s\nStock managed: %s\nQuantity: %s\nStatus: %s",
			$summary['name'],
			$summary['manages_stock'] ? 'yes' : 'no',
			null === $summary['stock_quantity'] ? 'n/a' : (string) $summary['stock_quantity'],
			$summary['stock_status']
		);

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/sales today|week|month` — order count and gross total for
	 * completed+processing orders in the fixed window, or the fixed
	 * cap-exceeded acknowledgement past 500 matching orders. No customer
	 * or order identifiers.
	 *
	 * @param BotProfile    $bot            The receiving bot.
	 * @param int|null      $destination_id Where to send the reply.
	 * @param ParsedCommand $parsed         Carries the validated window literal.
	 */
	private function handle_sales( BotProfile $bot, ?int $destination_id, ParsedCommand $parsed ): void {
		if ( ! $this->woocommerce_support->is_active() ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::WOOCOMMERCE_INACTIVE );

			return;
		}

		$summary = $this->woocommerce_queries->sales_summary( $parsed->raw_argument() );

		if ( null === $summary ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::TOO_MANY_ORDERS );

			return;
		}

		$text = sprintf(
			'Sales (%s): %d orders, total %s',
			$parsed->raw_argument(),
			$summary['count'],
			number_format( $summary['gross_total'], 2 )
		);

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/here` — the current conversation's short reference, status, and
	 * assignee's display name. Never a raw Telegram id, internal database
	 * id, or visitor content.
	 *
	 * @param BotProfile        $bot            The receiving bot.
	 * @param int|null          $destination_id Where to send the reply.
	 * @param Conversation|null $conversation   The resolved conversation (never null — CONTEXT_CONVERSATION already matched).
	 */
	private function handle_here( BotProfile $bot, ?int $destination_id, ?Conversation $conversation ): void {
		if ( null === $conversation ) {
			return;
		}

		$assignee_id    = $conversation->assigned_operator_id();
		$assignee_label = 'unassigned';

		if ( null !== $assignee_id ) {
			$user           = get_userdata( $assignee_id );
			$assignee_label = false !== $user ? $user->display_name : __( 'Unknown operator', 'universal-telegram' );
		}

		$text = sprintf(
			"Conversation: %s\nStatus: %s\nAssigned: %s",
			ConversationDisplay::short_ref( $conversation->conversation_uuid() ),
			$conversation->status(),
			$assignee_label
		);

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/presence available|busy|offline` — sets the caller's own
	 * availability. Reuses OperatorAvailabilityRepository::set_state()
	 * exactly as ConversationActionHandler's own self-service path does.
	 *
	 * @param BotProfile       $bot             The receiving bot.
	 * @param int|null         $destination_id  Where to send the reply.
	 * @param ParsedCommand    $parsed          Carries the validated state literal.
	 * @param OperatorIdentity $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_presence( BotProfile $bot, ?int $destination_id, ParsedCommand $parsed, OperatorIdentity $mapped_identity ): void {
		$state       = $parsed->raw_argument();
		$acting_user = $mapped_identity->wp_user_id();

		$this->availability->set_state( $acting_user, $state, $acting_user );

		$this->audit->record(
			'conversation.operator_availability.set',
			'operator',
			$acting_user,
			array(
				'target_user_id' => $acting_user,
				'state'          => $state,
				'source'         => 'telegram_command',
			),
			array(
				'target_user_id' => Classification::INTERNAL,
				'state'          => Classification::INTERNAL,
				'source'         => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->reply( $bot->id(), $destination_id, sprintf( 'Availability set to %s.', $state ) );
	}

	/**
	 * `/claim` — CAS-assigns the conversation to the caller. Rejected
	 * (no override on this surface) if the caller's own availability is
	 * busy or offline, mirroring ConversationActionHandler::assign()'s own
	 * busy-check, minus the MANAGE-only override branch this text-only
	 * surface deliberately omits.
	 *
	 * @param BotProfile        $bot             The receiving bot.
	 * @param int|null          $destination_id  Where to send the reply.
	 * @param Conversation|null $conversation    The resolved conversation.
	 * @param OperatorIdentity  $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_claim( BotProfile $bot, ?int $destination_id, ?Conversation $conversation, OperatorIdentity $mapped_identity ): void {
		if ( null === $conversation ) {
			return;
		}

		$acting_user   = $mapped_identity->wp_user_id();
		$current_state = $this->availability->find_for_operator( $acting_user );

		if ( null !== $current_state && in_array( $current_state->state(), array( OperatorAvailability::BUSY, OperatorAvailability::OFFLINE ), true ) ) {
			$this->reply( $bot->id(), $destination_id, "You're marked busy or offline — send /presence available first." );

			return;
		}

		$succeeded = $this->conversations->assign_with_expected( $conversation->id(), $conversation->assigned_operator_id(), $acting_user );

		if ( ! $succeeded ) {
			// A stale expectation — the assignment changed concurrently.
			// No audit entry, matching ConversationActionHandler's own
			// precedent for a losing CAS attempt.
			$this->reply( $bot->id(), $destination_id, 'Could not claim — the assignment changed. Send /here to check.' );

			return;
		}

		$this->audit->record(
			'conversation.assignment.set',
			'operator',
			$acting_user,
			array(
				'conversation_id'  => $conversation->id(),
				'operator_user_id' => $acting_user,
				'source'           => 'telegram_command',
			),
			array(
				'conversation_id'  => Classification::INTERNAL,
				'operator_user_id' => Classification::INTERNAL,
				'source'           => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->reply( $bot->id(), $destination_id, 'Conversation claimed.' );
	}

	/**
	 * `/release` — CAS-clears assignment. Restricted to the current
	 * assignee only.
	 *
	 * @param BotProfile        $bot             The receiving bot.
	 * @param int|null          $destination_id  Where to send the reply.
	 * @param Conversation|null $conversation    The resolved conversation.
	 * @param OperatorIdentity  $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_release( BotProfile $bot, ?int $destination_id, ?Conversation $conversation, OperatorIdentity $mapped_identity ): void {
		if ( null === $conversation ) {
			return;
		}

		$acting_user = $mapped_identity->wp_user_id();

		if ( $conversation->assigned_operator_id() !== $acting_user ) {
			$this->reply( $bot->id(), $destination_id, 'Only the assigned operator can release this conversation.' );

			return;
		}

		$succeeded = $this->conversations->assign_with_expected( $conversation->id(), $acting_user, null );

		if ( ! $succeeded ) {
			$this->reply( $bot->id(), $destination_id, 'Could not release — the assignment changed. Send /here to check.' );

			return;
		}

		$this->audit->record(
			'conversation.assignment.cleared',
			'operator',
			$acting_user,
			array(
				'conversation_id' => $conversation->id(),
				'source'          => 'telegram_command',
			),
			array(
				'conversation_id' => Classification::INTERNAL,
				'source'          => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->reply( $bot->id(), $destination_id, 'Conversation released.' );
	}

	/**
	 * `/resolve` — requires the current assignee and an eligible status;
	 * never transitions immediately. Requests a confirmation instead
	 * (ADR-0027 decision 5) — the actual `resolved` transition happens
	 * only once `/confirm` is received within 60 seconds.
	 *
	 * @param BotProfile        $bot             The receiving bot.
	 * @param int|null          $destination_id  Where to send the reply.
	 * @param Conversation|null $conversation    The resolved conversation.
	 * @param OperatorIdentity  $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_resolve( BotProfile $bot, ?int $destination_id, ?Conversation $conversation, OperatorIdentity $mapped_identity ): void {
		if ( null === $conversation ) {
			return;
		}

		$acting_user = $mapped_identity->wp_user_id();

		if ( $conversation->assigned_operator_id() !== $acting_user ) {
			$this->reply( $bot->id(), $destination_id, 'Only the assigned operator can resolve this conversation.' );

			return;
		}

		if ( ConversationStatus::RESOLVED === $conversation->status() ) {
			$this->reply( $bot->id(), $destination_id, 'This conversation is already resolved.' );

			return;
		}

		if ( ! $this->is_open_or_waiting( $conversation->status() ) ) {
			$this->reply( $bot->id(), $destination_id, "This conversation can't be resolved from its current state." );

			return;
		}

		$this->confirmations->request( $bot->id(), $conversation->id(), $acting_user, 'resolve' );
		$this->reply( $bot->id(), $destination_id, 'Reply /confirm within 60 seconds to resolve this conversation.' );
	}

	/**
	 * `/reopen` — requires the current assignee (tightened beyond the web
	 * UI's own broader `MANAGE_CONVERSATIONS` reopen — ADR-0027 decision
	 * 6) and status `resolved`. Requests a confirmation instead of
	 * transitioning immediately.
	 *
	 * @param BotProfile        $bot             The receiving bot.
	 * @param int|null          $destination_id  Where to send the reply.
	 * @param Conversation|null $conversation    The resolved conversation.
	 * @param OperatorIdentity  $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_reopen( BotProfile $bot, ?int $destination_id, ?Conversation $conversation, OperatorIdentity $mapped_identity ): void {
		if ( null === $conversation ) {
			return;
		}

		$acting_user = $mapped_identity->wp_user_id();

		// Covers both "wrong operator" and "unassigned" (assigned_operator_id
		// is null, which never equals a real WordPress user id) uniformly —
		// a resolved-and-unassigned conversation can only be reopened via
		// the Hub, by design.
		if ( $conversation->assigned_operator_id() !== $acting_user ) {
			$this->reply( $bot->id(), $destination_id, 'Only the assigned operator can reopen this conversation.' );

			return;
		}

		if ( $this->is_open_or_waiting( $conversation->status() ) ) {
			$this->reply( $bot->id(), $destination_id, 'This conversation is already open.' );

			return;
		}

		if ( ConversationStatus::RESOLVED !== $conversation->status() ) {
			$this->reply( $bot->id(), $destination_id, "This conversation can't be reopened from its current state." );

			return;
		}

		$this->confirmations->request( $bot->id(), $conversation->id(), $acting_user, 'reopen' );
		$this->reply( $bot->id(), $destination_id, 'Reply /confirm within 60 seconds to reopen this conversation.' );
	}

	/**
	 * `/confirm` — consumes the caller's own pending `/resolve` or
	 * `/reopen` (single-use, key-scoped to bot+conversation+operator) and,
	 * only after re-validating the same preconditions fresh (state may
	 * have drifted during the confirmation window), performs the actual
	 * transition via the existing, database-CAS-guarded
	 * ConversationRepository::transition().
	 *
	 * @param BotProfile        $bot             The receiving bot.
	 * @param int|null          $destination_id  Where to send the reply.
	 * @param Conversation|null $conversation    The resolved conversation.
	 * @param OperatorIdentity  $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_confirm( BotProfile $bot, ?int $destination_id, ?Conversation $conversation, OperatorIdentity $mapped_identity ): void {
		if ( null === $conversation ) {
			return;
		}

		$acting_user = $mapped_identity->wp_user_id();
		$pending     = $this->confirmations->consume( $bot->id(), $conversation->id(), $acting_user );

		if ( null === $pending ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::NO_PENDING_CONFIRMATION );

			return;
		}

		// State may have drifted during the up-to-60-second confirmation
		// window (a Hub action, or the retention sweep) — re-fetch and
		// re-validate fresh rather than trusting the conversation object
		// resolved before this exchange started.
		$fresh = $this->conversations->find( $conversation->id() );

		if ( null === $fresh || $fresh->assigned_operator_id() !== $acting_user ) {
			$this->reply( $bot->id(), $destination_id, 'No longer eligible — the assignment changed.' );

			return;
		}

		if ( 'resolve' === $pending ) {
			$this->confirm_resolve( $bot, $destination_id, $fresh, $acting_user );
		} elseif ( 'reopen' === $pending ) {
			$this->confirm_reopen( $bot, $destination_id, $fresh, $acting_user );
		}
	}

	/**
	 * The confirmed `resolve` transition, freshly re-validated.
	 *
	 * @param BotProfile   $bot            The receiving bot.
	 * @param int|null     $destination_id Where to send the reply.
	 * @param Conversation $fresh          The freshly re-fetched conversation.
	 * @param int          $acting_user    The confirming operator's WordPress user id.
	 */
	private function confirm_resolve( BotProfile $bot, ?int $destination_id, Conversation $fresh, int $acting_user ): void {
		if ( ConversationStatus::RESOLVED === $fresh->status() ) {
			$this->reply( $bot->id(), $destination_id, 'This conversation is already resolved.' );

			return;
		}

		if ( ! $this->is_open_or_waiting( $fresh->status() ) ) {
			$this->reply( $bot->id(), $destination_id, 'No longer eligible — the conversation state changed.' );

			return;
		}

		$succeeded = $this->conversations->transition( $fresh->id(), $fresh->status(), ConversationStatus::RESOLVED );

		if ( ! $succeeded ) {
			$this->reply( $bot->id(), $destination_id, 'No longer eligible — the conversation state changed.' );

			return;
		}

		$this->audit->record(
			'conversation.status.resolved',
			'operator',
			$acting_user,
			array(
				'conversation_id' => $fresh->id(),
				'source'          => 'telegram_command',
			),
			array(
				'conversation_id' => Classification::INTERNAL,
				'source'          => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->reply( $bot->id(), $destination_id, 'Conversation resolved.' );
	}

	/**
	 * The confirmed `reopen` transition, freshly re-validated.
	 *
	 * @param BotProfile   $bot            The receiving bot.
	 * @param int|null     $destination_id Where to send the reply.
	 * @param Conversation $fresh          The freshly re-fetched conversation.
	 * @param int          $acting_user    The confirming operator's WordPress user id.
	 */
	private function confirm_reopen( BotProfile $bot, ?int $destination_id, Conversation $fresh, int $acting_user ): void {
		if ( $this->is_open_or_waiting( $fresh->status() ) ) {
			$this->reply( $bot->id(), $destination_id, 'This conversation is already open.' );

			return;
		}

		if ( ConversationStatus::RESOLVED !== $fresh->status() ) {
			$this->reply( $bot->id(), $destination_id, 'No longer eligible — the conversation state changed.' );

			return;
		}

		$succeeded = $this->conversations->transition( $fresh->id(), ConversationStatus::RESOLVED, ConversationStatus::OPEN );

		if ( ! $succeeded ) {
			$this->reply( $bot->id(), $destination_id, 'No longer eligible — the conversation state changed.' );

			return;
		}

		$this->audit->record(
			'conversation.status.reopened',
			'operator',
			$acting_user,
			array(
				'conversation_id' => $fresh->id(),
				'source'          => 'telegram_command',
			),
			array(
				'conversation_id' => Classification::INTERNAL,
				'source'          => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);

		$this->reply( $bot->id(), $destination_id, 'Conversation reopened.' );
	}

	/**
	 * Whether a status is one of the three "open or waiting" states
	 * `/resolve` may transition from, and `/reopen` treats as "already
	 * open".
	 *
	 * @param string $status A ConversationStatus value.
	 *
	 * @return bool
	 */
	private function is_open_or_waiting( string $status ): bool {
		return in_array(
			$status,
			array( ConversationStatus::OPEN, ConversationStatus::WAITING_FOR_VISITOR, ConversationStatus::WAITING_FOR_OPERATOR ),
			true
		);
	}

	/**
	 * Resolves whether the update arrived in the General topic (no
	 * message_thread_id), a known conversation topic, or an unrecognized
	 * one. Topic identity is the exact (bot_id, chat_id, message_thread_id)
	 * tuple — never thread id alone (M07.1, docs/adr/0031).
	 *
	 * @param int         $bot_id            The bot's primary key.
	 * @param string|null $chat_id           The update's chat id.
	 * @param int|null    $message_thread_id The update's forum topic id.
	 *
	 * @return array{0: string, 1: Conversation|null}
	 */
	private function resolve_context( int $bot_id, ?string $chat_id, ?int $message_thread_id ): array {
		if ( null === $message_thread_id ) {
			return array( self::CONTEXT_GENERAL, null );
		}

		if ( null === $chat_id ) {
			return array( self::CONTEXT_UNKNOWN, null );
		}

		$conversation = $this->conversations->find_by_bot_chat_thread( $bot_id, $chat_id, $message_thread_id );

		if ( null === $conversation ) {
			return array( self::CONTEXT_UNKNOWN, null );
		}

		return array( self::CONTEXT_CONVERSATION, $conversation );
	}

	/**
	 * Sends one acknowledgement through the existing outbound pipeline. A
	 * null destination id (no conversation-support destination configured)
	 * is a silent no-op — there is nowhere to deliver it.
	 *
	 * @param int      $bot_id         The bot's primary key.
	 * @param int|null $destination_id The destination row to send through.
	 * @param string   $text           One of CommandAcknowledgements' fixed strings.
	 */
	private function reply( int $bot_id, ?int $destination_id, string $text ): void {
		if ( null === $destination_id ) {
			return;
		}

		$this->message_dispatcher->send( $bot_id, $destination_id, $text );
	}

	/**
	 * Reads the inbound sender's own numeric Telegram user id
	 * (`message.from.id`), mirroring WebhookController's own identical,
	 * private extraction for message-capture routing.
	 *
	 * @param array<string, mixed> $decoded The full decoded update body.
	 *
	 * @return int|null
	 */
	private function extract_sender_id( array $decoded ): ?int {
		if ( ! isset( $decoded['message']['from']['id'] ) || ! is_int( $decoded['message']['from']['id'] ) ) {
			return null;
		}

		return $decoded['message']['from']['id'];
	}
}
