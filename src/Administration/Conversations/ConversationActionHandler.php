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
use UniversalTelegram\Conversations\ConversationNoteRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\ConversationStatus;
use UniversalTelegram\Conversations\OperatorAvailability;
use UniversalTelegram\Conversations\OperatorAvailabilityRepository;
use UniversalTelegram\Conversations\OperatorIdentityRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Privacy\Classification;

/**
 * The single admin-post handler for every operator self-service and
 * administrator-override conversation-workflow action (M07, docs/adr/0026):
 * availability, CAS assignment/unassignment, the reopen lifecycle
 * transition, internal notes, and (WP8) manual deletion, mirroring
 * RuleBuilderRequestHandler's own single-handler, op-dispatch shape. Every
 * action independently re-verifies both its own required capability and
 * its own nonce, never relying solely on menu-registration-time gating.
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
	 * @param OperatorAvailabilityRepository $availability  Operator availability.
	 * @param OperatorIdentityRepository     $identities    Operator identity mappings, used to verify a target operator is actually mapped.
	 * @param ConversationRepository         $conversations Assignment and lifecycle transitions.
	 * @param ConversationNoteRepository     $notes         Internal notes.
	 * @param AuditLogger                    $audit         Records every successful state change.
	 */
	public function __construct(
		private readonly OperatorAvailabilityRepository $availability,
		private readonly OperatorIdentityRepository $identities,
		private readonly ConversationRepository $conversations,
		private readonly ConversationNoteRepository $notes,
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
			case 'assign':
				$this->assign();
				break;
			case 'unassign':
				$this->unassign();
				break;
			case 'reopen':
				$this->reopen();
				break;
			case 'add_note':
				$this->add_note();
				break;
		}

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=operator-inbox' ) );
	}

	/**
	 * Reads the submitted `expected_operator_id` field: an empty string
	 * means the caller displayed "currently unassigned"; a numeric string
	 * means the caller displayed that specific operator.
	 *
	 * @return int|null
	 */
	private function submitted_expected_operator_id(): ?int {
		$raw = isset( $_POST['expected_operator_id'] ) ? sanitize_text_field( wp_unslash( $_POST['expected_operator_id'] ) ) : '';

		return '' === $raw ? null : (int) $raw;
	}

	/**
	 * Concurrency-safe assignment (ADR-0026 decision 8). Requires
	 * MANAGE_CONVERSATIONS. Assigning to an operator whose current
	 * availability is busy/offline is blocked unless the acting user holds
	 * the broader MANAGE capability and explicitly confirmed an override
	 * (the `override` field); an override is always audited with a
	 * distinct action code (ADR-0026 decision 6).
	 */
	private function assign(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			return;
		}

		$conversation_id      = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
		$new_operator_id      = isset( $_POST['new_operator_id'] ) ? (int) $_POST['new_operator_id'] : 0;
		$expected_operator_id = $this->submitted_expected_operator_id();
		$override_requested   = ! empty( $_POST['override'] );

		if ( $conversation_id <= 0 || $new_operator_id <= 0 ) {
			return;
		}

		if ( null === $this->identities->find_by_wp_user_id( $new_operator_id ) ) {
			return;
		}

		$target_state    = $this->availability->find_for_operator( $new_operator_id );
		$target_is_busy  = null !== $target_state && in_array( $target_state->state(), array( OperatorAvailability::BUSY, OperatorAvailability::OFFLINE ), true );
		$is_override     = false;

		if ( $target_is_busy ) {
			if ( ! $override_requested || ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
				return;
			}

			$is_override = true;
		}

		$acting_user_id = get_current_user_id();
		$succeeded       = $this->conversations->assign_with_expected( $conversation_id, $expected_operator_id, $new_operator_id );

		if ( ! $succeeded ) {
			// A stale expectation: no change, no audit entry — the acting
			// user sees the redirect's now-current state instead.
			return;
		}

		$this->audit->record(
			$is_override ? 'conversation.assignment.set_with_availability_override' : 'conversation.assignment.set',
			'operator',
			$acting_user_id,
			array(
				'conversation_id' => $conversation_id,
				'operator_user_id' => $new_operator_id,
			),
			array(
				'conversation_id' => Classification::INTERNAL,
				'operator_user_id' => Classification::INTERNAL,
			),
			Classification::INTERNAL
		);
	}

	/**
	 * Concurrency-safe unassignment.
	 */
	private function unassign(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			return;
		}

		$conversation_id      = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
		$expected_operator_id = $this->submitted_expected_operator_id();

		if ( $conversation_id <= 0 ) {
			return;
		}

		$acting_user_id = get_current_user_id();
		$succeeded       = $this->conversations->assign_with_expected( $conversation_id, $expected_operator_id, null );

		if ( ! $succeeded ) {
			return;
		}

		$this->audit->record(
			'conversation.assignment.cleared',
			'operator',
			$acting_user_id,
			array( 'conversation_id' => $conversation_id ),
			array( 'conversation_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
	}

	/**
	 * The sole manual lifecycle transition this handler exposes:
	 * `resolved -> open` (ADR-0026 decision 7). Every other transition is
	 * system-driven (webhook capture, retention cleanup), never operator-
	 * triggered, so this method never accepts an arbitrary `to` status.
	 */
	private function reopen(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			return;
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;

		if ( $conversation_id <= 0 ) {
			return;
		}

		$succeeded = $this->conversations->transition( $conversation_id, ConversationStatus::RESOLVED, ConversationStatus::OPEN );

		if ( ! $succeeded ) {
			return;
		}

		$this->audit->record(
			'conversation.status.reopened',
			'operator',
			get_current_user_id(),
			array( 'conversation_id' => $conversation_id ),
			array( 'conversation_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
	}

	/**
	 * Adds an encrypted internal note.
	 */
	private function add_note(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_CONVERSATIONS ) ) {
			return;
		}

		$conversation_id = isset( $_POST['conversation_id'] ) ? (int) $_POST['conversation_id'] : 0;
		$body             = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';

		if ( $conversation_id <= 0 || '' === $body ) {
			return;
		}

		$acting_user_id = get_current_user_id();
		$note            = $this->notes->create( $conversation_id, $acting_user_id, $body );

		if ( null === $note ) {
			return;
		}

		$this->audit->record(
			'conversation.note.added',
			'operator',
			$acting_user_id,
			array( 'conversation_id' => $conversation_id ),
			array( 'conversation_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
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
