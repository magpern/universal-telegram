<?php
/**
 * Rule builder request handling.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Automations\InvalidConditionFieldException;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;

/**
 * Every action independently re-verifies both
 * current_user_can(CapabilityRegistrar::MANAGE_AUTOMATIONS) and its own
 * nonce, never relying solely on menu-registration-time gating, mirroring
 * BotManagementController's exact existing pattern. save_rule's own
 * condition-field validation is delegated entirely to
 * NotificationRuleRepository::save() — the sole authoritative check,
 * regardless of what any client-side validation already did.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching BotManagementController's
 * exact precedent.
 */
class RuleBuilderRequestHandler {

	public const ADMIN_POST_ACTION = 'universal_telegram_rule_builder';
	public const NONCE_ACTION      = 'universal_telegram_rule_builder';

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleRepository $rules Notification rules.
	 */
	public function __construct( private readonly NotificationRuleRepository $rules ) {}

	/**
	 * The single admin-post request handler, dispatching on the 'op' field.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'save_rule':
				$this->save_rule();
				break;
			case 'delete_rule':
				$this->delete_rule();
				break;
		}

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . RuleBuilderPage::TAB_ID ) );
	}

	/**
	 * Handles the save_rule operation (create or update). Conditions arrive
	 * as the friendly builder's own `conditions[N][field|operator|value]`
	 * POST shape (M08.1) — never JSON — and are translated here into the
	 * exact flat clause array NotificationRuleRepository::save() already
	 * accepts and authoritatively validates; this translation performs no
	 * validation of its own.
	 */
	private function save_rule(): void {
		$id = isset( $_POST['id'] ) && '' !== $_POST['id'] ? (int) $_POST['id'] : null;

		$name               = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$event_type         = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '';
		$schema_version_min = isset( $_POST['schema_version_min'] ) ? (int) $_POST['schema_version_min'] : 1;
		$bot_id             = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$destination_id     = isset( $_POST['destination_id'] ) ? (int) $_POST['destination_id'] : 0;
		$template           = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : '';
		$enabled            = ! empty( $_POST['enabled'] );
		$priority           = isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 100;
		$cooldown_seconds   = isset( $_POST['cooldown_seconds'] ) ? max( 0, (int) $_POST['cooldown_seconds'] ) : 0;
		$match_mode         = isset( $_POST['match_mode'] ) && 'any' === $_POST['match_mode'] ? 'any' : 'all';

		$conditions = $this->parse_conditions_from_post();

		try {
			$this->rules->save( $id, $name, $event_type, $schema_version_min, $conditions, $bot_id, $destination_id, $template, $enabled, $priority, $cooldown_seconds, $match_mode );
		} catch ( InvalidConditionFieldException $exception ) {
			// The authoritative, server-side rejection — a malformed or
			// disallowed condition field is simply never saved. No raw
			// exception detail is ever rendered.
			return;
		}
	}

	/**
	 * Translates the friendly builder's `conditions[N][field|operator|value]`
	 * POST fields into a flat clause array. A row missing a field or
	 * operator is dropped rather than passed through — the server-side
	 * allowlist check in NotificationRuleRepository::save() remains the
	 * sole authority either way.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_conditions_from_post(): array {
		if ( ! isset( $_POST['conditions'] ) || ! is_array( $_POST['conditions'] ) ) {
			return array();
		}

		$raw_conditions = wp_unslash( $_POST['conditions'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$conditions     = array();

		foreach ( $raw_conditions as $clause ) {
			if ( ! is_array( $clause ) || empty( $clause['field'] ) || empty( $clause['operator'] ) ) {
				continue;
			}

			$conditions[] = array(
				'field'    => sanitize_text_field( (string) $clause['field'] ),
				'operator' => sanitize_text_field( (string) $clause['operator'] ),
				'value'    => sanitize_text_field( (string) ( $clause['value'] ?? '' ) ),
			);
		}

		return $conditions;
	}

	/**
	 * Handles the delete_rule operation.
	 */
	private function delete_rule(): void {
		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		if ( $id > 0 ) {
			$this->rules->delete( $id );
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
