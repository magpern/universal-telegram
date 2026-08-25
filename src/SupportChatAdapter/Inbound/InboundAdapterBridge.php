<?php
/**
 * Maps inbound Telegram updates on adapter bindings to Support Chat Contract ops.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\Telegram\Commands\CommandParser;
use UniversalTelegram\Telegram\Commands\ParsedCommand;
use UniversalTelegram\Telegram\Configuration\BotProfile;

/**
 * When the adapter is compatible and a binding exists for the topic, handles
 * operator replies and lifecycle commands via Support Chat Contract calls
 * instead of legacy UT conversation SoR writes.
 */
final class InboundAdapterBridge {

	/**
	 * Constructor.
	 *
	 * @param ChannelBindingRepository   $bindings            Binding storage.
	 * @param DiscoveryClient            $discovery           Contract discovery.
	 * @param SupportChatContractClient  $sc_client           SC Contract client.
	 * @param OperatorIdentityRepository $operator_identities Operator map.
	 * @param AuditLogger                $audit               Audit logger.
	 * @param bool                       $adapter_enabled     Settings flag.
	 */
	public function __construct(
		private readonly ChannelBindingRepository $bindings,
		private readonly DiscoveryClient $discovery,
		private readonly SupportChatContractClient $sc_client,
		private readonly OperatorIdentityRepository $operator_identities,
		private readonly AuditLogger $audit,
		private readonly bool $adapter_enabled
	) {}

	/**
	 * Attempts to handle an inbound message update for an adapter binding.
	 *
	 * @param BotProfile           $bot               Receiving bot.
	 * @param string|null          $chat_id           Update chat id.
	 * @param int|null             $message_thread_id Forum topic id.
	 * @param array<string, mixed> $decoded           Full update body.
	 * @param int                  $update_id         Telegram update_id.
	 *
	 * @return bool True when the update was claimed by the adapter path.
	 */
	public function try_handle(
		BotProfile $bot,
		?string $chat_id,
		?int $message_thread_id,
		array $decoded,
		int $update_id
	): bool {
		if ( ! $this->adapter_enabled || null === $message_thread_id ) {
			return false;
		}

		$binding = $this->bindings->find_by_bot_topic( $bot->id(), $message_thread_id );
		if ( null === $binding || ! $binding->is_active() ) {
			return false;
		}

		if ( AdapterAvailability::Compatible !== $this->discovery->resolve( true ) ) {
			return true; // Claimed but fail-closed for channel only.
		}

		if ( null !== $binding->last_ingest_update_id() && $binding->last_ingest_update_id() === $update_id ) {
			return true;
		}

		$sender_id = $this->extract_sender_id( $decoded );
		if ( null === $sender_id ) {
			return true;
		}

		$identity = $this->operator_identities->find_by_telegram_user_id( $sender_id );
		if ( null === $identity ) {
			$this->audit->record(
				'support_chat_adapter.operator_reply.rejected_unmapped_sender',
				'system',
				null,
				array(
					'bot_id'       => $bot->id(),
					'binding_uuid' => $binding->binding_uuid(),
				),
				array(
					'bot_id'       => Classification::INTERNAL,
					'binding_uuid' => Classification::INTERNAL,
				),
				Classification::INTERNAL
			);

			return true;
		}

		$message = isset( $decoded['message'] ) && is_array( $decoded['message'] ) ? $decoded['message'] : null;
		$parsed  = null !== $message ? CommandParser::parse( $message, $bot->telegram_username() ) : null;

		if ( null !== $parsed ) {
			$command = $parsed->command();
			if ( ! in_array( $command, array( 'claim', 'release', 'resolve', 'reopen' ), true ) ) {
				// Non-lifecycle bot commands stay on the existing dispatcher path.
				return false;
			}

			$this->handle_command( $binding->binding_uuid(), $parsed, $identity->wp_user_id(), $update_id );
			$this->bindings->record_ingest_update_id( $binding->binding_uuid(), $update_id, $binding->cas_version() );
			return true;
		}

		$text = $this->extract_text( $decoded );
		if ( null === $text || '' === $text ) {
			return true;
		}

		// Signed and dispatched (or fail-closed per SupportChatContractClient's
		// own gates); result intentionally unused here — Telegram-side
		// bookkeeping below (record_ingest_update_id) proceeds regardless so
		// this inbound update is never redelivered, matching this bridge's
		// existing at-most-once ingest semantics.
		$ingest = $this->sc_client->ingest_operator_reply(
			$binding->binding_uuid(),
			'tg-update-' . $bot->id() . '-' . $update_id,
			$text,
			$identity->wp_user_id(),
			array(
				'telegram_update_id' => $update_id,
			)
		);
		unset( $ingest );

		$this->bindings->record_ingest_update_id( $binding->binding_uuid(), $update_id, $binding->cas_version() );

		return true;
	}

	/**
	 * Dispatches a lifecycle command to Support Chat.
	 *
	 * @param string        $binding_uuid Binding UUID.
	 * @param ParsedCommand $parsed       Parsed command.
	 * @param int           $user_id      Operator WP user id.
	 * @param int           $update_id    Telegram update id.
	 */
	private function handle_command( string $binding_uuid, ParsedCommand $parsed, int $user_id, int $update_id ): void {
		$key     = 'tg-cmd-' . $update_id;
		$command = $parsed->command();

		$result = match ( $command ) {
			'claim'   => $this->sc_client->claim( $binding_uuid, $user_id, $key ),
			'release' => $this->sc_client->release( $binding_uuid, $user_id, $key ),
			'resolve' => $this->sc_client->resolve( $binding_uuid, $user_id, $key ),
			'reopen'  => $this->sc_client->reopen( $binding_uuid, $user_id, $key ),
			default   => null,
		};
		unset( $result );
	}

	/**
	 * Extracts the Telegram sender user id from an update.
	 *
	 * @param array<string, mixed> $decoded Update body.
	 */
	private function extract_sender_id( array $decoded ): ?int {
		$message = $decoded['message'] ?? null;
		if ( ! is_array( $message ) ) {
			return null;
		}

		$from = $message['from'] ?? null;
		if ( ! is_array( $from ) || ! isset( $from['id'] ) || ! is_int( $from['id'] ) ) {
			return null;
		}

		return $from['id'];
	}

	/**
	 * Extracts plaintext message text or caption from an update.
	 *
	 * @param array<string, mixed> $decoded Update body.
	 */
	private function extract_text( array $decoded ): ?string {
		$message = $decoded['message'] ?? null;
		if ( ! is_array( $message ) ) {
			return null;
		}

		if ( isset( $message['text'] ) && is_string( $message['text'] ) ) {
			return $message['text'];
		}

		if ( isset( $message['caption'] ) && is_string( $message['caption'] ) ) {
			return $message['caption'];
		}

		return null;
	}
}
