<?php
/**
 * Operator identity mapping request handling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Conversations;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * Every action independently re-verifies both
 * current_user_can(CapabilityRegistrar::MANAGE) and its own nonce, never
 * relying solely on menu-registration-time gating, mirroring
 * RuleBuilderRequestHandler's exact existing pattern. MANAGE (not the
 * narrower MANAGE_CONVERSATIONS), since creating a mapping grants inbound
 * Telegram operator-acting trust (M07, docs/adr/0026).
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching RuleBuilderRequestHandler's
 * exact precedent.
 */
class OperatorIdentityRequestHandler {

	public const ADMIN_POST_ACTION = 'universal_telegram_operator_identity';
	public const NONCE_ACTION      = 'universal_telegram_operator_identity';

	/**
	 * Constructor.
	 *
	 * @param OperatorIdentityRepository $identities Operator identity mappings.
	 */
	public function __construct( private readonly OperatorIdentityRepository $identities ) {}

	/**
	 * The single admin-post request handler, dispatching on the 'op' field.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'create_mapping':
				$this->create_mapping();
				break;
			case 'delete_mapping':
				$this->delete_mapping();
				break;
		}

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . OperatorIdentityPage::TAB_ID ) );
	}

	/**
	 * Handles the create_mapping operation. telegram_user_id and
	 * telegram_username are never logged or echoed back beyond this
	 * request's own redirect — neither is passed through the URL.
	 */
	private function create_mapping(): void {
		$wp_user_id        = isset( $_POST['wp_user_id'] ) ? (int) $_POST['wp_user_id'] : 0;
		$telegram_user_id  = isset( $_POST['telegram_user_id'] ) ? (int) $_POST['telegram_user_id'] : 0;
		$telegram_username = isset( $_POST['telegram_username'] ) ? sanitize_text_field( wp_unslash( $_POST['telegram_username'] ) ) : '';

		if ( $wp_user_id <= 0 || $telegram_user_id <= 0 ) {
			return;
		}

		$this->identities->create( $wp_user_id, $telegram_user_id, '' === $telegram_username ? null : $telegram_username, get_current_user_id() );
	}

	/**
	 * Handles the delete_mapping operation.
	 */
	private function delete_mapping(): void {
		$wp_user_id = isset( $_POST['wp_user_id'] ) ? (int) $_POST['wp_user_id'] : 0;

		if ( $wp_user_id > 0 ) {
			$this->identities->delete_for_wp_user( $wp_user_id );
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
