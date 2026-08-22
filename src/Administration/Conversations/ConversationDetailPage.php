<?php
/**
 * Conversation detail admin view.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Conversations;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * One conversation's detail view: decrypted message history, the mapped
 * operator's WordPress display name for each Telegram-originated reply
 * (never the raw Telegram sender id), internal notes, and — if owned — the
 * visitor's display name (M07, docs/adr/0026). Opening this view as the
 * currently assigned operator marks the conversation seen up to its
 * newest message, per the unread derivation in ConversationRepository.
 * MANAGE_CONVERSATIONS-gated, same as the inbox that links here.
 */
final class ConversationDetailPage {

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository     $conversations Conversation persistence.
	 * @param MessageRepository          $messages      Conversation message persistence.
	 * @param ConversationNoteRepository $notes         Internal note persistence.
	 * @param OperatorIdentityRepository $identities    Operator identity mappings, for reply attribution.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly ConversationNoteRepository $notes,
		private readonly OperatorIdentityRepository $identities
	) {}

	/**
	 * Renders one conversation's detail view.
	 *
	 * @param int $conversation_id The conversation to render.
	 */
	public function render( int $conversation_id ): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) && ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$conversation = $this->conversations->find( $conversation_id );

		if ( null === $conversation ) {
			echo '<p>' . esc_html__( 'Conversation not found.', 'universal-telegram' ) . '</p>';
			return;
		}

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . ConversationInboxPage::TAB_ID ) ) . '">&larr; ' . esc_html__( 'Back to inbox', 'universal-telegram' ) . '</a></p>';

		printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Status', 'universal-telegram' ), esc_html( $conversation->status() ) );

		if ( null !== $conversation->owner_user_id() ) {
			$owner = get_userdata( $conversation->owner_user_id() );
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Visitor', 'universal-telegram' ), esc_html( false !== $owner ? $owner->display_name : __( 'Former account', 'universal-telegram' ) ) );
		} else {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Visitor', 'universal-telegram' ), esc_html__( 'Anonymous', 'universal-telegram' ) );
		}

		$this->render_messages( $conversation );
		$this->render_notes( $conversation );

		$this->maybe_mark_seen( $conversation );
	}

	/**
	 * Renders the decrypted message history, attributing each operator
	 * reply to the mapped WordPress operator's display name — never the
	 * raw Telegram sender id, which this view never reads for display.
	 *
	 * @param \UniversalTelegram\Conversations\Conversation $conversation The conversation.
	 */
	private function render_messages( \UniversalTelegram\Conversations\Conversation $conversation ): void {
		echo '<h2>' . esc_html__( 'Messages', 'universal-telegram' ) . '</h2><ul>';

		foreach ( $this->messages->messages_since( $conversation->id(), 0 ) as $message ) {
			$attribution = 'visitor' === $message->direction() ? __( 'Visitor', 'universal-telegram' ) : $this->attribute_operator( $message );

			printf(
				'<li><strong>%s</strong> (%s): %s</li>',
				esc_html( $attribution ),
				esc_html( $message->created_at() ),
				esc_html( (string) ( $this->messages->decrypt( $message ) ?? __( '(unavailable)', 'universal-telegram' ) ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Resolves an operator-direction message's attribution label: the
	 * mapped WordPress operator's display name if still mapped, "unmapped
	 * sender" if the message predates M07 or the sender was never mapped
	 * (should not occur for a genuinely accepted reply, but rendered
	 * defensively), or "— former operator —" once the mapping was cleared
	 * by account-deletion cleanup (ADR-0026 decision 12b). Never the raw
	 * Telegram sender id itself.
	 *
	 * @param \UniversalTelegram\Conversations\ConversationMessage $message The operator-direction message.
	 *
	 * @return string
	 */
	private function attribute_operator( \UniversalTelegram\Conversations\ConversationMessage $message ): string {
		if ( null === $message->telegram_sender_user_id() ) {
			return __( '— former operator —', 'universal-telegram' );
		}

		$identity = $this->identities->find_by_telegram_user_id( $message->telegram_sender_user_id() );

		if ( null === $identity ) {
			return __( 'Unmapped sender', 'universal-telegram' );
		}

		$user = get_userdata( $identity->wp_user_id() );

		return false !== $user ? $user->display_name : __( 'Unknown operator', 'universal-telegram' );
	}

	/**
	 * Renders internal notes, authorship anonymized to
	 * "— former operator —" once cleared by account-deletion cleanup.
	 *
	 * @param \UniversalTelegram\Conversations\Conversation $conversation The conversation.
	 */
	private function render_notes( \UniversalTelegram\Conversations\Conversation $conversation ): void {
		echo '<h2>' . esc_html__( 'Internal notes', 'universal-telegram' ) . '</h2><ul>';

		foreach ( $this->notes->for_conversation( $conversation->id() ) as $note ) {
			$author_label = __( '— former operator —', 'universal-telegram' );

			if ( null !== $note->operator_user_id() ) {
				$author       = get_userdata( $note->operator_user_id() );
				$author_label = false !== $author ? $author->display_name : __( 'Unknown operator', 'universal-telegram' );
			}

			printf(
				'<li><strong>%s</strong> (%s): %s</li>',
				esc_html( $author_label ),
				esc_html( $note->created_at() ),
				esc_html( (string) ( $this->notes->decrypt( $note ) ?? __( '(unavailable)', 'universal-telegram' ) ) )
			);
		}

		echo '</ul>';
	}

	/**
	 * Marks the conversation seen up to its newest message, only when the
	 * current user is the assigned operator — never for a different
	 * operator or a MANAGE administrator merely viewing it (M07,
	 * docs/adr/0026 decision 5).
	 *
	 * @param \UniversalTelegram\Conversations\Conversation $conversation The conversation.
	 */
	private function maybe_mark_seen( \UniversalTelegram\Conversations\Conversation $conversation ): void {
		if ( get_current_user_id() !== $conversation->assigned_operator_id() ) {
			return;
		}

		$messages = $this->messages->messages_since( $conversation->id(), 0 );

		if ( array() === $messages ) {
			return;
		}

		$newest = end( $messages );
		$this->conversations->mark_seen( $conversation->id(), $newest->id() );
	}
}
