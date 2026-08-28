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
			$this->reply( $bot->id(), $destination_id, CommandAcknowledgements::MALFORMED );

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
	 * Sends one acknowledgement through the existing outbound pipeline.
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
