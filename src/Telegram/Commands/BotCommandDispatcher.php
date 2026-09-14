<?php
/**
 * Administrative-bot command authorization and dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\EventHistoryRepository;
use UniversalTelegram\Events\EventSource;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Queue\AttemptOutcome;
use UniversalTelegram\Queue\QueueHealth;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMap;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * The sole entry point {@see \UniversalTelegram\Telegram\Inbound\WebhookController}
 * calls for a recognized command (ADR-0027): two-factor authorization
 * (Telegram-operator identity mapping plus a freshly evaluated
 * MANAGE_CONVERSATIONS capability check, both failure causes merged into
 * one non-enumerating outcome), then dispatch to the per-family handler.
 * Every reply is sent through {@see MessageDispatcher} — no second
 * Telegram-send path.
 *
 * Since ADR-0044 (transport/adapter only) the conversation-workflow
 * commands are gone; the surviving commands are the read-only diagnostics
 * (`/status`, `/errors`, `/visitors`) and the bounded WooCommerce queries
 * (`/orders`, `/order`, `/stock`, `/sales`), plus `/help` and `/whoami`.
 */
final class BotCommandDispatcher {

	/**
	 * The current inbound command's own sender, set once at the top of
	 * handle() and read only by reply() for the remainder of that same
	 * call. Safe as request-scoped state: WordPress builds a fresh Plugin
	 * (and therefore a fresh BotCommandDispatcher) per request, and handle()
	 * is never reentrant within one.
	 *
	 * @var int|null
	 */
	private ?int $current_sender_telegram_user_id = null;

	/**
	 * The current inbound command's own receiving bot username, set once at
	 * the top of handle() alongside current_sender_telegram_user_id, for
	 * the same reason: building the "open private chat" link button on a
	 * successful-private-delivery breadcrumb without changing reply()'s own
	 * signature (and therefore every one of the nine existing handle_*
	 * call sites).
	 *
	 * @var string|null
	 */
	private ?string $current_bot_username = null;

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityMapRepository  $operator_identities Resolves the inbound sender's mapped WordPress operator.
	 * @param QueueHealth                    $queue_health        Bounded queue-depth aggregates for `/status` and `/errors`.
	 * @param EventHistoryRepository         $event_history       Bounded 24h activity counts.
	 * @param WooCommerceSupport             $woocommerce_support Governs whether WooCommerce commands are active.
	 * @param WooCommerceCommandQueryService $woocommerce_queries Bounded, read-only WooCommerce queries.
	 * @param MessageDispatcher              $message_dispatcher  The existing, sole outbound Telegram-send path.
	 * @param DestinationRepository          $destinations        Resolves the reply destination for a bot + chat id.
	 * @param AuditLogger                    $audit               Records rejection entries.
	 */
	public function __construct(
		private readonly OperatorIdentityMapRepository $operator_identities,
		private readonly QueueHealth $queue_health,
		private readonly EventHistoryRepository $event_history,
		private readonly WooCommerceSupport $woocommerce_support,
		private readonly WooCommerceCommandQueryService $woocommerce_queries,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly DestinationRepository $destinations,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Handles one recognized command.
	 *
	 * @param BotProfile           $bot               The receiving bot, already resolved.
	 * @param string|null          $chat_id           The update's chat id.
	 * @param int|null             $message_thread_id The update's forum topic id (unused; retained for the caller's stable signature).
	 * @param ParsedCommand        $parsed            The recognized command.
	 * @param array<string, mixed> $decoded           The full decoded update body (used only for sender-id extraction).
	 */
	public function handle( BotProfile $bot, ?string $chat_id, ?int $message_thread_id, ParsedCommand $parsed, array $decoded ): void {
		unset( $message_thread_id );

		$destination_id = $this->resolve_destination_id( $bot->id(), $chat_id );

		$sender_telegram_user_id = $this->extract_sender_id( $decoded );

		if ( null === $sender_telegram_user_id ) {
			return;
		}

		$this->current_sender_telegram_user_id = $sender_telegram_user_id;
		$this->current_bot_username            = $bot->telegram_username();

		$mapped_identity = $this->operator_identities->find_by_telegram_user_id( $sender_telegram_user_id );

		if ( null === $mapped_identity || ! user_can( $mapped_identity->wp_user_id(), CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
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

		if ( ! $parsed->is_argument_valid() ) {
			$this->reply( $bot->id(), $destination_id, self::malformed_acknowledgement_for( $parsed->command() ) );

			return;
		}

		$this->execute( $parsed, $bot, $mapped_identity, $destination_id );
	}

	/**
	 * Dispatches an authorized, well-formed command to its own handler.
	 *
	 * @param ParsedCommand       $parsed          The recognized command.
	 * @param BotProfile          $bot             The receiving bot.
	 * @param OperatorIdentityMap $mapped_identity The authorized caller's operator identity.
	 * @param int|null            $destination_id  Where to send the acknowledgement.
	 */
	private function execute( ParsedCommand $parsed, BotProfile $bot, OperatorIdentityMap $mapped_identity, ?int $destination_id ): void {
		switch ( $parsed->command() ) {
			case 'help':
				$this->handle_help( $bot, $destination_id );
				break;
			case 'whoami':
				$this->handle_whoami( $bot, $destination_id, $mapped_identity );
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
		}
	}

	/**
	 * `/help` — lists every command.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_help( BotProfile $bot, ?int $destination_id ): void {
		$commands = CommandCatalogue::all_commands();
		sort( $commands );

		$lines = array_map(
			static fn ( string $command ): string => '/' . $command,
			$commands
		);

		$this->reply( $bot->id(), $destination_id, "Available commands:\n" . implode( "\n", $lines ) );
	}

	/**
	 * `/whoami` — the caller's own mapped WP display name. Never the raw
	 * Telegram id or username.
	 *
	 * @param BotProfile          $bot             The receiving bot.
	 * @param int|null            $destination_id  Where to send the reply.
	 * @param OperatorIdentityMap $mapped_identity The authorized caller's operator identity.
	 */
	private function handle_whoami( BotProfile $bot, ?int $destination_id, OperatorIdentityMap $mapped_identity ): void {
		$user         = get_userdata( $mapped_identity->wp_user_id() );
		$display_name = false !== $user ? $user->display_name : __( 'Unknown operator', 'universal-telegram' );

		$this->reply( $bot->id(), $destination_id, "You are mapped as: {$display_name}" );
	}

	/**
	 * `/status` — bounded queue-depth and 24h activity aggregates.
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
	 * `/errors` — bounded 24h WordPress-core event count plus queue failed count.
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
	 * `/visitors` — bounded 24h visitor-event count.
	 *
	 * @param BotProfile $bot            The receiving bot.
	 * @param int|null   $destination_id Where to send the reply.
	 */
	private function handle_visitors( BotProfile $bot, ?int $destination_id ): void {
		$text = sprintf( 'Visitor events (24h): %d', $this->event_history->count_24h_by_source( EventSource::VISITOR->value ) );

		$this->reply( $bot->id(), $destination_id, $text );
	}

	/**
	 * `/orders` — the exact trailing-24h order count.
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
	 * `/order <id>` — status, date, currency, total, item count only.
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
	 * `/stock <sku>` — product name, stock-managed state, quantity, status.
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

		if ( '' === $parsed->raw_argument() ) {
			$this->reply_products_menu( $bot->id(), $destination_id, 1 );

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
	 * Renders and sends one page of the `/stock` top-level products menu —
	 * the bare `/stock` command's own entry point. CallbackQueryDispatcher
	 * renders every subsequent Prev/Next/"Back to products" tap itself,
	 * via the same StockMenu builder + WooCommerceCommandQueryService
	 * query this method also uses.
	 *
	 * @param int      $bot_id         The bot's primary key.
	 * @param int|null $destination_id Where to send the menu.
	 * @param int      $page           1-based page number.
	 */
	private function reply_products_menu( int $bot_id, ?int $destination_id, int $page ): void {
		$result = $this->woocommerce_queries->list_stock_menu_items( $page );

		if ( array() === $result['items'] ) {
			$this->reply( $bot_id, $destination_id, CommandAcknowledgements::NOT_FOUND );

			return;
		}

		$text = sprintf( 'Products (page %d/%d) — tap one to see its stock:', $page, $result['total_pages'] );

		$this->reply( $bot_id, $destination_id, $text, StockMenu::products_keyboard( $result['items'], $page, $result['total_pages'] ) );
	}

	/**
	 * `/sales today|week|month` — order count and gross total for the fixed window.
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
	 * The malformed-argument acknowledgement for a command — a per-command
	 * usage hint for the three commands that require a specific argument
	 * shape (CommandCatalogue), falling back to the generic message for
	 * every other (argument-less) command. Still one of
	 * CommandAcknowledgements' fixed strings, never interpolated.
	 *
	 * @param string $command Lowercase command word, no leading slash.
	 *
	 * @return string
	 */
	private static function malformed_acknowledgement_for( string $command ): string {
		switch ( $command ) {
			case 'order':
				return CommandAcknowledgements::MALFORMED_ORDER;
			case 'stock':
				return CommandAcknowledgements::MALFORMED_STOCK;
			case 'sales':
				return CommandAcknowledgements::MALFORMED_SALES;
			default:
				return CommandAcknowledgements::MALFORMED;
		}
	}

	/**
	 * The destination row to reply through: the bot's destination whose
	 * chat id matches this update's chat, or the bot's first destination.
	 * A null result is a silent no-op in {@see reply()}.
	 *
	 * @param int         $bot_id  The bot's primary key.
	 * @param string|null $chat_id The update's chat id.
	 *
	 * @return int|null
	 */
	private function resolve_destination_id( int $bot_id, ?string $chat_id ): ?int {
		$destinations = $this->destinations->for_bot( $bot_id );

		if ( array() === $destinations ) {
			return null;
		}

		if ( null !== $chat_id ) {
			foreach ( $destinations as $destination ) {
				if ( (string) $destination->chat_id() === $chat_id ) {
					return $destination->id();
				}
			}
		}

		return $destinations[0]->id();
	}

	/**
	 * A command's answer belongs to whoever asked, not to everyone in the
	 * chat that happened to be watching -- so every reply is attempted as a
	 * private DM to the requesting operator first (M09), never the shared
	 * group topic by default. Falls back to the group only in two cases:
	 * the private attempt didn't complete (most commonly, the operator has
	 * never opened a DM with the bot -- Telegram refuses a cold DM), where
	 * the group gets a neutral prompt to open one and retry, never the
	 * reply's own content; or there is no sender to reply to at all (this
	 * method's own defensive fallback if ever called before handle() has
	 * set current_sender_telegram_user_id, which should not happen in
	 * practice). A short breadcrumb is posted to the group on a successful
	 * private delivery, so the chat never looks like the bot did nothing --
	 * exactly the confusion an earlier, real deployment surfaced.
	 *
	 * @param int                       $bot_id         The bot's primary key.
	 * @param int|null                  $destination_id The group destination to fall back to (or post a breadcrumb in), if any.
	 * @param string                    $text           One of CommandAcknowledgements' fixed strings, or a StockMenu-rendered body.
	 * @param array<string, mixed>|null $reply_markup    Telegram's own `reply_markup` payload (currently only `inline_keyboard`), or null for none.
	 */
	private function reply( int $bot_id, ?int $destination_id, string $text, ?array $reply_markup = null ): void {
		if ( null === $this->current_sender_telegram_user_id ) {
			if ( null !== $destination_id ) {
				$this->message_dispatcher->send( $bot_id, $destination_id, $text, null, $reply_markup, true );
			}

			return;
		}

		$outcome = $this->message_dispatcher->send_private(
			$bot_id,
			(string) $this->current_sender_telegram_user_id,
			$text,
			$reply_markup
		);

		if ( null === $destination_id ) {
			return;
		}

		if ( AttemptOutcome::DELIVERED === $outcome ) {
			$this->message_dispatcher->send( $bot_id, $destination_id, CommandAcknowledgements::REPLIED_PRIVATELY, null, $this->open_dm_keyboard(), true );

			return;
		}

		$this->message_dispatcher->send( $bot_id, $destination_id, CommandAcknowledgements::DM_REQUIRED, null, $this->open_dm_keyboard(), true );
	}

	/**
	 * A one-button "Open chat" link straight to the bot's own private chat
	 * (a plain `t.me` URL button -- no API call, no special permission),
	 * attached to both the success breadcrumb (skip hunting for the DM)
	 * and the DM-required fallback (the exact chat the operator needs to
	 * open). Null when the bot's own username is unknown (should not
	 * happen for a real Telegram bot, but every bot has one in practice).
	 *
	 * @return array{inline_keyboard: array<int, array<int, array{text:string,url:string}>>}|null
	 */
	private function open_dm_keyboard(): ?array {
		if ( null === $this->current_bot_username ) {
			return null;
		}

		return array(
			'inline_keyboard' => array(
				array(
					array(
						'text' => 'Open chat',
						'url'  => 'https://t.me/' . $this->current_bot_username,
					),
				),
			),
		);
	}

	/**
	 * Reads the inbound sender's own numeric Telegram user id
	 * (`message.from.id`).
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
