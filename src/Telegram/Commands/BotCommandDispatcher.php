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
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\OperatorIdentity;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;
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
	 * @param OperatorIdentityRepository $operator_identities Resolves the inbound sender's mapped WordPress operator.
	 * @param ConversationRepository     $conversations       Resolves conversation-topic context and (later work packages) lifecycle writes.
	 * @param ChatProfileResolver        $chat_profiles       Resolves the bot's configured support chat/destination.
	 * @param MessageDispatcher          $message_dispatcher  The existing, sole outbound Telegram-send path.
	 * @param AuditLogger                $audit               Records rejection and (later work packages) success entries.
	 */
	public function __construct(
		private readonly OperatorIdentityRepository $operator_identities,
		private readonly ConversationRepository $conversations,
		private readonly ChatProfileResolver $chat_profiles,
		private readonly MessageDispatcher $message_dispatcher,
		private readonly AuditLogger $audit
	) {}

	/**
	 * Handles one recognized command. Called by WebhookController only
	 * after the existing ADR-0013 authenticity/dedup gates, and only in
	 * place of, never in addition to, maybe_route_to_conversation()'s
	 * existing reply-capture path.
	 *
	 * @param BotProfile           $bot               The receiving bot, already resolved.
	 * @param string|null          $chat_id           The update's chat id, metadata already extracted.
	 * @param int|null             $message_thread_id The update's forum topic id, metadata already extracted.
	 * @param ParsedCommand        $parsed            The recognized command.
	 * @param array<string, mixed> $decoded           The full decoded update body (used only for sender-id extraction).
	 */
	public function handle( BotProfile $bot, ?string $chat_id, ?int $message_thread_id, ParsedCommand $parsed, array $decoded ): void {
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

		list( $context, $conversation ) = $this->resolve_context( $bot->id(), $message_thread_id );

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

		$this->execute( $parsed, $bot, $conversation, $mapped_identity, $destination_id );
	}

	/**
	 * Dispatches an authorized, correct-context, well-formed command to its
	 * own handler. Populated incrementally by later work packages
	 * (WP3-WP6); a command reaching this method with no case below is a
	 * defensive no-op, never reachable once every work package lands.
	 *
	 * @param ParsedCommand    $parsed          The recognized command.
	 * @param BotProfile       $bot             The receiving bot.
	 * @param Conversation|null $conversation   The resolved conversation, when in conversation-topic context.
	 * @param OperatorIdentity $mapped_identity The authorized caller's operator identity.
	 * @param int|null         $destination_id  Where to send the acknowledgement.
	 */
	private function execute( ParsedCommand $parsed, BotProfile $bot, ?Conversation $conversation, OperatorIdentity $mapped_identity, ?int $destination_id ): void {
		// Intentionally empty in WP2 — every command's real handler is
		// added by WP3 (help/whoami/conversations), WP4 (status/errors/
		// visitors), WP5 (orders/order/stock/sales), and WP6 (here/
		// presence/claim/release/resolve/reopen/confirm).
		unset( $parsed, $bot, $conversation, $mapped_identity, $destination_id );
	}

	/**
	 * Resolves whether the update arrived in the General topic (no
	 * message_thread_id), a known conversation topic, or an unrecognized
	 * one.
	 *
	 * @param int      $bot_id            The bot's primary key.
	 * @param int|null $message_thread_id The update's forum topic id.
	 *
	 * @return array{0: string, 1: Conversation|null}
	 */
	private function resolve_context( int $bot_id, ?int $message_thread_id ): array {
		if ( null === $message_thread_id ) {
			return array( self::CONTEXT_GENERAL, null );
		}

		$conversation = $this->conversations->find_by_topic( $bot_id, $message_thread_id );

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
