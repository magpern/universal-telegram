<?php
/**
 * Conversation-detail AI draft review panel.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\AI;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\AI\Draft\DraftRequestHandler;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\Conversation;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * The "Request AI draft" control and per-conversation draft history,
 * composed into ConversationDetailPage's render (docs/adr/0028 decision
 * 6) — one of exactly two `Administration\AI\*` classes permitted to
 * reference AiDraftRepository, and the only one permitted to write
 * reviewed/approved/discarded (never queued/generating/generated, which
 * only the AI\Draft\* worker/request classes ever write). Every draft is
 * rendered with a fixed "NOT SENT" banner; approving a draft is an
 * audit-trail action only and triggers no send of any kind.
 *
 * Not declared final: tests override redirect_and_exit(), matching
 * SettingsPage's exact existing precedent.
 */
class ConversationDraftPanel {

	public const ADMIN_POST_ACTION = 'universal_telegram_ai_draft_review';
	public const NONCE_ACTION      = 'universal_telegram_ai_draft_review';

	/**
	 * Constructor.
	 *
	 * @param AiDraftRepository    $drafts          Draft persistence — read, and review-status writes only.
	 * @param AIProviderRepository $provider_config Reads enablement, for the "Request draft" button's visibility.
	 */
	public function __construct(
		private readonly AiDraftRepository $drafts,
		private readonly AIProviderRepository $provider_config
	) {}

	/**
	 * The admin-post handler for reviewed/approve/discard actions.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$draft_id        = isset( $_POST['draft_id'] ) ? (int) $_POST['draft_id'] : 0;
		$conversation_id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
		$op              = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';
		$operator_id     = get_current_user_id();

		switch ( $op ) {
			case 'mark_reviewed':
				$this->drafts->mark_reviewed( $draft_id, $operator_id );
				break;
			case 'approve':
				$this->drafts->mark_approved( $draft_id, $operator_id );
				break;
			case 'discard':
				$this->drafts->mark_discarded( $draft_id, $operator_id );
				break;
		}

		$this->redirect_and_exit(
			admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=conversations&conversation_id=' . $conversation_id )
		);
	}

	/**
	 * Renders the "Request AI draft" control and this conversation's own
	 * draft history.
	 *
	 * @param Conversation $conversation The conversation being viewed.
	 */
	public function render( Conversation $conversation ): void {
		$config = $this->provider_config->get();

		echo '<h2>' . esc_html__( 'AI Draft Assistant', 'universal-telegram' ) . '</h2>';

		if ( null === $config || ! $config->is_ready() ) {
			echo '<p>' . esc_html__( 'AI drafting is not currently enabled.', 'universal-telegram' ) . '</p>';
			return;
		}

		if ( ! $conversation->is_ai_draft_eligible( $config->ack_policy_version() ) ) {
			echo '<p>' . esc_html__( 'This visitor did not acknowledge AI processing, so no draft can be requested for this conversation.', 'universal-telegram' ) . '</p>';
			return;
		}

		$active   = $this->drafts->find_active_for_conversation( $conversation->id() );
		$retained = $this->drafts->find_retained_for_conversation( $conversation->id() );

		if ( null === $active && null === $retained ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( DraftRequestHandler::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( DraftRequestHandler::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="conversation_id" value="' . (int) $conversation->id() . '" />';
			submit_button( __( 'Request AI Draft', 'universal-telegram' ), 'secondary', 'submit', false );
			echo '</form>';
		} elseif ( null !== $active ) {
			printf( '<p>%s</p>', esc_html__( 'A draft is currently being prepared…', 'universal-telegram' ) );
		} else {
			printf( '<p>%s</p>', esc_html__( 'Discard the current draft below to request a new one.', 'universal-telegram' ) );
		}

		$this->render_history( $conversation->id() );
	}

	/**
	 * Renders this conversation's draft history, most recent first.
	 *
	 * @param int $conversation_id The conversation being viewed.
	 */
	private function render_history( int $conversation_id ): void {
		$drafts = $this->drafts->list_for_conversation( $conversation_id );

		if ( array() === $drafts ) {
			return;
		}

		echo '<h3>' . esc_html__( 'Draft History', 'universal-telegram' ) . '</h3>';

		foreach ( $drafts as $draft ) {
			echo '<div class="ut-ai-draft">';
			printf( '<p><strong>%s</strong> — %s</p>', esc_html( $draft->status() ), esc_html( $draft->created_at() ) );

			if ( 'generated' === $draft->status() || 'reviewed' === $draft->status() || 'approved' === $draft->status() ) {
				echo '<p><strong>' . esc_html__( 'AI-generated draft — NOT SENT. Review, edit, and send manually via Telegram.', 'universal-telegram' ) . '</strong></p>';

				$decrypted = $this->drafts->decrypt_body( $draft );
				echo '<blockquote>' . esc_html( (string) ( null !== $decrypted ? $decrypted->plaintext() : __( '(unavailable)', 'universal-telegram' ) ) ) . '</blockquote>';

				$this->render_review_form( $draft->id(), $conversation_id, 'mark_reviewed', __( 'Mark Reviewed', 'universal-telegram' ), 'generated' === $draft->status() );
				$this->render_review_form( $draft->id(), $conversation_id, 'approve', __( 'Approve', 'universal-telegram' ), true );
				$this->render_review_form( $draft->id(), $conversation_id, 'discard', __( 'Discard', 'universal-telegram' ), true );
			} elseif ( 'failed' === $draft->status() ) {
				printf( '<p>%s: %s</p>', esc_html__( 'Failure reason', 'universal-telegram' ), esc_html( (string) $draft->failure_class() ) );
			}

			echo '</div>';
		}
	}

	/**
	 * Renders one review-action form.
	 *
	 * @param int    $draft_id        Primary key.
	 * @param int    $conversation_id The owning conversation.
	 * @param string $op              The op value this form submits.
	 * @param string $label           The visible button label.
	 * @param bool   $show            Whether to render this form at all.
	 */
	private function render_review_form( int $draft_id, int $conversation_id, string $op, string $label, bool $show ): void {
		if ( ! $show ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:4px;">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="draft_id" value="' . (int) $draft_id . '" />';
		echo '<input type="hidden" name="conversation_id" value="' . (int) $conversation_id . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Redirects and terminates the request. Overridden by tests.
	 *
	 * @param string $url The destination URL.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
