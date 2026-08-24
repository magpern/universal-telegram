<?php
/**
 * Operator conversation inbox admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Conversations;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * The operator inbox: active/resolved/archived conversations, status
 * filter, pagination, the current operator's own unread badge, and a
 * self-service availability control (M07, docs/adr/0026).
 * MANAGE_CONVERSATIONS-gated. Delegates to ConversationDetailPage when a
 * `conversation_id` GET parameter is present, mirroring
 * BotManagementPage's own established detail-within-tab pattern.
 * Bulk archive-and-delete permanently is confirmation-gated and reuses
 * the same queued remote-delete path as single-conversation delete
 * (M07.1 follow-up).
 */
final class ConversationInboxPage {

	public const TAB_ID = 'operator-inbox';

	private const PER_PAGE = 20;

	/**
	 * Constructor.
	 *
	 * @param ConversationRepository         $conversations Conversation persistence.
	 * @param OperatorIdentityRepository     $identities    Operator identity mappings.
	 * @param OperatorAvailabilityRepository $availability  Operator availability.
	 * @param ConversationDetailPage         $detail_page   Renders one conversation's detail view.
	 */
	public function __construct(
		private readonly ConversationRepository $conversations,
		private readonly OperatorIdentityRepository $identities,
		private readonly OperatorAvailabilityRepository $availability,
		private readonly ConversationDetailPage $detail_page
	) {}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) && ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$conversation_id = isset( $_GET['conversation_id'] ) ? (int) $_GET['conversation_id'] : 0;

		if ( $conversation_id > 0 ) {
			$this->detail_page->render( $conversation_id );
			return;
		}

		$this->render_notices();
		$this->render_own_availability();
		$this->render_unread_badge();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirm navigation.
		if ( isset( $_GET['bulk_confirm'] ) && '1' === (string) $_GET['bulk_confirm'] ) {
			$this->render_bulk_confirm();
			return;
		}

		$this->render_list();
	}

	/**
	 * Flash notices from action redirects.
	 */
	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash.
		$code = isset( $_GET['ut_notice'] ) ? sanitize_key( wp_unslash( $_GET['ut_notice'] ) ) : '';

		if ( 'bulk_none_selected' === $code ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' .
				esc_html__( 'Select at least one conversation first.', 'universal-telegram' ) .
				'</p></div>';
			return;
		}

		if ( 'bulk_archive_delete' !== $code ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$queued = isset( $_GET['bulk_queued'] ) ? max( 0, (int) $_GET['bulk_queued'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$removed = isset( $_GET['bulk_removed'] ) ? max( 0, (int) $_GET['bulk_removed'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$skipped = isset( $_GET['bulk_skipped'] ) ? max( 0, (int) $_GET['bulk_skipped'] ) : 0;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: queued remote deletes, 2: locally removed, 3: skipped */
					__( 'Bulk cleanup finished. Deletion queued for %1$d conversation(s); %2$d removed locally; %3$d skipped.', 'universal-telegram' ),
					$queued,
					$removed,
					$skipped
				)
			)
		);
	}

	/**
	 * Second-step confirm form for bulk archive + permanent delete.
	 */
	private function render_bulk_confirm(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only confirm navigation.
		$raw = isset( $_GET['ids'] ) ? sanitize_text_field( wp_unslash( $_GET['ids'] ) ) : '';
		$ids = array();

		foreach ( explode( ',', $raw ) as $piece ) {
			$id = (int) $piece;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
			if ( count( $ids ) >= 50 ) {
				break;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		if ( array() === $ids ) {
			echo '<div class="notice notice-warning"><p>' .
				esc_html__( 'No conversations selected.', 'universal-telegram' ) .
				'</p></div>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID ) ) . '">' .
				esc_html__( 'Back to inbox', 'universal-telegram' ) . '</a></p>';
			return;
		}

		$count = count( $ids );

		echo '<div class="notice notice-warning"><p>';
		echo esc_html(
			sprintf(
				/* translators: %d: number of conversations */
				_n(
					'You are about to archive and permanently delete %d conversation.',
					'You are about to archive and permanently delete %d conversations.',
					$count,
					'universal-telegram'
				),
				$count
			)
		);
		echo '</p><p>' . esc_html__(
			'This archives each conversation (visitor access is revoked), queues Telegram topic deletion where the conversation owns an eligible plugin-created topic, then removes conversation data from WordPress. This cannot be undone.',
			'universal-telegram'
		) . '</p></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="bulk_archive_and_delete_permanently" />';
		echo '<input type="hidden" name="confirm" value="1" />';
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="conversation_ids[]" value="' . esc_attr( (string) $id ) . '" />';
		}
		submit_button( __( 'Confirm archive and delete permanently', 'universal-telegram' ), 'delete', '', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID ) ) . '">' .
			esc_html__( 'Cancel', 'universal-telegram' ) . '</a>';
		echo '</form>';
	}

	/**
	 * Renders the current operator's own unread-assigned-conversations
	 * badge, if they are a mapped operator with at least one.
	 */
	private function render_unread_badge(): void {
		$identity = $this->identities->find_by_wp_user_id( get_current_user_id() );

		if ( null === $identity ) {
			return;
		}

		$unread = $this->conversations->unread_assigned_conversations( get_current_user_id() );

		if ( array() === $unread ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of unread assigned conversations */
					_n( 'You have %d unread assigned conversation.', 'You have %d unread assigned conversations.', count( $unread ), 'universal-telegram' ),
					count( $unread )
				)
			)
		);
	}

	/**
	 * Renders the current mapped operator's own availability self-control.
	 */
	private function render_own_availability(): void {
		$identity = $this->identities->find_by_wp_user_id( get_current_user_id() );

		if ( null === $identity ) {
			return;
		}

		$current = $this->availability->find_for_operator( get_current_user_id() );

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="set_availability" />';
		echo '<label>' . esc_html__( 'My availability:', 'universal-telegram' ) . ' <select name="state">';
		foreach ( array( OperatorAvailability::AVAILABLE, OperatorAvailability::BUSY, OperatorAvailability::OFFLINE ) as $state ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $state ),
				null !== $current && $current->state() === $state ? ' selected' : '',
				esc_html( $state )
			);
		}
		echo '</select></label> ';
		submit_button( __( 'Update', 'universal-telegram' ), '', '', false );
		echo '</form>';
	}

	/**
	 * Renders the paginated, filterable conversation list. Bounded,
	 * indexable metadata only — no decrypted-body/name scan, and no
	 * Telegram sender id or username filter (WP9, ADR-0026).
	 */
	private function render_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination/filter.
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status = in_array( $status, ConversationStatus::all(), true ) ? $status : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$uuid_prefix = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bot_id = isset( $_GET['bot_id'] ) && '' !== $_GET['bot_id'] ? (int) $_GET['bot_id'] : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$assigned_operator_id = isset( $_GET['assigned_operator_id'] ) && '' !== $_GET['assigned_operator_id'] ? (int) $_GET['assigned_operator_id'] : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$created_from = isset( $_GET['created_from'] ) ? sanitize_text_field( wp_unslash( $_GET['created_from'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$created_to = isset( $_GET['created_to'] ) ? sanitize_text_field( wp_unslash( $_GET['created_to'] ) ) : '';
		$offset     = ( $page - 1 ) * self::PER_PAGE;

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HubPage::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_ID ) . '" />';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'universal-telegram' ) . '</option>';
		foreach ( ConversationStatus::all() as $option ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), $option === $status ? ' selected' : '', esc_html( $option ) );
		}
		echo '</select> ';
		echo '<input type="text" name="q" value="' . esc_attr( $uuid_prefix ) . '" placeholder="' . esc_attr__( 'Conversation id starts with…', 'universal-telegram' ) . '" /> ';
		echo '<input type="number" name="bot_id" value="' . esc_attr( null === $bot_id ? '' : (string) $bot_id ) . '" placeholder="' . esc_attr__( 'Bot id', 'universal-telegram' ) . '" /> ';
		echo '<input type="number" name="assigned_operator_id" value="' . esc_attr( null === $assigned_operator_id ? '' : (string) $assigned_operator_id ) . '" placeholder="' . esc_attr__( 'Assigned operator id', 'universal-telegram' ) . '" /> ';
		echo '<input type="date" name="created_from" value="' . esc_attr( $created_from ) . '" /> ';
		echo '<input type="date" name="created_to" value="' . esc_attr( $created_to ) . '" /> ';
		submit_button( __( 'Filter', 'universal-telegram' ), '', '', false );
		echo '</form>';

		$conversations = $this->conversations->for_inbox(
			$status,
			self::PER_PAGE,
			$offset,
			'' === $uuid_prefix ? null : $uuid_prefix,
			$bot_id,
			$assigned_operator_id,
			'' === $created_from ? null : $created_from,
			'' === $created_to ? null : $created_to
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( ConversationActionHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( ConversationActionHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="confirm_bulk_archive_and_delete" />';
		echo '<p>';
		submit_button( __( 'Archive and delete permanently…', 'universal-telegram' ), 'delete', '', false );
		echo ' <span class="description">' . esc_html__( 'Selected conversations on this page. One confirmation for the whole batch.', 'universal-telegram' ) . '</span>';
		echo '</p>';

		echo '<table class="widefat striped"><thead><tr><th scope="col" class="check-column"><input type="checkbox" id="ut-bulk-select-all" /></th><th>' .
			esc_html__( 'Conversation', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Status', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Telegram topic', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Assigned to', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Last activity', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $conversations as $conversation ) {
			$assigned_label = '';

			if ( null !== $conversation->assigned_operator_id() ) {
				$assigned_user  = get_userdata( $conversation->assigned_operator_id() );
				$assigned_label = false !== $assigned_user ? $assigned_user->display_name : esc_html__( 'Unknown operator', 'universal-telegram' );
			}

			printf(
				'<tr><th scope="row" class="check-column"><input type="checkbox" class="ut-bulk-conversation" name="conversation_ids[]" value="%d" /></th><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				$conversation->id(),
				esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID . '&conversation_id=' . $conversation->id() ) ),
				esc_html( substr( $conversation->conversation_uuid(), 0, 8 ) ),
				esc_html( $conversation->status() ),
				esc_html( ConversationDetailPage::topic_lifecycle_label( $conversation->topic_lifecycle_state() ) ),
				esc_html( $assigned_label ),
				esc_html( $conversation->updated_at() )
			);
		}

		echo '</tbody></table>';
		echo '</form>';

		echo '<script>document.getElementById("ut-bulk-select-all")?.addEventListener("change",function(e){document.querySelectorAll(".ut-bulk-conversation").forEach(function(cb){cb.checked=e.target.checked;});});</script>';
	}
}
