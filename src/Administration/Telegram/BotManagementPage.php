<?php
/**
 * Bot and destination management admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

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

	public const SLUG = 'universal-telegram-bots';

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository      $bots         Bot profiles.
	 * @param DestinationRepository     $destinations Destinations.
	 * @param UpdateRepository          $updates      Last-inbound-update-received signal.
	 * @param OutboundMessageRepository $messages     Dead-lettered message inspection.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly UpdateRepository $updates,
		private readonly OutboundMessageRepository $messages
	) {}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			\UniversalTelegram\Administration\Diagnostics\DiagnosticsPage::SLUG,
			__( 'Telegram Bots', 'universal-telegram' ),
			__( 'Bots', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page. Defense in depth: the menu registration's own
	 * capability parameter already denies an unauthorized user before this
	 * ever runs.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Telegram Bots', 'universal-telegram' ) . '</h1>';

		$this->render_bot_list();
		$this->render_create_bot_form();
		$this->render_dead_letter_list();

		echo '</div>';
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

		$this->render_op_form( 'test_connection', $bot->id(), __( 'Test connection', 'universal-telegram' ) );

		if ( ! $has_pending ) {
			$this->render_op_form( 'register_webhook', $bot->id(), __( 'Register webhook', 'universal-telegram' ) );
			$this->render_op_form( 'rotate_webhook', $bot->id(), __( 'Rotate webhook secret', 'universal-telegram' ) );
		} else {
			$this->render_op_form( 'retry_pending_webhook', $bot->id(), __( 'Retry pending rotation', 'universal-telegram' ) );
			$this->render_op_form( 'rollback_webhook', $bot->id(), __( 'Roll back rotation', 'universal-telegram' ) );
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="replace_token" />';
		echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot->id() ) . '" />';
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		echo '<input type="text" name="token" placeholder="' . esc_attr__( 'New token', 'universal-telegram' ) . '" />';
		submit_button( __( 'Replace token', 'universal-telegram' ), 'secondary', 'submit', false );
		echo '</form>';

		$this->render_op_form( 'delete_bot', $bot->id(), __( 'Delete bot', 'universal-telegram' ) );
	}

	/**
	 * Renders one single-button admin-post form.
	 *
	 * @param string $op     The 'op' field value.
	 * @param int    $bot_id The bot's primary key.
	 * @param string $label  The button label.
	 */
	private function render_op_form( string $op, int $bot_id, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot_id ) . '" />';
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
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
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="op" value="send_test_message" />';
			echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot->id() ) . '" />';
			echo '<input type="hidden" name="destination_id" value="' . esc_attr( (string) $destination->id() ) . '" />';
			wp_nonce_field( BotManagementController::NONCE_ACTION );
			submit_button( __( 'Send test message', 'universal-telegram' ), 'secondary', 'submit', false );
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="create_destination" />';
		echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot->id() ) . '" />';
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		echo '<input type="text" name="label" placeholder="' . esc_attr__( 'Label', 'universal-telegram' ) . '" /> ';
		echo '<select name="kind">';
		foreach ( \UniversalTelegram\Telegram\Configuration\DestinationKind::cases() as $kind ) {
			printf( '<option value="%1$s">%1$s</option>', esc_attr( $kind->value ) );
		}
		echo '</select> ';
		echo '<input type="text" name="chat_id" placeholder="' . esc_attr__( 'Chat ID', 'universal-telegram' ) . '" /> ';
		echo '<input type="number" name="message_thread_id" placeholder="' . esc_attr__( 'Topic ID (supergroup only)', 'universal-telegram' ) . '" /> ';
		submit_button( __( 'Add destination', 'universal-telegram' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Renders the add-bot form.
	 */
	private function render_create_bot_form(): void {
		echo '<h2>' . esc_html__( 'Add a bot', 'universal-telegram' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="create_bot" />';
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		echo '<p><input type="text" name="name" placeholder="' . esc_attr__( 'Name', 'universal-telegram' ) . '" /></p>';
		echo '<p><input type="text" name="token" placeholder="' . esc_attr__( 'Bot token', 'universal-telegram' ) . '" /></p>';
		submit_button( __( 'Add bot', 'universal-telegram' ) );
		echo '</form>';
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
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="op" value="requeue_message" />';
			echo '<input type="hidden" name="message_id" value="' . esc_attr( (string) $message->id() ) . '" />';
			wp_nonce_field( BotManagementController::NONCE_ACTION );
			submit_button( __( 'Requeue', 'universal-telegram' ), 'secondary', 'submit', false );
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
