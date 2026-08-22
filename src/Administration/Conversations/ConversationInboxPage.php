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

		$this->render_own_availability();
		$this->render_unread_badge();
		$this->render_list();
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
	 * Renders the paginated, status-filterable conversation list. Bounded,
	 * indexable metadata only — no decrypted-body/name scan.
	 */
	private function render_list(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination/filter.
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$status = in_array( $status, ConversationStatus::all(), true ) ? $status : null;
		$offset = ( $page - 1 ) * self::PER_PAGE;

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HubPage::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_ID ) . '" />';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'universal-telegram' ) . '</option>';
		foreach ( ConversationStatus::all() as $option ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $option ), $option === $status ? ' selected' : '', esc_html( $option ) );
		}
		echo '</select>';
		submit_button( __( 'Filter', 'universal-telegram' ), '', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Conversation', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Status', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Assigned to', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Last activity', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $this->conversations->for_inbox( $status, self::PER_PAGE, $offset ) as $conversation ) {
			$assigned_label = '';

			if ( null !== $conversation->assigned_operator_id() ) {
				$assigned_user  = get_userdata( $conversation->assigned_operator_id() );
				$assigned_label = false !== $assigned_user ? $assigned_user->display_name : esc_html__( 'Unknown operator', 'universal-telegram' );
			}

			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID . '&conversation_id=' . $conversation->id() ) ),
				esc_html( substr( $conversation->conversation_uuid(), 0, 8 ) ),
				esc_html( $conversation->status() ),
				esc_html( $assigned_label ),
				esc_html( $conversation->updated_at() )
			);
		}

		echo '</tbody></table>';
	}
}
