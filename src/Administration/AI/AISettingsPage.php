<?php
/**
 * AI provider configuration admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\AI;

use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * The AI tab of the administration hub (docs/adr/0028 decision 3 and
 * decision on Administration and Operator UX): enable toggle (default
 * off), model identifier, write-only masked API key field, and the
 * disclosure text/version-bump control. Gated on the existing
 * CapabilityRegistrar::MANAGE capability — no new capability constant.
 * Every action independently re-verifies both the capability and its own
 * nonce, mirroring SettingsPage's exact existing pattern.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching SettingsPage's exact
 * existing precedent.
 */
class AISettingsPage {

	public const TAB_ID = 'ai';

	public const ACTION_SAVE_SETTINGS   = 'universal_telegram_ai_save_settings';
	public const ACTION_SET_CREDENTIAL  = 'universal_telegram_ai_set_credential';
	public const ACTION_DELETE_CREDENTIAL = 'universal_telegram_ai_delete_credential';
	public const ACTION_BUMP_ACK        = 'universal_telegram_ai_bump_ack';

	/**
	 * Constructor.
	 *
	 * @param AIProviderRepository $repository Reads/writes the AI provider configuration.
	 */
	public function __construct( private readonly AIProviderRepository $repository ) {}

	/**
	 * The admin-post handler for the model/enablement save action.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::ACTION_SAVE_SETTINGS );

		$model   = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model'] ) ) : '';
		$enabled = isset( $_POST['enabled'] );

		$this->repository->update_settings( $model, $enabled );

		$this->redirect_and_exit( $this->tab_url() );
	}

	/**
	 * The admin-post handler for setting a new API key. A blank submitted
	 * value is a no-op (the existing key, if any, is left untouched) —
	 * the field is always rendered empty, never pre-filled, so there is
	 * no other way to represent "leave it unchanged" on this form.
	 */
	public function handle_set_credential(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::ACTION_SET_CREDENTIAL );

		$api_key = isset( $_POST['api_key'] ) ? (string) wp_unslash( $_POST['api_key'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		if ( '' !== trim( $api_key ) ) {
			$this->repository->set_credential( trim( $api_key ) );
		}

		$this->redirect_and_exit( $this->tab_url() );
	}

	/**
	 * The admin-post handler for the explicit "delete credential" action.
	 * Clears the stored key and forces enablement off in the same update
	 * (docs/adr/0028 decision on provider config deletion/disablement).
	 */
	public function handle_delete_credential(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::ACTION_DELETE_CREDENTIAL );

		$this->repository->delete_credential();

		$this->redirect_and_exit( $this->tab_url() );
	}

	/**
	 * The admin-post handler for bumping the disclosure text/version. A
	 * deliberate, warned action — every conversation acknowledged under
	 * the prior version becomes permanently ineligible for new drafts.
	 */
	public function handle_bump_ack(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::ACTION_BUMP_ACK );

		$new_text = isset( $_POST['ack_text'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['ack_text'] ) ) : '';

		if ( '' !== trim( $new_text ) ) {
			$this->repository->bump_ack_policy( wp_generate_uuid4(), $new_text );
		}

		$this->redirect_and_exit( $this->tab_url() );
	}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$config = $this->repository->get();

		if ( null === $config ) {
			echo '<p>' . esc_html__( 'AI configuration is unavailable.', 'universal-telegram' ) . '</p>';
			return;
		}

		echo '<h2>' . esc_html__( 'AI Draft Assistant', 'universal-telegram' ) . '</h2>';
		echo '<p>' . esc_html__( 'Operator-assist only: a draft is never sent to a visitor or Telegram automatically.', 'universal-telegram' ) . '</p>';

		if ( ! $config->has_credential() ) {
			echo '<p><strong>' . esc_html__( 'AI cannot be enabled until an API key is configured below.', 'universal-telegram' ) . '</strong></p>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SAVE_SETTINGS );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE_SETTINGS ) . '" />';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Provider', 'universal-telegram' ) . '</th><td>' . esc_html( $config->provider() ) . '</td></tr>';
		printf(
			'<tr><th><label for="ut-ai-model">%1$s</label></th><td><input type="text" id="ut-ai-model" name="model" maxlength="191" value="%2$s" /></td></tr>',
			esc_html__( 'Model identifier', 'universal-telegram' ),
			esc_attr( $config->model() )
		);
		printf(
			'<tr><th><label for="ut-ai-enabled">%1$s</label></th><td><label><input type="checkbox" id="ut-ai-enabled" name="enabled" value="1" %2$s /> %3$s</label></td></tr>',
			esc_html__( 'Enabled', 'universal-telegram' ),
			checked( $config->is_enabled(), true, false ),
			esc_html__( 'Enable AI draft generation', 'universal-telegram' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save', 'universal-telegram' ) );
		echo '</form>';

		echo '<h3>' . esc_html__( 'API Key', 'universal-telegram' ) . '</h3>';
		echo '<p>' . esc_html( $config->has_credential() ? __( 'A key is currently configured (hidden).', 'universal-telegram' ) : __( 'No key configured.', 'universal-telegram' ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION_SET_CREDENTIAL );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SET_CREDENTIAL ) . '" />';
		echo '<input type="password" name="api_key" autocomplete="off" placeholder="' . esc_attr__( 'Enter a new key to replace it', 'universal-telegram' ) . '" /> ';
		submit_button( __( 'Set Key', 'universal-telegram' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $config->has_credential() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Delete the stored API key and disable AI? Any in-flight draft will be cancelled.', 'universal-telegram' ) ) . '\');">';
			wp_nonce_field( self::ACTION_DELETE_CREDENTIAL );
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_DELETE_CREDENTIAL ) . '" />';
			submit_button( __( 'Delete Key', 'universal-telegram' ), 'delete', 'submit', false );
			echo '</form>';
		}

		echo '<h3>' . esc_html__( 'Visitor Disclosure', 'universal-telegram' ) . '</h3>';
		echo '<p>' . esc_html__( 'Shown to visitors as an optional, unchecked checkbox before their first message, only while AI is enabled.', 'universal-telegram' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Changing this text retroactively excludes every previously acknowledged conversation from future drafts. This cannot be undone.', 'universal-telegram' ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'' . esc_js( __( 'Bump the disclosure version? Every already-acknowledged conversation will become permanently ineligible for new drafts.', 'universal-telegram' ) ) . '\');">';
		wp_nonce_field( self::ACTION_BUMP_ACK );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_BUMP_ACK ) . '" />';
		echo '<p><textarea name="ack_text" rows="3" cols="60">' . esc_textarea( $config->ack_text() ) . '</textarea></p>';
		submit_button( __( 'Save New Disclosure Text (bumps version)', 'universal-telegram' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * This tab's own URL within the Hub.
	 */
	private function tab_url(): string {
		return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID );
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
