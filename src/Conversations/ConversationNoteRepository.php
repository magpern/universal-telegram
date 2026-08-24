<?php
/**
 * Conversation internal note persistence.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialUnavailableException;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * CRUD for encrypted internal conversation notes (M07, docs/adr/0026),
 * mirroring MessageRepository's own per-row CredentialVault context
 * pattern. Never exposed to Telegram or visitors.
 */
class ConversationNoteRepository {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth    $schema_health Checked before every operation.
	 * @param CredentialVault $vault         Encrypts/decrypts note bodies.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly CredentialVault $vault
	) {}

	/**
	 * The encryption/decryption additional-authenticated-data context for a
	 * given note's primary key, fixed once assigned at creation.
	 *
	 * @param int $conversation_id The owning conversation.
	 * @param int $note_id         The note's own primary key.
	 *
	 * @return string
	 */
	private function context( int $conversation_id, int $note_id ): string {
		return 'conversation_note:' . $conversation_id . ':' . $note_id;
	}

	/**
	 * Creates a note, encrypting the plaintext body immediately. The
	 * plaintext is never retained by this method beyond the encrypt() call.
	 *
	 * @param int    $conversation_id  The owning conversation.
	 * @param int    $operator_user_id The authoring operator.
	 * @param string $plaintext_body   The note text.
	 *
	 * @return ConversationNote|null Null if the schema or key is unavailable, or the write failed.
	 */
	public function create( int $conversation_id, int $operator_user_id, string $plaintext_body ): ?ConversationNote {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		// Insert first with a placeholder ciphertext to obtain the row's own
		// id (the AAD context includes it), then encrypt and update — the
		// row is never readable with plaintext-derived ciphertext in
		// between, since this all happens inside one request before any
		// other caller can read it back.
		$inserted = $wpdb->insert(
			$table,
			array(
				'conversation_id'  => $conversation_id,
				'operator_user_id' => $operator_user_id,
				'body_ciphertext'  => '',
				'created_at'       => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return null;
		}

		$note_id = (int) $wpdb->insert_id;

		try {
			$ciphertext = $this->vault->encrypt( $plaintext_body, $this->context( $conversation_id, $note_id ) );
		} catch ( CredentialUnavailableException $exception ) {
			$wpdb->delete( $table, array( 'id' => $note_id ), array( '%d' ) );
			return null;
		}

		$wpdb->update(
			$table,
			array( 'body_ciphertext' => $ciphertext ),
			array( 'id' => $note_id ),
			array( '%s' ),
			array( '%d' )
		);

		return $this->find( $note_id );
	}

	/**
	 * Finds a note by primary key.
	 *
	 * @param int $id The note's primary key.
	 *
	 * @return ConversationNote|null
	 */
	public function find( int $id ): ?ConversationNote {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $row ? null : $this->hydrate( $row );
	}

	/**
	 * Every note for a conversation, oldest first.
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return array<int, ConversationNote>
	 */
	public function for_conversation( int $conversation_id ): array {
		if ( ! $this->schema_health->is_available() ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE conversation_id = %d ORDER BY created_at ASC", $conversation_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array_map( array( $this, 'hydrate' ), null === $rows ? array() : $rows );
	}

	/**
	 * Decrypts a note's body for the authenticated caller of one request. A
	 * decrypt failure never modifies the stored ciphertext and is reported
	 * here as null, never an exception.
	 *
	 * @param ConversationNote $note The note to decrypt.
	 *
	 * @return string|null
	 */
	public function decrypt( ConversationNote $note ): ?string {
		$result = $this->vault->decrypt( $note->body_ciphertext(), $this->context( $note->conversation_id(), $note->id() ) );

		return CredentialState::AVAILABLE === $result->state() ? $result->plaintext() : null;
	}

	/**
	 * Nulls the `operator_user_id` author reference on every note a given
	 * operator authored — part of the operator-account-deletion cleanup
	 * (ADR-0026 decision 12b2). Note content itself is never touched and
	 * remains subject to the existing conversation retention model; a note
	 * with a null author renders as "— former operator —".
	 *
	 * @param int $operator_user_id The operator whose note authorship is anonymized.
	 *
	 * @return bool
	 */
	public function anonymize_author( int $operator_user_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		$updated = $wpdb->update(
			$table,
			array( 'operator_user_id' => null ),
			array( 'operator_user_id' => $operator_user_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Permanently deletes every note for a conversation — part of the
	 * shared ConversationPurgeService sequence (M07.1).
	 *
	 * @param int $conversation_id The owning conversation.
	 *
	 * @return bool
	 */
	public function delete_for_conversation( int $conversation_id ): bool {
		if ( ! $this->schema_health->is_available() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATION_NOTES_TABLE;

		return false !== $wpdb->delete( $table, array( 'conversation_id' => $conversation_id ), array( '%d' ) );
	}

	/**
	 * Hydrates one database row into a ConversationNote.
	 *
	 * @param array<string, mixed> $row The raw database row.
	 *
	 * @return ConversationNote
	 */
	private function hydrate( array $row ): ConversationNote {
		return new ConversationNote(
			(int) $row['id'],
			(int) $row['conversation_id'],
			null === $row['operator_user_id'] ? null : (int) $row['operator_user_id'],
			(string) $row['body_ciphertext'],
			(string) $row['created_at']
		);
	}
}
