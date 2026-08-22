<?php
/**
 * Bot and destination management admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\Administration\Hub\HubPage;
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
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly UpdateRepository $updates,
		private readonly OutboundMessageRepository $messages,
		private readonly TelegramFormFields $forms,
		private readonly BotSetupWizardState $wizard_state,
		private readonly BotSetupWizardRenderer $wizard_renderer
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

		$this->render_bot_list();

		if ( $this->wizard_view_requested() || ! $this->wizard_state->is_complete() ) {
			$this->wizard_renderer->render( $this->resolve_step() );
		} else {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $this->wizard_url() ),
				esc_html__( 'Setup wizard', 'universal-telegram' )
			);
		}

		$this->render_create_bot_form();
		$this->render_dead_letter_list();
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
	 * out of range, or absent) falls back to the derived current step —
	 * never clamped, never an error.
	 *
	 * @return int
	 */
	private function resolve_step(): int {
		$raw = isset( $_GET['step'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' !== $raw && ctype_digit( $raw ) ) {
			$step = (int) $raw;

			if ( $step >= 1 && $step <= 5 ) {
				return $step;
			}
		}

		return $this->wizard_state->current_step();
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
		echo '<h3>' . esc_html__( 'Destinations', 'universal-telegram' ) . '</h3>';
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Label', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Kind', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Chat ID', 'universal-telegram' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $this->destinations->for_bot( $bot->id() ) as $destination ) {
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
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$this->forms->create_destination_form( $bot->id() );
	}

	/**
	 * Renders the add-bot form.
	 */
	private function render_create_bot_form(): void {
		echo '<h2>' . esc_html__( 'Add a bot', 'universal-telegram' ) . '</h2>';
		$this->forms->create_bot_form();
	}

	/**
	 * Renders the dead-lettered message list with a Requeue action per row.
	 */
	private function render_dead_letter_list(): void {
		$dead_letters = $this->messages->recent_dead_letters( 50 );

		echo '<h2>' . esc_html__( 'Dead-lettered messages', 'universal-telegram' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'ID', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Reason', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Dead-lettered at', 'universal-telegram' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $dead_letters as $message ) {
			echo '<tr>';
			printf( '<td>%s</td>', esc_html( (string) $message->id() ) );
			printf( '<td>%s</td>', esc_html( (string) $message->last_failure_code() ) );
			printf( '<td>%s</td>', esc_html( (string) $message->dead_lettered_at() ) );
			echo '<td>';
			$this->forms->op_button_form( 'requeue_message', array( 'message_id' => $message->id() ), __( 'Requeue', 'universal-telegram' ) );
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
