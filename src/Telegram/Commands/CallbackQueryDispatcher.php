<?php
/**
 * `/stock` inline-keyboard button-tap authorization and dispatch.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Commands;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceCommandQueryService;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\SupportChatAdapter\Identity\OperatorIdentityMapRepository;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Outbound\MessageDispatcher;

/**
 * The sole entry point {@see \UniversalTelegram\Telegram\Inbound\WebhookController}
 * calls for a `callback_query` update carrying a StockMenu-shaped
 * callback_data. Enforces the identical two-factor authorization
 * BotCommandDispatcher enforces for text commands (a tap is no more
 * trustworthy than typed text just because it happened in the right chat)
 * — a genuinely separate check, not a shared code path, since callback_query
 * and message updates carry the sender id at different JSON locations.
 *
 * Deliberately makes no Telegram API call of its own (no
 * `answerCallbackQuery`): {@see \UniversalTelegram\Telegram\Inbound\WebhookController}'s
 * own contract is bounded, synchronous, low-cost work only — no Telegram
 * API call, no queue dispatch, from the request thread. The tapped
 * button's loading spinner clears via Telegram's own client-side timeout
 * rather than an explicit acknowledgement; the menu reply itself still
 * goes out through the existing queued MessageDispatcher, exactly like
 * every other outbound send.
 */
final class CallbackQueryDispatcher {

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityMapRepository  $operator_identities Resolves the inbound sender's mapped WordPress operator.
	 * @param WooCommerceSupport             $woocommerce_support Governs whether the menu is active.
	 * @param WooCommerceCommandQueryService $woocommerce_queries Bounded, read-only WooCommerce queries.
	 * @param MessageDispatcher              $message_dispatcher  The existing, sole outbound Telegram-send path.
	 * @param DestinationRepository          $destinations        Resolves the reply destination for a bot + chat id.
	 * @param AuditLogger                    $audit                Records rejection entries.
	 */
	public function __construct(
		private readonly OperatorIdentityMapRepository $operator_identities,
		private readonly WooCommerceSupport $woocommerce_support,
		private readonly WooCommerceCommandQueryService $woocommerce_queries,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly DestinationRepository $destinations,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Handles one `callback_query` update.
	 *
	 * @param BotProfile           $bot     The receiving bot, already resolved.
	 * @param string|null          $chat_id The tap's own chat id (the original message's chat).
	 * @param array<string, mixed> $decoded The full decoded update body.
	 */
	public function handle( BotProfile $bot, ?string $chat_id, array $decoded ): void {
		$callback_query = $decoded['callback_query'] ?? null;

		if ( ! is_array( $callback_query ) || ! isset( $callback_query['data'] ) || ! is_string( $callback_query['data'] ) ) {
			return;
		}

		$parsed = StockMenu::parse( $callback_query['data'] );

		if ( null === $parsed ) {
			return;
		}

		$destination_id = $this->resolve_destination_id( $bot->id(), $chat_id );
		$sender_id      = $this->extract_sender_id( $callback_query );

		if ( null === $sender_id ) {
			return;
		}

		$mapped_identity = $this->operator_identities->find_by_telegram_user_id( $sender_id );

		if ( null === $mapped_identity || ! user_can( $mapped_identity->wp_user_id(), CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			$this->audit->record(
				'bot_callback.rejected_unauthorized',
				'system',
				null,
				array( 'bot_id' => $bot->id() ),
				array( 'bot_id' => Classification::INTERNAL ),
				Classification::INTERNAL
			);

			return;
		}

		if ( ! $this->woocommerce_support->is_active() ) {
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::WOOCOMMERCE_INACTIVE );

			return;
		}

		$this->dispatch( $bot->id(), $destination_id, $parsed['action'], $parsed['args'] );
	}

	/**
	 * Dispatches an authorized, recognized StockMenu action.
	 *
	 * @param int                $bot_id         The receiving bot's primary key.
	 * @param int|null           $destination_id Where to send the result.
	 * @param string             $action         One of StockMenu's ACTION_* constants.
	 * @param array<int, string> $args           The action's own positional arguments.
	 */
	private function dispatch( int $bot_id, ?int $destination_id, string $action, array $args ): void {
		switch ( $action ) {
			case StockMenu::ACTION_PRODUCTS:
				$this->reply_products_page( $bot_id, $destination_id, isset( $args[0] ) ? (int) $args[0] : 1 );
				break;
			case StockMenu::ACTION_VARIATIONS:
				if ( ! isset( $args[0] ) ) {
					return;
				}
				$this->reply_variations_page( $bot_id, $destination_id, (int) $args[0], isset( $args[1] ) ? (int) $args[1] : 1 );
				break;
			case StockMenu::ACTION_ITEM:
				if ( ! isset( $args[0] ) ) {
					return;
				}
				$this->reply_item_stock( $bot_id, $destination_id, (int) $args[0] );
				break;
			default:
				// An unrecognized action for a well-formed StockMenu callback_data — silently ignored, same as any other unmapped input.
				break;
		}
	}

	/**
	 * Renders and sends one page of the top-level products menu.
	 *
	 * @param int      $bot_id         The bot's primary key.
	 * @param int|null $destination_id Where to send the menu.
	 * @param int      $page           1-based page number.
	 */
	private function reply_products_page( int $bot_id, ?int $destination_id, int $page ): void {
		$result = $this->woocommerce_queries->list_stock_menu_items( $page );

		if ( array() === $result['items'] ) {
			$this->reply( $bot_id, $destination_id, CommandAcknowledgements::NOT_FOUND );

			return;
		}

		$text = sprintf( 'Products (page %d/%d) — tap one to see its stock:', $page, $result['total_pages'] );

		$this->reply( $bot_id, $destination_id, $text, StockMenu::products_keyboard( $result['items'], $page, $result['total_pages'] ) );
	}

	/**
	 * Renders and sends one page of a variable product's variations menu.
	 *
	 * @param int      $bot_id         The bot's primary key.
	 * @param int|null $destination_id Where to send the menu.
	 * @param int      $parent_id      The variable product's own id.
	 * @param int      $page           1-based page number.
	 */
	private function reply_variations_page( int $bot_id, ?int $destination_id, int $parent_id, int $page ): void {
		$result = $this->woocommerce_queries->list_stock_menu_variations( $parent_id, $page );

		if ( null === $result || array() === $result['items'] ) {
			$this->reply( $bot_id, $destination_id, CommandAcknowledgements::NOT_FOUND );

			return;
		}

		$text = sprintf( '%s (page %d/%d) — tap a variation to see its stock:', $result['parent_name'], $page, $result['total_pages'] );

		$this->reply( $bot_id, $destination_id, $text, StockMenu::variations_keyboard( $parent_id, $result['items'], $page, $result['total_pages'] ) );
	}

	/**
	 * Renders and sends one item's stock summary — the same fixed field
	 * set and wording `/stock <sku>` itself renders.
	 *
	 * @param int      $bot_id         The bot's primary key.
	 * @param int|null $destination_id Where to send the result.
	 * @param int      $item_id        The tapped product/variation id.
	 */
	private function reply_item_stock( int $bot_id, ?int $destination_id, int $item_id ): void {
		$summary = $this->woocommerce_queries->stock_summary_by_id( $item_id );

		if ( null === $summary ) {
			$this->reply( $bot_id, $destination_id, CommandAcknowledgements::NOT_FOUND );

			return;
		}

		$text = sprintf(
			"Product: %s\nStock managed: %s\nQuantity: %s\nStatus: %s",
			$summary['name'],
			$summary['manages_stock'] ? 'yes' : 'no',
			null === $summary['stock_quantity'] ? 'n/a' : (string) $summary['stock_quantity'],
			$summary['stock_status']
		);

		$this->reply( $bot_id, $destination_id, $text );
	}

	/**
	 * The destination row to reply through: the bot's destination whose
	 * chat id matches this update's chat, or the bot's first destination.
	 * Identical logic to BotCommandDispatcher's own (a genuinely separate
	 * copy, not a shared one — see this class's own docblock).
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
	 * Sends one message through the existing outbound pipeline.
	 * Opts into MessageDispatcher's immediate-delivery attempt (M09): a
	 * button-tap reply is genuinely interactive traffic (ADR-0023
	 * amendment's own scope), same as BotCommandDispatcher's own replies.
	 *
	 * @param int                       $bot_id         The bot's primary key.
	 * @param int|null                  $destination_id The destination row to send through.
	 * @param string                    $text           The message text.
	 * @param array<string, mixed>|null $reply_markup    Telegram's own `reply_markup` payload, or null for none.
	 */
	private function reply( int $bot_id, ?int $destination_id, string $text, ?array $reply_markup = null ): void {
		if ( null === $destination_id ) {
			return;
		}

		$this->message_dispatcher->send( $bot_id, $destination_id, $text, null, $reply_markup, true );
	}

	/**
	 * Reads the tapping user's own numeric Telegram user id
	 * (`callback_query.from.id`).
	 *
	 * @param array<string, mixed> $callback_query The decoded `callback_query` object.
	 *
	 * @return int|null
	 */
	private function extract_sender_id( array $callback_query ): ?int {
		if ( ! isset( $callback_query['from']['id'] ) || ! is_int( $callback_query['from']['id'] ) ) {
			return null;
		}

		return $callback_query['from']['id'];
	}
}
