<?php
/**
 * Hub tab: administrator-authorized Support Chat pairing (ADR-0007 §2).
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Pairing;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;
use UniversalTelegram\SupportChatAdapter\Auth\PairingResult;
use UniversalTelegram\SupportChatAdapter\Auth\PairingService;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRecord;
use UniversalTelegram\SupportChatAdapter\Auth\PeerRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;

/**
 * Renders and processes the Support Chat pairing Hub tab. Every mutating
 * action here requires a currently authenticated administrator holding
 * BOTH `CapabilityRegistrar::MANAGE` and
 * `ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY` (ADR-0007 §2) — never
 * either capability alone. Never renders this plugin's own private key;
 * shows only the public key and key ID.
 *
 * Not declared final: tests override redirect_and_exit() to exercise
 * handle_*() without terminating the PHP process, exactly as
 * Administration\Automations\RuleBuilderRequestHandler does.
 */
class PairingController {

	public const TAB_ID = 'support-chat-pairing';

	public const NONCE_ACTION = 'universal_telegram_support_chat_pairing';

	public const ACTION_GENERATE_OWN_KEY = 'universal_telegram_support_chat_pairing_generate_own_key';
	public const ACTION_ROTATE_OWN_KEY   = 'universal_telegram_support_chat_pairing_rotate_own_key';
	public const ACTION_PAIR             = 'universal_telegram_support_chat_pairing_pair';
	public const ACTION_REVOKE           = 'universal_telegram_support_chat_pairing_revoke';
	public const ACTION_DISABLE          = 'universal_telegram_support_chat_pairing_disable';
	public const ACTION_ENABLE           = 'universal_telegram_support_chat_pairing_enable';

	/**
	 * Constructor.
	 *
	 * @param OwnKeyManager  $own_key This plugin's own key pair.
	 * @param PeerRepository $peers   Peer key store.
	 * @param PairingService $pairing Pairing orchestration.
	 */
	public function __construct(
		private readonly OwnKeyManager $own_key,
		private readonly PeerRepository $peers,
		private readonly PairingService $pairing
	) {
		add_action( 'admin_post_' . self::ACTION_GENERATE_OWN_KEY, array( $this, 'handle_generate_own_key' ) );
		add_action( 'admin_post_' . self::ACTION_ROTATE_OWN_KEY, array( $this, 'handle_rotate_own_key' ) );
		add_action( 'admin_post_' . self::ACTION_PAIR, array( $this, 'handle_pair' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE, array( $this, 'handle_revoke' ) );
		add_action( 'admin_post_' . self::ACTION_DISABLE, array( $this, 'handle_disable' ) );
		add_action( 'admin_post_' . self::ACTION_ENABLE, array( $this, 'handle_enable' ) );
	}

	/**
	 * Whether the current user holds both pairing capabilities.
	 */
	private function current_user_may_pair(): bool {
		return current_user_can( CapabilityRegistrar::MANAGE )
			&& current_user_can( ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY );
	}

	/**
	 * Idempotently ensures this plugin's own key pair exists.
	 */
	public function handle_generate_own_key(): void {
		$this->require_pairing_capability();
		check_admin_referer( self::NONCE_ACTION );

		$this->own_key->ensure_key_pair();

		$this->redirect();
	}

	/**
	 * Rotates this plugin's own key pair. Support Chat must re-pair against
	 * the new key ID (ADR-0007 §2) — rotation never propagates automatically.
	 */
	public function handle_rotate_own_key(): void {
		$this->require_pairing_capability();
		check_admin_referer( self::NONCE_ACTION );

		$this->own_key->rotate();

		$this->redirect( array( 'rotated' => '1' ) );
	}

	/**
	 * Pairs (or re-pairs, with explicit confirmation) the Support Chat peer.
	 */
	public function handle_pair(): void {
		$this->require_pairing_capability();
		check_admin_referer( self::NONCE_ACTION );

		$public_key_base64 = isset( $_POST['peer_public_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['peer_public_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$key_id            = isset( $_POST['peer_key_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['peer_key_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$confirm_replace   = ! empty( $_POST['confirm_replace'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$expires_raw       = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['expires_at'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.

		$allowed_operations = array();
		$posted_operations  = isset( $_POST['allowed_operations'] ) && is_array( $_POST['allowed_operations'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			? wp_unslash( $_POST['allowed_operations'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- filtered below.
			: array();
		foreach ( $posted_operations as $operation ) {
			if ( is_string( $operation ) ) {
				$allowed_operations[] = sanitize_key( $operation );
			}
		}

		$expires_at = '' !== $expires_raw ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $expires_raw ) ) : null;

		$actor_user_id = get_current_user_id();
		$result        = $this->pairing->pair(
			ContractConstants::PEER_ID,
			$public_key_base64,
			$key_id,
			$allowed_operations,
			ContractConstants::SUPPORT_CHAT_MANAGE_CAPABILITY,
			$confirm_replace,
			0 === $actor_user_id ? null : $actor_user_id,
			$expires_at
		);

		$this->redirect( array( 'pairing_result' => $result->reason() ) );
	}

	/**
	 * Revokes the paired Support Chat key immediately.
	 */
	public function handle_revoke(): void {
		$this->require_pairing_capability();
		check_admin_referer( self::NONCE_ACTION );

		$actor_user_id = get_current_user_id();
		$this->pairing->revoke( ContractConstants::PEER_ID, 0 === $actor_user_id ? null : $actor_user_id );

		$this->redirect();
	}

	/**
	 * Disables the paired Support Chat key without revoking it.
	 */
	public function handle_disable(): void {
		$this->require_pairing_capability();
		check_admin_referer( self::NONCE_ACTION );

		$actor_user_id = get_current_user_id();
		$this->pairing->disable( ContractConstants::PEER_ID, 0 === $actor_user_id ? null : $actor_user_id );

		$this->redirect();
	}

	/**
	 * Re-enables a disabled Support Chat peer.
	 */
	public function handle_enable(): void {
		$this->require_pairing_capability();
		check_admin_referer( self::NONCE_ACTION );

		$actor_user_id = get_current_user_id();
		$this->pairing->enable( ContractConstants::PEER_ID, 0 === $actor_user_id ? null : $actor_user_id );

		$this->redirect();
	}

	/**
	 * Renders the Hub tab content.
	 */
	public function render_tab_content(): void {
		if ( ! $this->current_user_may_pair() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Pairing requires both the Universal Telegram management capability and the Universal Support Chat management capability.', 'universal-telegram' ) . '</p></div>';
			return;
		}

		$own  = $this->own_key->public_key();
		$peer = $this->peers->find_by_peer_id( ContractConstants::PEER_ID );

		echo '<div class="universal-telegram-support-chat-pairing">';
		echo '<h2>' . esc_html__( 'Support Chat pairing', 'universal-telegram' ) . '</h2>';
		echo '<p>' . esc_html__( 'Mutual Ed25519 signing keys authenticate every Contract v1 call between this plugin and Universal Support Chat (ADR-0007). Neither plugin ever sees the other\'s private key.', 'universal-telegram' ) . '</p>';

		$this->render_flash();

		echo '<h3>' . esc_html__( 'This plugin\'s own key', 'universal-telegram' ) . '</h3>';
		if ( null === $own ) {
			echo '<p>' . esc_html__( 'No signing key pair exists yet.', 'universal-telegram' ) . '</p>';
			$this->render_action_form( self::ACTION_GENERATE_OWN_KEY, __( 'Generate key pair', 'universal-telegram' ) );
		} else {
			echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
			echo '<tr><th>' . esc_html__( 'Key ID', 'universal-telegram' ) . '</th><td><code>' . esc_html( $own['key_id'] ) . '</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Public key (base64)', 'universal-telegram' ) . '</th><td><code style="word-break:break-all">' . esc_html( $own['public_key'] ) . '</code></td></tr>';
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Share this key ID and public key with the Support Chat administrator to pair. Rotating replaces both immediately — Support Chat must re-pair with the new key.', 'universal-telegram' ) . '</p>';
			$this->render_action_form( self::ACTION_ROTATE_OWN_KEY, __( 'Rotate key pair', 'universal-telegram' ), true );
		}

		echo '<h3>' . esc_html__( 'Support Chat peer', 'universal-telegram' ) . '</h3>';
		if ( null !== $peer ) {
			echo '<table class="widefat striped" style="max-width:48rem"><tbody>';
			echo '<tr><th>' . esc_html__( 'Pairing state', 'universal-telegram' ) . '</th><td>' . esc_html( $peer->pairing_state() ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Key ID', 'universal-telegram' ) . '</th><td><code>' . esc_html( $peer->key_id() ) . '</code></td></tr>';
			echo '<tr><th>' . esc_html__( 'Permitted operations', 'universal-telegram' ) . '</th><td>' . esc_html( implode( ', ', $peer->allowed_operations() ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Paired', 'universal-telegram' ) . '</th><td>' . esc_html( $peer->created_at() ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last key change', 'universal-telegram' ) . '</th><td>' . esc_html( $peer->last_rotated_at() ?? '—' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Last successful call', 'universal-telegram' ) . '</th><td>' . esc_html( $peer->last_used_at() ?? '—' ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Expires', 'universal-telegram' ) . '</th><td>' . esc_html( $peer->expires_at() ?? esc_html__( 'Never', 'universal-telegram' ) ) . '</td></tr>';
			echo '<tr><th>' . esc_html__( 'Revoked', 'universal-telegram' ) . '</th><td>' . esc_html( $peer->revoked_at() ?? '—' ) . '</td></tr>';
			echo '</tbody></table>';

			if ( PeerRecord::STATUS_ACTIVE === $peer->status() ) {
				$this->render_action_form( self::ACTION_DISABLE, __( 'Disable', 'universal-telegram' ) );
			} elseif ( PeerRecord::STATUS_DISABLED === $peer->status() ) {
				$this->render_action_form( self::ACTION_ENABLE, __( 'Enable', 'universal-telegram' ) );
			}
			if ( PeerRecord::STATUS_REVOKED !== $peer->status() ) {
				$this->render_action_form( self::ACTION_REVOKE, __( 'Revoke', 'universal-telegram' ), true );
			}
		} else {
			echo '<p>' . esc_html__( 'Not yet paired.', 'universal-telegram' ) . '</p>';
		}

		echo '<h4>' . esc_html( null === $peer ? __( 'Pair with Support Chat', 'universal-telegram' ) : __( 'Replace Support Chat key', 'universal-telegram' ) ) . '</h4>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_PAIR ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="peer_public_key">' . esc_html__( 'Support Chat public key (base64)', 'universal-telegram' ) . '</label></th><td><input type="text" id="peer_public_key" name="peer_public_key" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row"><label for="peer_key_id">' . esc_html__( 'Support Chat key ID', 'universal-telegram' ) . '</label></th><td><input type="text" id="peer_key_id" name="peer_key_id" class="regular-text" required /></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Permitted operations', 'universal-telegram' ) . '</th><td>';
		foreach ( ContractConstants::support_chat_to_adapter_operations() as $operation ) {
			echo '<label style="display:block"><input type="checkbox" name="allowed_operations[]" value="' . esc_attr( $operation ) . '" checked /> ' . esc_html( $operation ) . '</label>';
		}
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="expires_at">' . esc_html__( 'Expiry (optional)', 'universal-telegram' ) . '</label></th><td><input type="datetime-local" id="expires_at" name="expires_at" /></td></tr>';
		if ( null !== $peer ) {
			echo '<tr><th scope="row">' . esc_html__( 'Confirm replace', 'universal-telegram' ) . '</th><td><label><input type="checkbox" name="confirm_replace" value="1" /> ' . esc_html__( 'I understand this replaces the currently active Support Chat key immediately.', 'universal-telegram' ) . '</label></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( null === $peer ? __( 'Pair', 'universal-telegram' ) : __( 'Replace key', 'universal-telegram' ) );
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Renders a flash notice from the last pairing/rotation action, if any.
	 */
	private function render_flash(): void {
		if ( isset( $_GET['rotated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Key pair rotated. Support Chat must re-pair with the new key ID.', 'universal-telegram' ) . '</p></div>';
		}

		if ( isset( $_GET['pairing_result'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
			$reason  = sanitize_key( wp_unslash( (string) $_GET['pairing_result'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$success = in_array( $reason, array( PairingResult::REASON_CREATED, PairingResult::REASON_UNCHANGED, PairingResult::REASON_REPLACED ), true );
			$class   = $success ? 'notice-success' : 'notice-error';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $this->pairing_result_message( $reason ) ) . '</p></div>';
		}
	}

	/**
	 * Human-readable message for a pairing reason code.
	 *
	 * @param string $reason One of PairingResult::REASON_* codes.
	 */
	private function pairing_result_message( string $reason ): string {
		return match ( $reason ) {
			PairingResult::REASON_CREATED => __( 'Paired successfully.', 'universal-telegram' ),
			PairingResult::REASON_UNCHANGED => __( 'Already paired with this key — no change made.', 'universal-telegram' ),
			PairingResult::REASON_REPLACED => __( 'Support Chat key replaced.', 'universal-telegram' ),
			PairingResult::REASON_CONFIRMATION_REQUIRED => __( 'An active key already exists. Check "Confirm replace" to proceed.', 'universal-telegram' ),
			PairingResult::REASON_INVALID_INPUT => __( 'The submitted key, key ID, or permitted operations are invalid.', 'universal-telegram' ),
			default => __( 'Pairing is currently unavailable. Try again later.', 'universal-telegram' ),
		};
	}

	/**
	 * Renders a minimal single-button confirm form.
	 *
	 * @param string $action  admin-post action name.
	 * @param string $label   Button label.
	 * @param bool   $confirm Whether to add a JS confirm() prompt.
	 */
	private function render_action_form( string $action, string $label, bool $confirm = false ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:.5rem">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );
		if ( $confirm ) {
			echo '<button type="submit" class="button" onclick="return confirm(\'' . esc_js( __( 'Are you sure?', 'universal-telegram' ) ) . '\');">' . esc_html( $label ) . '</button>';
		} else {
			submit_button( $label, 'secondary', '', false );
		}
		echo '</form>';
	}

	/**
	 * Requires both pairing capabilities or dies with 403.
	 */
	private function require_pairing_capability(): void {
		if ( ! $this->current_user_may_pair() ) {
			wp_die( esc_html__( 'Forbidden', 'universal-telegram' ), 403 );
		}
	}

	/**
	 * Redirects back to this tab.
	 *
	 * @param array<string, string> $extra_args Extra query args.
	 */
	private function redirect( array $extra_args = array() ): void {
		$redirect = add_query_arg(
			array_merge(
				array(
					'page' => 'universal-telegram',
					'tab'  => self::TAB_ID,
				),
				$extra_args
			),
			admin_url( 'admin.php' )
		);
		$this->redirect_and_exit( $redirect );
	}

	/**
	 * Issues the redirect and ends the request. A separate, overridable
	 * method so tests can exercise handle_*() without terminating the PHP
	 * process (mirrors Administration\Automations\RuleBuilderRequestHandler's
	 * own redirect_and_exit() convention).
	 *
	 * @param string $url Destination URL.
	 */
	protected function redirect_and_exit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
