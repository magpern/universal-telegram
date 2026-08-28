<?php
/**
 * Ensures a Telegram channel case for a Support Chat conversation.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Outbound;

use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\Telegram\Topics\ForumTopicService;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\Destination;
use UniversalTelegram\Telegram\Configuration\DestinationKind;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * Implements Contract ensure_channel_case: create or reuse a forum topic
 * binding. Idempotent on ensure key and conversation UUID.
 */
final class EnsureChannelCaseService {

	/**
	 * Constructor.
	 *
	 * @param ChannelBindingRepository $bindings     Binding storage.
	 * @param BotProfileRepository     $bots         Bot profiles.
	 * @param DestinationRepository    $destinations Destination CRUD.
	 * @param ForumTopicService        $topics       Forum-topic create/delete.
	 */
	public function __construct(
		private readonly ChannelBindingRepository $bindings,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly ForumTopicService $topics
	) {}

	/**
	 * Ensures a channel case.
	 *
	 * @param string               $conversation_uuid Support Chat conversation UUID.
	 * @param string               $idempotency_key   Ensure idempotency key.
	 * @param int                  $bot_id            Configured adapter bot.
	 * @param Destination          $parent_destination Forum/supergroup destination (topic parent).
	 * @param array<string, mixed> $summary_meta      Non-secret summary metadata.
	 *
	 * @return array{channel_case_ref: string, status: string}
	 */
	public function ensure(
		string $conversation_uuid,
		string $idempotency_key,
		int $bot_id,
		Destination $parent_destination,
		array $summary_meta = array()
	): array {
		$by_key = $this->bindings->find_by_ensure_key( $idempotency_key );
		if ( null !== $by_key ) {
			return array(
				'channel_case_ref' => $by_key->support_conversation_uuid(),
				'status'           => 'reused',
			);
		}

		$by_conversation = $this->bindings->find_by_conversation_uuid( $conversation_uuid );
		if ( null !== $by_conversation ) {
			return array(
				'channel_case_ref' => $by_conversation->support_conversation_uuid(),
				'status'           => 'reused',
			);
		}

		$bot = $this->bots->find( $bot_id );
		if ( null === $bot || $parent_destination->bot_id() !== $bot_id ) {
			return array(
				'channel_case_ref' => '',
				'status'           => 'unavailable',
			);
		}

		$topic_name        = $this->topic_name( $conversation_uuid, $summary_meta );
		$telegram_topic_id = $this->topics->create( $bot, $parent_destination->chat_id(), $topic_name );

		if ( null === $telegram_topic_id ) {
			return array(
				'channel_case_ref' => '',
				'status'           => 'unavailable',
			);
		}

		$existing_topic = $this->bindings->find_by_bot_topic( $bot_id, $telegram_topic_id );
		if ( null !== $existing_topic ) {
			return array(
				'channel_case_ref' => $existing_topic->support_conversation_uuid(),
				'status'           => 'reused',
			);
		}

		$destination = $this->destinations->create(
			$bot_id,
			DestinationKind::SUPERGROUP,
			$parent_destination->chat_id(),
			$telegram_topic_id,
			$topic_name
		);

		if ( null === $destination ) {
			$this->topics->try_delete( $bot, $parent_destination->chat_id(), $telegram_topic_id );

			return array(
				'channel_case_ref' => '',
				'status'           => 'unavailable',
			);
		}

		$binding_uuid = wp_generate_uuid4();
		$binding      = $this->bindings->create(
			$binding_uuid,
			$conversation_uuid,
			$idempotency_key,
			$bot_id,
			$destination->id(),
			$telegram_topic_id
		);

		if ( null === $binding ) {
			// Unique race: re-read by conversation or ensure key.
			$race = $this->bindings->find_by_ensure_key( $idempotency_key )
				?? $this->bindings->find_by_conversation_uuid( $conversation_uuid );

			if ( null !== $race ) {
				return array(
					'channel_case_ref' => $race->support_conversation_uuid(),
					'status'           => 'reused',
				);
			}

			return array(
				'channel_case_ref' => '',
				'status'           => 'unavailable',
			);
		}

		return array(
			'channel_case_ref' => $binding->support_conversation_uuid(),
			'status'           => 'created',
		);
	}

	/**
	 * Builds a forum topic title from conversation identity and optional summary.
	 *
	 * @param string               $conversation_uuid Conversation UUID.
	 * @param array<string, mixed> $summary_meta      Non-secret metadata.
	 */
	private function topic_name( string $conversation_uuid, array $summary_meta ): string {
		$label = isset( $summary_meta['title'] ) && is_string( $summary_meta['title'] )
			? trim( $summary_meta['title'] )
			: '';

		$short = substr( $conversation_uuid, 0, 8 );
		if ( '' === $label ) {
			return 'Support ' . $short;
		}

		$trimmed = mb_substr( $label, 0, 100, 'UTF-8' );
		return $trimmed . ' · ' . $short;
	}
}
