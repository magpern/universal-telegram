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
	 * Each op decides its own redirect target — create_starter_set redirects
	 * back to its own review screen with an error flag on validation
	 * failure, every other op redirects to the plain Rules tab.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : '';

		switch ( $op ) {
			case 'save_rule':
				$saved = $this->save_rule();
				$this->redirect_and_exit( $saved ? $this->rules_tab_url() : $this->rules_tab_url() . '&save_error=invalid_condition' );
				return;
			case 'delete_rule':
				$this->delete_rule();
				$this->redirect_and_exit( $this->rules_tab_url() );
				return;
			case 'create_starter_set':
				$this->redirect_and_exit( $this->create_starter_set() );
				return;
		}

		$this->redirect_and_exit( $this->rules_tab_url() );
	}

	/**
	 * The plain Rules tab URL.
	 *
	 * @return string
	 */
	private function rules_tab_url(): string {
		return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . RuleBuilderPage::TAB_ID );
	}

	/**
	 * Handles the save_rule operation (create or update). Conditions arrive
	 * as the friendly builder's own `conditions[N][field|operator|value]`
	 * POST shape (M08.1) — never JSON — and are translated here into the
	 * exact flat clause array NotificationRuleRepository::save() already
	 * accepts and authoritatively validates; this translation performs no
	 * validation of its own.
	 *
	 * @return bool Whether the rule was saved. False routes handle_request()
	 *              to append a save_error flag so RuleBuilderPage can show an
	 *              accessible error summary (M08.1 plan "Accessibility and
	 *              admin integration") — the underlying rejection itself is
	 *              unchanged.
	 */
	private function save_rule(): bool {
		$id = isset( $_POST['id'] ) && '' !== $_POST['id'] ? (int) $_POST['id'] : null;

		$name               = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$event_type         = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '';
		$schema_version_min = isset( $_POST['schema_version_min'] ) ? (int) $_POST['schema_version_min'] : 1;
		$bot_id             = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$destination_id     = isset( $_POST['destination_id'] ) ? (int) $_POST['destination_id'] : 0;
		$template           = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : '';
		$enabled            = ! empty( $_POST['enabled'] );
		$priority           = isset( $_POST['priority'] ) ? (int) $_POST['priority'] : 100;
		$cooldown_seconds   = isset( $_POST['cooldown_minutes'] ) ? max( 0, (int) $_POST['cooldown_minutes'] ) * 60 : 0;
		$match_mode         = isset( $_POST['match_mode'] ) && 'any' === $_POST['match_mode'] ? 'any' : 'all';

		$conditions = ! empty( $_POST['conditions_locked'] )
			? $this->parse_preserved_conditions_from_post()
			: $this->parse_conditions_from_post();

		try {
			$this->rules->save( $id, $name, $event_type, $schema_version_min, $conditions, $bot_id, $destination_id, $template, $enabled, $priority, $cooldown_seconds, $match_mode );
		} catch ( InvalidConditionFieldException $exception ) {
			// The authoritative, server-side rejection — a malformed or
			// disallowed condition field is simply never saved. No raw
			// exception detail is ever rendered.
			return false;
		}

		return true;
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
	 * Decodes the hidden, non-editable `conditions_preserved_json` field
	 * rendered only for a rule whose conditions the visual builder cannot
	 * represent (M08.1 plan "Existing-rule compatibility strategy") — the
	 * admin never edits this value directly, it is simply resubmitted
	 * byte-for-byte from what RuleEditor::from_existing() originally
	 * rendered. NotificationRuleRepository::save() still re-validates it
	 * exactly as any other conditions array.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_preserved_conditions_from_post(): array {
		$raw     = isset( $_POST['conditions_preserved_json'] ) ? sanitize_textarea_field( wp_unslash( $_POST['conditions_preserved_json'] ) ) : '[]';
		$decoded = json_decode( (string) $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Handles the create_starter_set operation (M08.1 plan "Fix the
	 * starter-set flow"): the second, explicit confirmation step of the
	 * Store-essentials review screen. Creates exactly three disabled draft
	 * rules sharing one admin-chosen bot/destination — never a single
	 * click producing an incomplete rule. A missing bot or destination
	 * creates nothing and redirects back to the review screen with an
	 * error flag instead.
	 *
	 * @return string The redirect target.
	 */
	private function create_starter_set(): string {
		$bot_id         = isset( $_POST['bot_id'] ) ? (int) $_POST['bot_id'] : 0;
		$destination_id = isset( $_POST['destination_id'] ) ? (int) $_POST['destination_id'] : 0;

		if ( $bot_id <= 0 || $destination_id <= 0 ) {
			return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . RuleBuilderPage::TAB_ID . '&view=starter_set&error=missing_destination' );
		}

		foreach ( PresetCatalog::starter_set() as $preset ) {
			try {
				$this->rules->save(
					null,
					$preset['title'] . ' (draft)',
					$preset['event_type'],
					1,
					$preset['conditions'],
					$bot_id,
					$destination_id,
					$preset['message'],
					false,
					100,
					0,
					$preset['match_mode']
				);
			} catch ( InvalidConditionFieldException $exception ) {
				// A starter-set preset's own conditions are covered by
				// PresetCatalogTest's coverage assertions; this catch exists
				// only so one unexpected failure never leaves the remaining
				// draft rules uncreated.
				continue;
			}
		}

		return $this->rules_tab_url();
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
