<?php
/**
 * Shared bot/destination form-rendering collaborator.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\Telegram\Configuration\DestinationKind;

/**
 * Renders the admin-post forms shared by the manual Bots page
 * (BotManagementPage) and the setup wizard (BotSetupWizardRenderer) — the
 * add-bot form, the create-destination form (optionally pre-filled), and
 * the single-op button form used for register_webhook, send_test_message,
 * and every other bot/destination action. Every form posts to the existing
 * BotManagementController::ADMIN_POST_ACTION handler; this class introduces
 * no new admin-post action, no new controller, and never renders a token
 * value of any kind. Page-agnostic on purpose: neither BotManagementPage
 * nor BotSetupWizardRenderer is injected into the other (M06.1 plan §
 * "Rendering").
 */
final class TelegramFormFields {

	/**
	 * Renders the add-bot form (name + token, posts create_bot).
	 */
	public function create_bot_form(): void {
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
	 * Renders the create-destination form (posts create_destination),
	 * optionally pre-filled — used by the wizard's step 4 to default to
	 * `Website Support` / `supergroup` with a blank topic ID.
	 *
	 * @param int    $bot_id        The owning bot's primary key.
	 * @param string $prefill_label Pre-filled label value, or '' for none.
	 * @param string $prefill_kind  Pre-selected DestinationKind value, or '' for none.
	 */
	public function create_destination_form( int $bot_id, string $prefill_label = '', string $prefill_kind = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="create_destination" />';
		echo '<input type="hidden" name="bot_id" value="' . esc_attr( (string) $bot_id ) . '" />';
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		echo '<input type="text" name="label" value="' . esc_attr( $prefill_label ) . '" placeholder="' . esc_attr__( 'Label', 'universal-telegram' ) . '" /> ';
		echo '<select name="kind">';
		foreach ( DestinationKind::cases() as $kind ) {
			printf(
				'<option value="%1$s"%2$s>%1$s</option>',
				esc_attr( $kind->value ),
				selected( $prefill_kind, $kind->value, false )
			);
		}
		echo '</select> ';
		echo '<input type="text" name="chat_id" placeholder="' . esc_attr__( 'Chat ID', 'universal-telegram' ) . '" /> ';
		echo '<input type="number" name="message_thread_id" placeholder="' . esc_attr__( 'Topic ID (supergroup only)', 'universal-telegram' ) . '" /> ';
		submit_button( __( 'Add destination', 'universal-telegram' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Renders one single-button admin-post form for any existing op
	 * (register_webhook, rotate_webhook, test_connection, send_test_message,
	 * requeue_message, delete_bot, delete_destination, ...), with whichever
	 * hidden fields that op requires.
	 *
	 * @param string            $op            The 'op' field value.
	 * @param array<string,int> $hidden_fields  Hidden field name => integer value (e.g. ['bot_id' => 5, 'destination_id' => 7]).
	 * @param string            $label         The button label.
	 */
	public function op_button_form( string $op, array $hidden_fields, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:8px;">';
		echo '<input type="hidden" name="action" value="' . esc_attr( BotManagementController::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="' . esc_attr( $op ) . '" />';
		foreach ( $hidden_fields as $field_name => $field_value ) {
			echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( (string) $field_value ) . '" />';
		}
		wp_nonce_field( BotManagementController::NONCE_ACTION );
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form>';
	}
}
