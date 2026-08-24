<?php
/**
 * Bot and destination management admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;
use UniversalTelegram\Telegram\Inbound\UpdateRepository;
use UniversalTelegram\Telegram\Outbound\OutboundMessageRepository;

/**
 * Never renders a token or webhook secret — plaintext or ciphertext — under
 * any circumstance (docs/adr/0012, A12). A token can only be replaced,
 * never revealed. Exposes exactly the webhook operation valid for a bot's
 * current state: register() only while no pending secret exists;
 * retry_pending()/rollback() only while one does; rotate() only while none
 * does. An 'uncertain' registration state is always labelled distinctly
 * from 'registered'.
 */
final class BotManagementPage {

	public const SLUG   = 'universal-telegram-bots';
	public const TAB_ID = 'bots';

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository      $bots            Bot profiles.
	 * @param DestinationRepository     $destinations    Destinations.
	 * @param UpdateRepository          $updates         Last-inbound-update-received signal.
	 * @param OutboundMessageRepository $messages       Dead-lettered message inspection.
	 * @param TelegramFormFields        $forms           Shared bot/destination/op form markup.
	 * @param BotSetupWizardState       $wizard_state    Derives the setup wizard's current step.
	 * @param BotSetupWizardRenderer    $wizard_renderer Renders the setup wizard in place of the old static guidance panel.
	 * @param ConversationRepository    $conversations   Identifies conversation-created destinations to exclude from the manual list (M06.3, ADR-0024).
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly UpdateRepository $updates,
		private readonly OutboundMessageRepository $messages,
		private readonly TelegramFormFields $forms,
		private readonly BotSetupWizardState $wizard_state,
		private readonly BotSetupWizardRenderer $wizard_renderer,
		private readonly ConversationRepository $conversations
	) {}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage). Defense in depth: the Hub shell's own capability check
	 * already denies an unauthorized user before this ever runs.
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$this->render_test_message_notice();

		$default_bot    = $this->wizard_state->default_bot();
		$setup_complete = null !== $default_bot && $this->wizard_state->is_complete( $default_bot );

		if ( $this->wizard_view_requested() || ! $setup_complete ) {
			// Wizard-only render: the manual bot list and "Add a bot" form are
			// deliberately not appended here. Showing both at once duplicated
			// the sensitive token-entry form and made multi-bot management
			// unclear (M06.1 wizard-manual-view hotfix).
			$bot_id   = $this->resolve_bot_id();
			$bot_mode = $this->resolve_bot_mode();

			// Bare-URL auto-resume: when the wizard wasn't explicitly
			// requested (this is only the default-view fallback because the
			// default bot's own setup is incomplete) and no bot/mode was
			// explicitly chosen, continue that default bot's own checklist
			// directly rather than the top-level landing choice — preserving
			// the original guided-continuation behaviour for the common
			// single-bot case. An explicit "Setup wizard" click (?view=wizard
			// with nothing else) always shows the landing choice, even when
			// the default bot is already fully configured (M06.1 addendum:
			// new-user guided setup).
			if ( ! $this->wizard_view_requested() && null === $bot_id && null === $bot_mode && null !== $default_bot ) {
				$bot_id = $default_bot->id();
			}

			$selected_bot = null !== $bot_id ? $this->bots->find( $bot_id ) : null;

			$this->wizard_renderer->render( $this->resolve_step( $selected_bot ), $bot_mode, $bot_id );
		} else {
			$this->render_bot_list();
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $this->wizard_url() ),
				esc_html__( 'Setup wizard', 'universal-telegram' )
			);
			$this->render_create_bot_form();
		}

		$this->render_dead_letter_list();
	}

	/**
	 * Renders the bounded synchronous Test Message action's immediate
	 * result, read once from `?test_message_result=` on the redirect
	 * BotManagementController::handle_request() builds. Always one of a
	 * fixed set of non-content codes — never raw Telegram error text, a
	 * token, a secret, or ciphertext (docs/adr/0023 §4).
	 */
	private function render_test_message_notice(): void {
		$result = isset( $_GET['test_message_result'] ) ? sanitize_key( wp_unslash( (string) $_GET['test_message_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $result ) {
			return;
		}

		if ( 'ok' === $result ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Test message sent.', 'universal-telegram' )
			);
			return;
		}

		$messages = array(
			'error_not_found'         => __( 'Test message not sent: bot or destination not found.', 'universal-telegram' ),
			'error_token_unavailable' => __( 'Test message not sent: bot token unavailable.', 'universal-telegram' ),
			'failed_rate_limited'     => __( 'Test message not sent: Telegram is rate-limiting this bot right now. Try again shortly.', 'universal-telegram' ),
			'failed_terminal'         => __( 'Test message not sent: Telegram rejected the destination (e.g. chat not found, or the bot was removed).', 'universal-telegram' ),
			'failed_token_invalid'    => __( 'Test message not sent: the bot token is invalid.', 'universal-telegram' ),
			'failed_retryable'        => __( 'Test message not sent: a temporary network or server error occurred. Try again shortly.', 'universal-telegram' ),
		);

		$message = $messages[ $result ] ?? __( 'Test message not sent.', 'universal-telegram' );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html( $message )
		);
	}

	/**
	 * Whether the wizard view was explicitly requested via `?view=wizard`.
	 * Any other `view` value — or its absence — is treated identically to
	 * "not requested," mirroring HubPage::resolve_tab_id()'s own
	 * unrecognized-input-falls-back-silently convention. Never an error.
	 *
	 * @return bool
	 */
	private function wizard_view_requested(): bool {
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( (string) $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return 'wizard' === $view;
	}

	/**
	 * Resolves the wizard step to display: `?step=` only when it is an
	 * integer in the inclusive range 1-5; any other value (non-numeric,
	 * out of range, or absent) falls back to the derived current step for
	 * the selected bot — never clamped, never an error. Meaningless (and
	 * ignored by the renderer) until a bot is actually selected; defaults
	 * to 1 in that case since there is no bot to derive a current step from.
	 *
	 * @param \UniversalTelegram\Telegram\Configuration\BotProfile|null $selected_bot The bot resolved from `?bot_id=`, if any.
	 *
	 * @return int
	 */
	private function resolve_step( ?\UniversalTelegram\Telegram\Configuration\BotProfile $selected_bot ): int {
		$raw = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $raw && ctype_digit( $raw ) ) {
			$step = (int) $raw;

			if ( $step >= 1 && $step <= 5 ) {
				return $step;
			}
		}

		return null !== $selected_bot ? $this->wizard_state->current_step( $selected_bot ) : 1;
	}

	/**
	 * Resolves step 1's landing-choice mode: `?bot_mode=` only when it is
	 * exactly 'new' or 'existing'; any other value — or its absence — is
	 * treated as no choice made yet (the landing choice itself is shown).
	 * Never an error.
	 *
	 * @return string|null
	 */
	private function resolve_bot_mode(): ?string {
		$raw = isset( $_GET['bot_mode'] ) ? sanitize_key( wp_unslash( (string) $_GET['bot_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return in_array( $raw, array( 'new', 'existing' ), true ) ? $raw : null;
	}

	/**
	 * Resolves which bot the wizard is configuring: `?bot_id=` as a numeric
	 * id (only if it matches an existing bot), or the literal `latest`
	 * (the most recently created bot — used to return into the wizard right
	 * after creating one there). Any other value, a numeric id matching no
	 * bot, or its absence all resolve to null (no bot selected yet — the
	 * wizard's landing choice or bot picker is shown instead). Never an error.
	 *
	 * @return int|null
	 */
	private function resolve_bot_id(): ?int {
		$raw = isset( $_GET['bot_id'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['bot_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $raw ) {
			return null;
		}

		if ( 'latest' === $raw ) {
			$all = $this->bots->all();

			return array() === $all ? null : end( $all )->id();
		}

		if ( ! ctype_digit( $raw ) ) {
			return null;
		}

		$id = (int) $raw;

		return null !== $this->bots->find( $id ) ? $id : null;
	}

	/**
	 * The wizard's own entry URL on this same Bots tab.
	 *
	 * @return string
	 */
	private function wizard_url(): string {
		return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID . '&view=wizard' );
	}

	/**
	 * Renders the list of configured bots.
	 */
	private function render_bot_list(): void {
		foreach ( $this->bots->all() as $bot ) {
			echo '<div class="card" style="max-width:none;">';
			printf( '<h2>%s</h2>', esc_html( $bot->name() ) );

			echo '<table class="widefat striped"><tbody>';
			printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Status', 'universal-telegram' ), esc_html( $bot->status()->value ) );
			printf(
				'<tr><th>%s</th><td>%s</td></tr>',
				esc_html__( 'Webhook registration state', 'universal-telegram' ),
				esc_html( 'uncertain' === $bot->webhook_registration_state() ? __( 'Uncertain — needs attention', 'universal-telegram' ) : $bot->webhook_registration_state() )
			);
			$last_inbound = $this->updates->last_received_at( $bot->id() );
			printf(
				'<tr><th>%s</th><td>%s</td></tr>',
				esc_html__( 'Last inbound update received', 'universal-telegram' ),
				esc_html( null !== $last_inbound ? $last_inbound : __( 'Never', 'universal-telegram' ) )
			);
			echo '</tbody></table>';

			$this->render_bot_actions( $bot );
			$this->render_destinations( $bot );

			echo '</div>';
		}
	}

	/**
	 * Renders one bot's action forms.
	 *
	 * @param \UniversalTelegram\Telegram\Configuration\BotProfile $bot The bot.
	 */
	private function render_bot_actions( \UniversalTelegram\Telegram\Configuration\BotProfile $bot ): void {
		$has_pending = $bot->has_pending_secret();

		echo '<h3>' . esc_html__( 'Actions', 'universal-telegram' ) . '</h3>';

		$this->forms->op_button_form( 'test_connection', array( 'bot_id' => $bot->id() ), __( 'Test connection', 'universal-telegram' ) );

		if ( ! $has_pending ) {
			$this->forms->op_button_form( 'register_webhook', array( 'bot_id' => $bot->id() ), __( 'Register webhook', 'universal-telegram' ) );
			$this->forms->op_button_form( 'rotate_webhook', array( 'bot_id' => $bot->id() ), __( 'Rotate webhook secret', 'universal-telegram' ) );
		} else {
			$this->forms->op_button_form( 'retry_pending_webhook', array( 'bot_id' => $bot->id() ), __( 'Retry pending rotation', 'universal-telegram' ) );
			$this->forms->op_button_form( 'rollback_webhook', array( 'bot_id' => $bot->id() ), __( 'Roll back rotation', 'universal-telegram' ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="replace_token" />';
		echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot->id() ) . '" />';
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		echo '<input type="text" name="token" placeholder="' . esc_attr__( 'New token', 'universal-telegram' ) . '" />';
		submit_button( __( 'Replace token', 'universal-telegram' ), 'secondary', 'submit', false );
		echo '</form>';

		$this->forms->op_button_form( 'delete_bot', array( 'bot_id' => $bot->id() ), __( 'Delete bot', 'universal-telegram' ) );
	}

	/**
	 * Renders one bot's destination list and add-destination form.
	 *
	 * @param \UniversalTelegram\Telegram\Configuration\BotProfile $bot The bot.
	 */
	private function render_destinations( \UniversalTelegram\Telegram\Configuration\BotProfile $bot ): void {
		// System-created conversation topic destinations never appear as
		// ordinary manually configured destinations, and never expose a
		// "Send test message" action — they are surfaced separately,
		// read-only, below (M06.3, ADR-0024).
		$conversation_destination_ids = $this->conversations->destination_ids_for_bot( $bot->id() );

		$manual_destinations       = array();
		$conversation_destinations = array();

		foreach ( $this->destinations->for_bot( $bot->id() ) as $destination ) {
			if ( in_array( $destination->id(), $conversation_destination_ids, true ) ) {
				$conversation_destinations[] = $destination;
			} else {
				$manual_destinations[] = $destination;
			}
		}

		echo '<h3>' . esc_html__( 'Destinations', 'universal-telegram' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Label', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Kind', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Chat ID', 'universal-telegram' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $manual_destinations as $destination ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( $destination->label() ) );
			printf( '<td>%s</td>', esc_html( $destination->kind()->value ) );
			printf( '<td>%s</td>', esc_html( $destination->chat_id() ) );
			echo '<td>';
			$this->forms->op_button_form(
				'send_test_message',
				array(
					'bot_id'         => $bot->id(),
					'destination_id' => $destination->id(),
				),
				__( 'Send test message', 'universal-telegram' )
			);
			$this->forms->op_button_form(
				'delete_destination',
				array(
					'destination_id' => $destination->id(),
				),
				__( 'Delete', 'universal-telegram' )
			);
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		if ( array() !== $conversation_destinations ) {
			echo '<h3>' . esc_html__( 'Conversation topics', 'universal-telegram' ) . '</h3>';
			echo '<p>' . esc_html__( 'Created automatically by the chat widget. Read-only — not ordinary configured destinations.', 'universal-telegram' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr><th>' .
				esc_html__( 'Label', 'universal-telegram' ) . '</th><th>' .
				esc_html__( 'Kind', 'universal-telegram' ) . '</th><th>' .
				esc_html__( 'Chat ID', 'universal-telegram' ) . '</th><th>' .
				esc_html__( 'Conversation', 'universal-telegram' ) . '</th></tr></thead><tbody>';

			foreach ( $conversation_destinations as $destination ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( $destination->label() ) );
				printf( '<td>%s</td>', esc_html( $destination->kind()->value ) );
				printf( '<td>%s</td>', esc_html( $destination->chat_id() ) );
				$owned = $this->conversations->find_by_destination_id( $destination->id() );
				if ( null !== $owned ) {
					printf(
						'<td><a href="%s">%s</a></td>',
						esc_url(
							admin_url(
								'admin.php?page=' . HubPage::SLUG . '&tab=operator-inbox&conversation_id=' . $owned->id()
							)
						),
						esc_html__( 'Open conversation', 'universal-telegram' )
					);
				} else {
					echo '<td></td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$this->forms->create_destination_form( $bot->id() );
	}

	/**
	 * Renders the add-bot form.
	 */
	private function render_create_bot_form(): void {
		echo '<h2>' . esc_html__( 'Add a bot', 'universal-telegram' ) . '</h2>';
		echo '<p>' . esc_html__( 'Adding another bot here does not change your website\'s chat bot — the first bot you configured remains the one connected to the chat widget.', 'universal-telegram' ) . '</p>';
		$this->forms->create_bot_form();
	}

	/**
	 * Renders the dead-lettered message list with Requeue and Dismiss actions.
	 */
	private function render_dead_letter_list(): void {
		$dead_letters = $this->messages->recent_dead_letters( 50 );

		echo '<h2>' . esc_html__( 'Dead-lettered messages', 'universal-telegram' ) . '</h2>';
		echo '<p>' . esc_html__(
			'Requeue retries the same stored message after you fix the bot, destination, or Telegram-side problem. Dismiss removes a message that cannot succeed or that you accept as lost — for example bad message formatting that would fail again unchanged.',
			'universal-telegram'
		) . '</p>';

		if ( array() === $dead_letters ) {
			echo '<p>' . esc_html__( 'No dead-lettered messages.', 'universal-telegram' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'ID', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Reason', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Dead-lettered at', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Actions', 'universal-telegram' ) . '</th></tr></thead><tbody>';

		foreach ( $dead_letters as $message ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( (string) $message->id() ) );
			printf( '<td>%s</td>', esc_html( (string) $message->last_failure_code() ) );
			printf( '<td>%s</td>', esc_html( (string) $message->dead_lettered_at() ) );
			echo '<td>';
			$this->forms->op_button_form( 'requeue_message', array( 'message_id' => $message->id() ), __( 'Requeue', 'universal-telegram' ) );
			$this->forms->op_button_form( 'dismiss_dead_letter', array( 'message_id' => $message->id() ), __( 'Dismiss', 'universal-telegram' ) );
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
