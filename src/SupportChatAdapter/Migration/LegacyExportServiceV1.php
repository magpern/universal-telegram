<?php
/**
 * Narrow, versioned, in-process legacy conversation export boundary.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Migration;

use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Implements Support Chat ADR-0008 §2–§5, pinned and scoped for this
 * repository by ADR-0039. This is a plain PHP class — never a REST route,
 * Ajax handler, cron path, or Contract v1 operation — called in-process by
 * Support Chat's own SC-M03 migration WP-CLI command, which this repository
 * does not implement or register (ADR-0039 §2, §4). It never touches
 * $wpdb directly: every read goes through this plugin's own existing
 * ConversationRepository/MessageRepository/ConversationNoteRepository,
 * which remain the sole authorized decryptors of this plugin's
 * CredentialVault-encrypted columns (ADR-0008 §1).
 *
 * Redaction happens here, at the source: only the fields ADR-0008 §5 lists
 * are ever assembled into the returned shape. A field never emitted here
 * cannot be logged, cached, or migrated by mistake on Support Chat's side.
 */
final class LegacyExportServiceV1 {

	/**
	 * The export shape's own version, independent of both plugins' release
	 * versioning (ADR-0008 §5). A future incompatible change ships as a new
	 * export_batch_v2() method, never a breaking change to this one.
	 */
	public const EXPORT_SCHEMA_VERSION = 1;

	/**
	 * Server-side batch ceiling, enforced regardless of what the caller
	 * requests (ADR-0008 §5) — a batch-size ceiling, not a caller-configurable
	 * trust decision.
	 */
	public const MAX_BATCH_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository     $conversations Owns conversation reads.
	 * @param MessageRepository          $messages      Owns message reads/decryption.
	 * @param ConversationNoteRepository $notes         Owns note reads/decryption.
	 * @param SchemaHealth               $schema_health Checked before every export.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly ConversationNoteRepository $notes,
		private readonly SchemaHealth $schema_health
	) {}

	/**
	 * Exports up to `min( $limit, MAX_BATCH_SIZE )` legacy conversations with
	 * id greater than `$after_source_id`, ordered ascending by id, plus each
	 * conversation's ordered messages and notes with decrypted body
	 * plaintext (ADR-0008 §5). Plaintext exists only as PHP in-memory values
	 * for the duration of this call — never logged, never persisted by this
	 * plugin outside its own existing ciphertext columns (ADR-0008 §3,
	 * ADR-0039 §2).
	 *
	 * A per-conversation read failure (decrypt failure, malformed row) is
	 * returned as a typed error entry within the batch result; it never
	 * throws and never aborts the rest of the batch (ADR-0008 §5). Being
	 * refused entirely — wrong invocation context, or an unavailable schema
	 * — is a different kind of failure and is signalled differently: see
	 * below.
	 *
	 * @param int $after_source_id The highest legacy conversation id already
	 *                              exported by the caller; 0 for the first batch.
	 * @param int $limit           Requested batch size; capped server-side at
	 *                              MAX_BATCH_SIZE regardless of this value.
	 *
	 * @throws LegacyExportContextRejectedException If called outside a WP-CLI
	 *                                                process. This is a hard,
	 *                                                unconditional refusal
	 *                                                (ADR-0008 §4) — never a
	 *                                                silently empty result a
	 *                                                caller could mistake for
	 *                                                "no more data".
	 *
	 * @return array{export_schema_version: int, conversations: array<int, array<string, mixed>>, error?: string}
	 */
	public function export_batch( int $after_source_id, int $limit ): array {
		$this->assert_wp_cli_context();

		if ( ! $this->schema_health->is_available() ) {
			return $this->envelope( array(), 'schema_unavailable' );
		}

		$capped_limit  = max( 0, min( $limit, self::MAX_BATCH_SIZE ) );
		$conversations = $this->conversations->after_id( $after_source_id, $capped_limit );

		$exported = array();
		foreach ( $conversations as $conversation ) {
			$exported[] = $this->export_conversation( $conversation );
		}

		return $this->envelope( $exported, null );
	}

	/**
	 * The real security boundary is operating-system authority to execute
	 * WP-CLI against this install (ADR-0008 §4); this check only closes off
	 * every externally reachable path — web, Ajax, REST, cron. It cannot
	 * and does not claim to distinguish Support Chat's migration command
	 * from any other code already executing inside the same authorized
	 * WP-CLI process.
	 *
	 * @throws LegacyExportContextRejectedException Always, when not in a WP-CLI process.
	 */
	private function assert_wp_cli_context(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			throw new LegacyExportContextRejectedException(
				'LegacyExportServiceV1::export_batch() may only be invoked from a WP-CLI process.'
			);
		}
	}

	/**
	 * Wraps a batch result in the versioned envelope (ADR-0008 §5).
	 *
	 * @param array<int, array<string, mixed>> $conversations The exported/error conversation entries.
	 * @param string|null                      $error         A batch-level typed refusal reason, if any.
	 *
	 * @return array{export_schema_version: int, conversations: array<int, array<string, mixed>>, error?: string}
	 */
	private function envelope( array $conversations, ?string $error ): array {
		$envelope = array(
			'export_schema_version' => self::EXPORT_SCHEMA_VERSION,
			'conversations'         => $conversations,
		);

		if ( null !== $error ) {
			$envelope['error'] = $error;
		}

		return $envelope;
	}

	/**
	 * Exports one conversation's ADR-0008 §5 allow-listed fields plus its
	 * ordered messages and notes, or a typed error entry if any read/decrypt
	 * step fails.
	 *
	 * @param Conversation $conversation The legacy conversation to export.
	 *
	 * @return array<string, mixed>
	 */
	private function export_conversation( Conversation $conversation ): array {
		try {
			$messages = $this->export_messages( $conversation->id() );
			if ( null === $messages ) {
				return $this->conversation_error( $conversation->id(), 'decrypt_failed' );
			}

			$notes = $this->export_notes( $conversation->id() );
			if ( null === $notes ) {
				return $this->conversation_error( $conversation->id(), 'decrypt_failed' );
			}

			return array(
				'id'                            => $conversation->id(),
				'conversation_uuid'             => $conversation->conversation_uuid(),
				'bot_id'                        => $conversation->bot_id(),
				'destination_id'                => $conversation->destination_id(),
				'status'                        => $conversation->status(),
				'assigned_operator_id'          => $conversation->assigned_operator_id(),
				'owner_user_id'                 => $conversation->owner_user_id(),
				'topic_creation_state'          => $conversation->topic_creation_state(),
				'telegram_topic_id'             => $conversation->telegram_topic_id(),
				'topic_lifecycle_state'         => $conversation->topic_lifecycle_state(),
				'start_idempotency_key'         => $conversation->start_idempotency_key(),
				'created_at'                    => $conversation->created_at(),
				'updated_at'                    => $conversation->updated_at(),
				'resolved_at'                   => $conversation->resolved_at(),
				'expires_at'                    => $conversation->expires_at(),
				'assignee_last_seen_message_id' => $conversation->assignee_last_seen_message_id(),
				'messages'                      => $messages,
				'notes'                         => $notes,
			);
		} catch ( \Throwable $exception ) {
			return $this->conversation_error( $conversation->id(), 'export_failed' );
		}
	}

	/**
	 * A conversation's full ordered message list with decrypted plaintext,
	 * or null if any message's ciphertext exists but fails to decrypt. A
	 * message whose body_ciphertext is legitimately null (retention-nulled,
	 * `MessageRepository::null_bodies_for_conversation()`) exports with a
	 * null body — that is not a decrypt failure.
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function export_messages( int $conversation_id ): ?array {
		$exported = array();

		foreach ( $this->messages->messages_since( $conversation_id, 0 ) as $message ) {
			if ( null === $message->body_ciphertext() ) {
				$body = null;
			} else {
				$body = $this->messages->decrypt( $message );
				if ( null === $body ) {
					return null;
				}
			}

			$exported[] = array(
				'id'           => $message->id(),
				'message_uuid' => $message->message_uuid(),
				'direction'    => $message->direction(),
				'body'         => $body,
				'created_at'   => $message->created_at(),
			);
		}

		return $exported;
	}

	/**
	 * A conversation's full ordered internal-note list with decrypted
	 * plaintext, or null if any note fails to decrypt.
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return array<int, array<string, mixed>>|null
	 */
	private function export_notes( int $conversation_id ): ?array {
		$exported = array();

		foreach ( $this->notes->for_conversation( $conversation_id ) as $note ) {
			$body = $this->notes->decrypt( $note );
			if ( null === $body ) {
				return null;
			}

			$exported[] = array(
				'id'               => $note->id(),
				'operator_user_id' => $note->operator_user_id(),
				'body'             => $body,
				'created_at'       => $note->created_at(),
			);
		}

		return $exported;
	}

	/**
	 * A typed per-conversation error entry (ADR-0008 §5) — never a thrown
	 * exception, so the caller's batch continues past it.
	 *
	 * @param int    $id     The legacy conversation id that failed.
	 * @param string $reason A stable, typed failure reason.
	 *
	 * @return array{id: int, error: string}
	 */
	private function conversation_error( int $id, string $reason ): array {
		return array(
			'id'    => $id,
			'error' => $reason,
		);
	}
}
