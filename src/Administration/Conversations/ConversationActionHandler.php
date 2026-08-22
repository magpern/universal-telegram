<?php
/**
 * Operator conversation-workflow request handling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Conversations;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;

/**
 * The single admin-post handler for every operator self-service and
 * administrator-override conversation-workflow action (M07, docs/adr/0026):
 * availability (this work package), and — added by later M07 work
 * packages — assignment, lifecycle transitions, notes, and manual
 * deletion, mirroring RuleBuilderRequestHandler's own single-handler,
 * op-dispatch shape. Every action independently re-verifies both its own
 * required capability and its own nonce, never relying solely on
 * menu-registration-time gating.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching RuleBuilderRequestHandler's
 * exact precedent.
 */
class ConversationActionHandler {

	public const ADMIN_POST_ACTION = 'universal_telegram_conversation_action';
	public const NONCE_ACTION      = 'universal_telegram_conversation_action';

	/**
	 * Constructor.
	 *
	 * @param OperatorAvailabilityRepository $availability Operator availability.
	 * @param OperatorIdentityRepository     $identities   Operator identity mappings, used to verify a target operator is actually mapped.
	 * @param AuditLogger                    $audit        Records every successful state change.
	 */
	public function __construct(
		private readonly OperatorAvailabilityRepository $availability,
		private readonly OperatorIdentityRepository $identities,
		private readonly AuditLogger $audit
	) {}

	/**
	 * The single admin-post request handler, dispatching on the 'op' field.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) && ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'set_availability':
				$this->set_own_availability();
				break;
			case 'set_availability_for_operator':
				$this->set_availability_for_operator();
				break;
		}

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=operator-inbox' ) );
	}

	/**
	 * An operator sets their own availability. Requires
	 * MANAGE_CONVERSATIONS and that the acting user is themself mapped as
	 * an operator (a WordPress user with no identity mapping has no
	 * availability state to set).
	 */
	private function set_own_availability(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			return;
		}

		$acting_user_id = get_current_user_id();
		$state          = $this->sanitized_state();

		if ( null === $state || null === $this->identities->find_by_wp_user_id( $acting_user_id ) ) {
			return;
		}

		$this->availability->set_state( $acting_user_id, $state, $acting_user_id );
		$this->record_availability_change( $acting_user_id, $acting_user_id, $state, false );
	}

	/**
	 * An administrator (MANAGE, the broader capability) sets another mapped
	 * operator's availability — the override path.
	 */
	private function set_availability_for_operator(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		$acting_user_id = get_current_user_id();
		$target_user_id = isset( $_POST['operator_user_id'] ) ? (int) $_POST['operator_user_id'] : 0;
		$state          = $this->sanitized_state();

		if ( null === $state || $target_user_id <= 0 || null === $this->identities->find_by_wp_user_id( $target_user_id ) ) {
			return;
		}

		$this->availability->set_state( $target_user_id, $state, $acting_user_id );
		$this->record_availability_change( $target_user_id, $acting_user_id, $state, true );
	}

	/**
	 * The submitted `state` field, validated against the three allowed
	 * values — never trusted as-is.
	 *
	 * @return string|null
	 */
	private function sanitized_state(): ?string {
		$state = isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : '';

		$allowed = array( OperatorAvailability::AVAILABLE, OperatorAvailability::BUSY, OperatorAvailability::OFFLINE );

		return in_array( $state, $allowed, true ) ? $state : null;
	}

	/**
	 * Records one classified audit entry for an availability change. No
	 * Telegram identifier is ever included — only WordPress user ids and
	 * the new state, all INTERNAL.
	 *
	 * @param int    $target_user_id The operator whose state changed.
	 * @param int    $acting_user_id The WordPress user who performed the change.
	 * @param string $state          The new state.
	 * @param bool   $is_override    Whether this was a MANAGE administrator override of another operator's state.
	 */
	private function record_availability_change( int $target_user_id, int $acting_user_id, string $state, bool $is_override ): void {
		$this->audit->record(
			$is_override ? 'conversation.operator_availability.set_by_administrator' : 'conversation.operator_availability.set',
			'operator',
			$acting_user_id,
			array(
				'target_user_id' => $target_user_id,
				'state'          => $state,
			),
			array(
				'target_user_id' => Classification::INTERNAL,
				'state'          => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);
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
