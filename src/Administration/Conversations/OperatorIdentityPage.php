<?php
/**
 * Operator identity mapping admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Conversations;

use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * Manual WordPress-user <-> Telegram numeric-user-id mapping management —
 * the sole way a mapping is ever created (M07, docs/adr/0026). MANAGE-gated
 * (the broader, administrator-only capability), since a mapping grants
 * inbound Telegram operator-acting trust. This is the one screen
 * telegram_user_id and telegram_username, both SENSITIVE, are ever
 * displayed on — never the conversation inbox/detail views.
 */
final class OperatorIdentityPage {

	public const SLUG   = 'universal-telegram-operator-identities';
	public const TAB_ID = 'operator-identities';

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityRepository $identities Operator identity mappings.
	 */
	public function __construct(
		private readonly OperatorIdentityRepository $identities
	) {}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<p>' . esc_html__( 'Every human who may reply as an operator inside a Telegram support topic must be mapped here first. An unmapped Telegram account\'s replies never reach the visitor side.', 'universal-telegram' ) . '</p>';

		$this->render_mapping_list();
		$this->render_mapping_form();
	}

	/**
	 * Renders the existing-mapping list with delete actions. Shows the
	 * mapped WordPress user's display name and the Telegram username only
	 * here, on this MANAGE-gated screen — never the raw Telegram numeric id.
	 */
	private function render_mapping_list(): void {
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'WordPress user', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Telegram username', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Mapped since', 'universal-telegram' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $this->identities->all() as $identity ) {
			$user = get_userdata( $identity->wp_user_id() );

			echo '<tr>';
			printf(
				'<td>%s</td><td>%s</td><td>%s</td>',
				esc_html( false !== $user ? $user->display_name : (string) $identity->wp_user_id() ),
				esc_html( $identity->telegram_username() ?? '' ),
				esc_html( $identity->created_at() )
			);
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( OperatorIdentityRequestHandler::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( OperatorIdentityRequestHandler::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="op" value="delete_mapping" />';
			echo '<input type="hidden" name="wp_user_id" value="' . esc_attr( (string) $identity->wp_user_id() ) . '" />';
			submit_button( __( 'Delete', 'universal-telegram' ), 'delete', '', false );
			echo '</form>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders the create-mapping form.
	 */
	private function render_mapping_form(): void {
		echo '<h2>' . esc_html__( 'Add mapping', 'universal-telegram' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( OperatorIdentityRequestHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( OperatorIdentityRequestHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="create_mapping" />';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ut-operator-wp-user">' . esc_html__( 'WordPress user', 'universal-telegram' ) . '</label></th><td><select id="ut-operator-wp-user" name="wp_user_id">';
		foreach ( get_users( array( 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
			printf( '<option value="%d">%s</option>', (int) $user->ID, esc_html( $user->display_name ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="ut-operator-telegram-id">' . esc_html__( 'Telegram numeric user id', 'universal-telegram' ) . '</label></th><td><input type="text" id="ut-operator-telegram-id" name="telegram_user_id" class="regular-text" /></td></tr>';

		echo '<tr><th><label for="ut-operator-telegram-username">' . esc_html__( 'Telegram username (optional, for your own reference)', 'universal-telegram' ) . '</label></th><td><input type="text" id="ut-operator-telegram-username" name="telegram_username" class="regular-text" /></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Add mapping', 'universal-telegram' ) );
		echo '</form>';
	}
}
