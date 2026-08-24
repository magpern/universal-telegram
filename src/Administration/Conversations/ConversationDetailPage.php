<?php
/**
 * Conversation detail admin view.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Conversations;

use UniversalTelegram\Administration\AI\ConversationDraftPanel;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\MessageRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Conversations\TopicLifecycleState;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Telegram\Client\TelegramTopicError;

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
	 * @param ConversationRepository      $conversations Conversation persistence.
	 * @param MessageRepository           $messages      Conversation message persistence.
	 * @param ConversationNoteRepository  $notes         Internal note persistence.
	 * @param OperatorIdentityRepository  $identities    Operator identity mappings, for reply attribution.
	 * @param ConversationDraftPanel|null $draft_panel  AI draft request/review controls (M09, docs/adr/0028); null in contexts (e.g. some tests) that do not need it.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly MessageRepository $messages,
		private readonly ConversationNoteRepository $notes,
		private readonly OperatorIdentityRepository $identities,
		private readonly ?ConversationDraftPanel $draft_panel = null
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

		$this->render_notice();

		printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Status', 'universal-telegram' ), esc_html( $conversation->status() ) );
		printf(
			'<p><strong>%s:</strong> %s</p>',
			esc_html__( 'Telegram topic', 'universal-telegram' ),
			esc_html( self::topic_lifecycle_label( $conversation->topic_lifecycle_state() ) )
		);

		if ( null !== $conversation->owner_user_id() ) {
			$owner = get_userdata( $conversation->owner_user_id() );
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Visitor', 'universal-telegram' ), esc_html( false !== $owner ? $owner->display_name : __( 'Former account', 'universal-telegram' ) ) );
		} else {
			printf( '<p><strong>%s:</strong> %s</p>', esc_html__( 'Visitor', 'universal-telegram' ), esc_html__( 'Anonymous', 'universal-telegram' ) );
		}

		$this->render_topic_banners( $conversation );
		$this->render_actions( $conversation );
		$this->render_messages( $conversation );
		$this->render_notes( $conversation );
		$this->draft_panel?->render( $conversation );

		$this->maybe_mark_seen( $conversation );
	}

	/**
	 * Operator-facing label for a topic lifecycle state.
	 *
	 * @param string $state TopicLifecycleState value.
	 *
	 * @return string
	 */
	public static function topic_lifecycle_label( string $state ): string {
		return match ( $state ) {
			TopicLifecycleState::ACTIVE => __( 'Healthy', 'universal-telegram' ),
			TopicLifecycleState::UNAVAILABLE => __( 'Missing or closed', 'universal-telegram' ),
			TopicLifecycleState::DELETE_PENDING => __( 'Deletion in progress', 'universal-telegram' ),
			TopicLifecycleState::DELETE_FAILED => __( 'Could not delete topic', 'universal-telegram' ),
			default => __( 'No topic', 'universal-telegram' ),
		};
	}

	/**
	 * Flash notices from action redirects.
	 */
	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash.
		$code = isset( $_GET['ut_notice'] ) ? sanitize_key( wp_unslash( $_GET['ut_notice'] ) ) : '';

		$messages = array(
			'deletion_started'     => __( 'Deletion started.', 'universal-telegram' ),
			'conversation_removed' => __( 'Conversation removed.', 'universal-telegram' ),
			'deletion_failed'      => __( 'The Telegram topic could not be deleted. The conversation was kept.', 'universal-telegram' ),
		);

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$class = 'deletion_failed' === $code ? 'notice-error' : 'notice-success';
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), esc_html( $messages[ $code ] ) );
	}

	/**
	 * Topic lifecycle banners.
	 *
	 * @param Conversation $conversation The conversation.
	 */
	private function render_topic_banners( Conversation $conversation ): void {
		$state = $conversation->topic_lifecycle_state();

		if ( TopicLifecycleState::DELETE_PENDING === $state ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Deletion in progress. The Telegram topic is being removed.', 'universal-telegram' ) . '</p></div>';
			return;
		}

		if ( TopicLifecycleState::UNAVAILABLE === $state ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'This conversation\'s Telegram topic is missing or closed. Archive it, then delete it permanently to clean up.', 'universal-telegram' ) . '</p></div>';
		}

		if ( TopicLifecycleState::DELETE_FAILED === $state ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The Telegram topic could not be deleted. The conversation was kept.', 'universal-telegram' ) . ' ';
			echo esc_html( $this->delete_failed_sentence( $conversation->topic_lifecycle_code() ) );
			echo '</p></div>';
		}
	}

	/**
	 * Operator sentence for a fixed delete-failure code.
	 *
	 * @param string|null $code Fixed lifecycle code.
	 *
	 * @return string
	 */
	private function delete_failed_sentence( ?string $code ): string {
		return match ( $code ) {
			TelegramTopicError::TOPIC_DELETE_FORBIDDEN => __( 'The bot does not have permission to manage topics in this chat. Grant topic rights, then retry Delete permanently.', 'universal-telegram' ),
			TelegramTopicError::TOPIC_DELETE_ATTEMPTS_EXHAUSTED => __( 'Telegram could not delete the topic after several attempts. Retry Delete permanently later.', 'universal-telegram' ),
			TelegramTopicError::TOPIC_DELETE_CHAT_NOT_FOUND => __( 'Telegram reported the chat was not found. Repair bot membership or the chat id, then retry Delete permanently.', 'universal-telegram' ),
			default => '',
		};
	}

	/**
	 * Archive / confirm-delete / reopen controls.
	 *
	 * @param Conversation $conversation The conversation.
	 */
	private function render_actions( Conversation $conversation ): void {
		if ( TopicLifecycleState::DELETE_PENDING === $conversation->topic_lifecycle_state() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirm flag.
		$confirm_delete = isset( $_GET['confirm_delete'] ) && '1' === (string) $_GET['confirm_delete'];

		echo '<h2>' . esc_html__( 'Actions', 'universal-telegram' ) . '</h2>';

		if ( ConversationStatus::ARCHIVED !== $conversation->status()
			&& ConversationStatus::is_valid_transition( $conversation->status(), ConversationStatus::ARCHIVED )
		) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="op" value="archive" />';
			echo '<input type="hidden" name="conversation_id" value="' . esc_attr( (string) $conversation->id() ) . '" />';
			echo '<p>' . esc_html__( 'Archive this conversation. The Telegram topic is not deleted. The visitor can no longer send messages.', 'universal-telegram' ) . '</p>';
			submit_button( __( 'Archive', 'universal-telegram' ), '', '', false );
			echo '</form>';
		}

		if ( ConversationStatus::ARCHIVED === $conversation->status() ) {
			if ( $confirm_delete ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
				echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
				echo '<input type="hidden" name="op" value="delete_permanently" />';
				echo '<input type="hidden" name="confirm" value="1" />';
				echo '<input type="hidden" name="conversation_id" value="' . esc_attr( (string) $conversation->id() ) . '" />';
				echo '<p>' . esc_html__( 'This deletes the Telegram topic created for this conversation, then removes the conversation and its messages from WordPress. This cannot be undone.', 'universal-telegram' ) . '</p>';
				submit_button( __( 'Delete permanently', 'universal-telegram' ), 'delete', '', false );
				echo '</form>';
			} else {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
				echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
				echo '<input type="hidden" name="op" value="confirm_delete_permanently" />';
				echo '<input type="hidden" name="conversation_id" value="' . esc_attr( (string) $conversation->id() ) . '" />';
				submit_button( __( 'Delete permanently…', 'universal-telegram' ), 'delete', '', false );
				echo '</form>';
			}
		}

		if ( ConversationStatus::RESOLVED === $conversation->status() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="op" value="reopen" />';
			echo '<input type="hidden" name="conversation_id" value="' . esc_attr( (string) $conversation->id() ) . '" />';
			submit_button( __( 'Reopen', 'universal-telegram' ), '', '', false );
			echo '</form>';
		}
	}

	/**
	 * Renders the decrypted message history, attributing each operator
	 * reply to the mapped WordPress operator's display name — never the
	 * raw Telegram sender id, which this view never reads for display.
	 *
	 * @param Conversation $conversation The conversation.
	 */
	private function render_messages( Conversation $conversation ): void {
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
