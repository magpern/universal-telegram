<?php
/**
 * Operational-summary AI review panel.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Automations\Intelligence\OperationalSummaryRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiRepository;
use UniversalTelegram\Automations\Intelligence\SummaryAiRequestHandler;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * The "Summarize with AI" control and review/discard UI for the most
 * recent Operational Summary row
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md
 * §2.6/§5), composed into RuleBuilderPage's Intelligence section — one of
 * exactly two classes (alongside SummaryAiRequestHandler,
 * SummaryAiGenerationHandler, SummaryAiLeaseSweep, and SummaryAiRepository
 * itself) permitted to reference SummaryAiRepository, and the only one
 * permitted to write reviewed/discarded. Every draft is rendered in
 * wp-admin only, with a fixed "NOT SENT" banner — no code path here or
 * anywhere in Automations\Intelligence\* ever calls
 * Telegram\Outbound\MessageDispatcher::send() with AI-generated content.
 *
 * Not declared final: tests override redirect_and_exit(), matching
 * Administration\AI\ConversationDraftPanel's exact existing precedent.
 */
class IntelligencePanel {

	public const ADMIN_POST_ACTION = 'universal_telegram_operational_summary_ai_review';
	public const NONCE_ACTION      = 'universal_telegram_operational_summary_ai_review';

	/**
	 * Constructor.
	 *
	 * @param SummaryAiRepository          $drafts          Draft persistence — read, and review-status writes only.
	 * @param OperationalSummaryRepository $summaries       Reads the most recent summary row.
	 * @param AIProviderRepository         $provider_config Reads enablement, for the "Summarize with AI" button's visibility.
	 */
	public function __construct(
		private readonly SummaryAiRepository $drafts,
		private readonly OperationalSummaryRepository $summaries,
		private readonly AIProviderRepository $provider_config
	) {}

	/**
	 * The admin-post handler for the discard action — the only write this
	 * class ever performs beyond the implicit reviewed-on-open transition
	 * in render().
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$draft_id = isset( $_POST['draft_id'] ) ? (int) $_POST['draft_id'] : 0;

		$this->drafts->mark_discarded( $draft_id, get_current_user_id() );

		$this->redirect_and_exit(
			admin_url( 'admin.php?page=' . RuleBuilderPage::SLUG . '&tab=' . RuleBuilderPage::TAB_ID )
		);
	}

	/**
	 * Renders the "Summarize with AI" control and the most recent summary
	 * row's own AI-draft review UI, if any.
	 */
	public function render(): void {
		$row = $this->summaries->most_recent();

		if ( null === $row ) {
			return;
		}

		echo '<h3>' . esc_html__( 'AI-Assisted Summary', 'universal-telegram' ) . '</h3>';

		$config = $this->provider_config->get();

		if ( null === $config || ! $config->is_ready() ) {
			echo '<p class="description">' . esc_html__( 'Enable AI on the AI settings tab to summarize this report.', 'universal-telegram' ) . '</p>';
			return;
		}

		$draft = $this->drafts->find_by_summary_run_id( (int) $row['id'] );

		if ( null === $draft ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( SummaryAiRequestHandler::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( SummaryAiRequestHandler::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="summary_run_id" value="' . esc_attr( (string) $row['id'] ) . '" />';
			submit_button( __( 'Summarize with AI', 'universal-telegram' ) );
			echo '</form>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'Status:', 'universal-telegram' ) . '</strong> ' . esc_html( $draft->status() ) . '</p>';

		if ( 'generated' === $draft->status() ) {
			// Reviewed on operator open — the exact instant this panel
			// renders a generated draft, matching the frozen lifecycle
			// (§3): no separate "mark reviewed" button is needed.
			$this->drafts->mark_reviewed( $draft->id(), get_current_user_id() );
			$draft = $this->drafts->find( $draft->id() ) ?? $draft;
		}

		if ( in_array( $draft->status(), array( 'generated', 'reviewed' ), true ) ) {
			$decrypted = $this->drafts->decrypt_body( $draft );

			echo '<div class="notice notice-warning"><p>' . esc_html__( 'AI-generated summary — NOT SENT. For internal review only; never sent to Telegram or any visitor.', 'universal-telegram' ) . '</p></div>';
			echo '<pre style="white-space:pre-wrap">' . esc_html( null !== $decrypted ? (string) $decrypted->plaintext() : '' ) . '</pre>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( self::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="draft_id" value="' . esc_attr( (string) $draft->id() ) . '" />';
			submit_button( __( 'Discard', 'universal-telegram' ), 'delete' );
			echo '</form>';
		} elseif ( 'failed' === $draft->status() ) {
			echo '<p class="description">' . esc_html__( 'Summary generation failed.', 'universal-telegram' ) . ' ' . esc_html( (string) $draft->failure_class() ) . '</p>';
		} elseif ( 'discarded' === $draft->status() ) {
			echo '<p class="description">' . esc_html__( 'Discarded. A new summary cannot be generated for this report.', 'universal-telegram' ) . '</p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Generation in progress…', 'universal-telegram' ) . '</p>';
		}
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
