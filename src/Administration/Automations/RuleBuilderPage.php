<?php
/**
 * Rule builder admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * CRUD over NotificationRuleRepository. The condition-clause editor here is
 * a JSON textarea constrained, authoritatively, only by
 * NotificationRuleRepository::save()'s own server-side allowlist check
 * (M02 plan §9.1) — this page's own rendering is advisory only. Also
 * composes the M11B "Intelligence" settings section (operational summary +
 * threshold alerts) — the natural existing home for automation-adjacent
 * configuration, per docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md
 * §5, rather than a new Hub tab.
 */
final class RuleBuilderPage {

	public const SLUG   = 'universal-telegram-rules';
	public const TAB_ID = 'rules';

	public const INTELLIGENCE_ADMIN_POST_ACTION = 'universal_telegram_intelligence_settings_save';
	public const INTELLIGENCE_NONCE_ACTION      = 'universal_telegram_intelligence_settings_save';

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleRepository $rules                Notification rules.
	 * @param Registry                   $registry             The current request's event registry.
	 * @param BotProfileRepository       $bots                 Bot profiles.
	 * @param DestinationRepository      $destinations         Destinations.
	 * @param DigestEligibility|null     $digest_eligibility   Live "currently batched by Visitor Digest" state (M11A §3.1); also supplies the shared destination-eligibility filter (§5) for the Intelligence section's dropdowns. Null only for pre-M11A callers.
	 * @param Settings|null              $settings             Reads/writes the operational_summary_* and alert_* fields (§5). Null only for pre-M11B callers.
	 * @param IntelligenceSettings|null  $intelligence_settings Typed reader over the same fields. Null only for pre-M11B callers.
	 * @param IntelligencePanel|null     $intelligence_panel    The AI-summary review UI (§2.6/§5), composed after the settings form. Null only for pre-WP7 callers.
	 */
	public function __construct(
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly ?DigestEligibility $digest_eligibility = null,
		private readonly ?Settings $settings = null,
		private readonly ?IntelligenceSettings $intelligence_settings = null,
		private readonly ?IntelligencePanel $intelligence_panel = null
	) {}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$this->render_rule_list();
		$this->render_rule_form();
		$this->render_intelligence_settings();
	}

	/**
	 * The Intelligence settings save handler (operator-post save action
	 * distinct from RuleBuilderRequestHandler's own rule-CRUD action, since
	 * this section owns a different field set entirely — mirrors
	 * VisitorTrackingPage's own self-contained save-handler pattern).
	 */
	public function handle_intelligence_settings_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::INTELLIGENCE_NONCE_ACTION );

		if ( null === $this->settings || null === $this->digest_eligibility ) {
			$this->redirect_and_exit( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . self::TAB_ID ) );
			return;
		}

		$input = isset( $_POST['intelligence_settings'] ) && is_array( $_POST['intelligence_settings'] )
			? wp_unslash( $_POST['intelligence_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		foreach ( array(
			'operational_summary_enabled',
			'alert_checkout_failure_count_enabled',
			'alert_order_failure_spike_enabled',
			'alert_js_error_spike_enabled',
		) as $field ) {
			$input[ $field ] = isset( $input[ $field ] );
		}

		$current    = $this->settings->get();
		$sanitized  = $this->settings->sanitize( array_merge( $current, $input ) );
		update_option( Settings::OPTION_NAME, $sanitized );

		// The alert/summary destination fields above just changed; the
		// shared DigestEligibility eligibility-filter methods (§5) used by
		// this section's dropdowns already re-validate live on every read,
		// but the digest's own cached is_active() must not be left stale by
		// this save either, matching VisitorTrackingPage's own precedent.
		$this->digest_eligibility->invalidate();

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . self::TAB_ID ) );
	}

	/**
	 * Renders the M11B Intelligence settings section: the Operational
	 * Summary target/schedule and the three fixed threshold alerts, each
	 * independently toggleable and default-disabled
	 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §2, §5).
	 */
	private function render_intelligence_settings(): void {
		if ( null === $this->settings || null === $this->digest_eligibility ) {
			return;
		}

		$values = $this->settings->get();

		echo '<h2>' . esc_html__( 'Intelligence', 'universal-telegram' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::INTELLIGENCE_NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::INTELLIGENCE_ADMIN_POST_ACTION ) . '" />';

		echo '<h3>' . esc_html__( 'Daily operations summary', 'universal-telegram' ) . '</h3>';
		echo '<p><label><input type="checkbox" name="intelligence_settings[operational_summary_enabled]" value="1" ' . checked( $values['operational_summary_enabled'], true, false ) . ' /> ' .
			esc_html__( 'Enable daily operations summary', 'universal-telegram' ) . '</label></p>';

		$this->render_bot_destination_pair( 'operational_summary_bot_id', 'operational_summary_destination_id', $values );

		echo '<p><label>' . esc_html__( 'Send hour (UTC)', 'universal-telegram' ) . ' ';
		echo '<input type="number" min="0" max="23" name="intelligence_settings[operational_summary_hour_utc]" value="' . esc_attr( (string) $values['operational_summary_hour_utc'] ) . '" />';
		echo '</label></p>';

		echo '<h3>' . esc_html__( 'Threshold alerts', 'universal-telegram' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Each alert is disabled by default and, once fired, will not fire again for the same condition for one hour.', 'universal-telegram' ) . '</p>';

		$this->render_bot_destination_pair( 'alert_bot_id', 'alert_destination_id', $values );

		$alert_labels = array(
			'checkout_failure_count' => __( 'Checkout failure alert', 'universal-telegram' ),
			'order_failure_spike'    => __( 'Order failure alert', 'universal-telegram' ),
			'js_error_spike'         => __( 'Error spike alert', 'universal-telegram' ),
		);

		foreach ( $alert_labels as $alert_type => $label ) {
			$enabled_field   = 'alert_' . $alert_type . '_enabled';
			$threshold_field = 'alert_' . $alert_type . '_threshold';

			echo '<p><label><input type="checkbox" name="intelligence_settings[' . esc_attr( $enabled_field ) . ']" value="1" ' . checked( $values[ $enabled_field ], true, false ) . ' /> ' .
				esc_html( $label ) . '</label> ';
			echo '<label>' . esc_html__( 'Threshold', 'universal-telegram' ) . ' ';
			echo '<input type="number" name="intelligence_settings[' . esc_attr( $threshold_field ) . ']" value="' . esc_attr( (string) $values[ $threshold_field ] ) . '" /></label></p>';
		}

		submit_button( __( 'Save Intelligence settings', 'universal-telegram' ) );
		echo '</form>';

		if ( null !== $this->intelligence_panel ) {
			$this->intelligence_panel->render();
		}
	}

	/**
	 * Renders one bot+destination dropdown pair, filtered by the shared
	 * M11A eligibility rule (never a conversation-linked destination).
	 *
	 * @param string               $bot_field         The bot Settings field name.
	 * @param string               $destination_field The destination Settings field name.
	 * @param array<string, mixed> $values             The current settings values.
	 */
	private function render_bot_destination_pair( string $bot_field, string $destination_field, array $values ): void {
		$selected_bot_id = null === $values[ $bot_field ] ? 0 : (int) $values[ $bot_field ];

		echo '<p><label>' . esc_html__( 'Bot', 'universal-telegram' ) . ' ';
		echo '<select name="intelligence_settings[' . esc_attr( $bot_field ) . ']">';
		echo '<option value="">' . esc_html__( '— Select a bot —', 'universal-telegram' ) . '</option>';
		foreach ( $this->bots->all() as $bot ) {
			if ( BotStatus::ACTIVE !== $bot->status() ) {
				continue;
			}
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$bot->id(),
				selected( $selected_bot_id, $bot->id(), false ),
				esc_html( $bot->name() )
			);
		}
		echo '</select></label></p>';

		$selected_destination_id = null === $values[ $destination_field ] ? 0 : (int) $values[ $destination_field ];
		$eligible_destinations   = $selected_bot_id > 0 ? $this->digest_eligibility->eligible_destinations_for_bot( $selected_bot_id ) : array();

		echo '<p><label>' . esc_html__( 'Destination', 'universal-telegram' ) . ' ';
		echo '<select name="intelligence_settings[' . esc_attr( $destination_field ) . ']">';
		echo '<option value="">' . esc_html__( '— Select a destination —', 'universal-telegram' ) . '</option>';
		foreach ( $eligible_destinations as $destination ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				$destination->id(),
				selected( $selected_destination_id, $destination->id(), false ),
				esc_html( $destination->label() )
			);
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__(
			'Only enabled, manually configured destinations belonging to the selected bot appear here — a destination created automatically for a website chat conversation can never be selected.',
			'universal-telegram'
		) . '</p>';
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

	/**
	 * Renders the existing-rule list with delete actions.
	 */
	private function render_rule_list(): void {
		echo '<table class="widefat striped"><thead><tr><th>' .
			esc_html__( 'Name', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Event type', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Enabled', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Priority', 'universal-telegram' ) . '</th><th>' .
			esc_html__( 'Cooldown (s)', 'universal-telegram' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( $this->rules->all() as $rule ) {
			echo '<tr>';
			printf(
				'<td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td>',
				esc_html( $rule->name() ),
				esc_html( $rule->event_type() ) . $this->digest_badge( $rule->event_type() ),
				$rule->enabled() ? esc_html__( 'Yes', 'universal-telegram' ) : esc_html__( 'No', 'universal-telegram' ),
				esc_html( (string) $rule->priority() ),
				esc_html( (string) $rule->cooldown_seconds() )
			);
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
			wp_nonce_field( RuleBuilderRequestHandler::NONCE_ACTION );
			echo '<input type="hidden" name="action" value="' . esc_attr( RuleBuilderRequestHandler::ADMIN_POST_ACTION ) . '" />';
			echo '<input type="hidden" name="op" value="delete_rule" />';
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $rule->id() ) . '" />';
			submit_button( __( 'Delete', 'universal-telegram' ), 'delete', '', false );
			echo '</form>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Renders a small inline badge next to an event type currently batched
	 * by an active Visitor Digest, or an empty string otherwise — a live,
	 * state-reflecting label, never a static "always superseded" claim
	 * (M11A §3.1).
	 *
	 * @param string $event_type The rule's own event type.
	 *
	 * @return string
	 */
	private function digest_badge( string $event_type ): string {
		if ( null === $this->digest_eligibility ) {
			return '';
		}

		if ( ! in_array( $event_type, DigestEligibility::SUPPRESSED_EVENT_TYPES, true ) ) {
			return '';
		}

		if ( ! $this->digest_eligibility->is_active() ) {
			return '';
		}

		return ' <span class="ut-digest-badge">' . esc_html__( 'Currently batched by Visitor Digest', 'universal-telegram' ) . '</span>';
	}

	/**
	 * Renders the create-rule form.
	 */
	private function render_rule_form(): void {
		echo '<h2>' . esc_html__( 'Add rule', 'universal-telegram' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( RuleBuilderRequestHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( RuleBuilderRequestHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="save_rule" />';

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ut-rule-name">' . esc_html__( 'Name', 'universal-telegram' ) . '</label></th><td><input type="text" id="ut-rule-name" name="name" class="regular-text" /></td></tr>';

		echo '<tr><th><label for="ut-rule-event-type">' . esc_html__( 'Event type', 'universal-telegram' ) . '</label></th><td><select id="ut-rule-event-type" name="event_type">';
		foreach ( $this->registry->all() as $entry ) {
			printf( '<option value="%s">%s</option>', esc_attr( $entry['event_type'] ), esc_html( $entry['event_type'] ) );
		}
		echo '</select>';
		if ( null !== $this->digest_eligibility && $this->digest_eligibility->is_active() ) {
			echo '<p class="description">' . esc_html__(
				'Visitor Digest is currently enabled and active: page view, navigation, search, product view, and cart/checkout-intent event types will not send individually while that remains the case.',
				'universal-telegram'
			) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th><label for="ut-rule-bot">' . esc_html__( 'Bot', 'universal-telegram' ) . '</label></th><td><select id="ut-rule-bot" name="bot_id">';
		foreach ( $this->bots->all() as $bot ) {
			printf( '<option value="%d">%s</option>', (int) $bot->id(), esc_html( $bot->name() ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="ut-rule-destination">' . esc_html__( 'Destination', 'universal-telegram' ) . '</label></th><td><select id="ut-rule-destination" name="destination_id">';
		foreach ( $this->bots->all() as $bot ) {
			foreach ( $this->destinations->for_bot( $bot->id() ) as $destination ) {
				printf( '<option value="%d">%s</option>', (int) $destination->id(), esc_html( $bot->name() . ' / ' . $destination->label() ) );
			}
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="ut-rule-conditions">' . esc_html__( 'Conditions (JSON)', 'universal-telegram' ) . '</label></th><td><textarea id="ut-rule-conditions" name="conditions_json" class="large-text code" rows="4">[]</textarea>' .
			'<p class="description">' . esc_html__( 'A flat JSON array of {"field", "operator", "value"} clauses. Every rule is validated server-side against the selected event type\'s own allowed fields.', 'universal-telegram' ) . '</p></td></tr>';

		echo '<tr><th><label for="ut-rule-template">' . esc_html__( 'Message template', 'universal-telegram' ) . '</label></th><td><textarea id="ut-rule-template" name="template" class="large-text" rows="3"></textarea></td></tr>';

		echo '<tr><th><label for="ut-rule-priority">' . esc_html__( 'Priority', 'universal-telegram' ) . '</label></th><td><input type="number" id="ut-rule-priority" name="priority" value="100" /></td></tr>';

		echo '<tr><th><label for="ut-rule-cooldown">' . esc_html__( 'Cooldown seconds', 'universal-telegram' ) . '</label></th><td><input type="number" id="ut-rule-cooldown" name="cooldown_seconds" value="0" min="0" /></td></tr>';

		echo '<tr><th><label for="ut-rule-enabled">' . esc_html__( 'Enabled', 'universal-telegram' ) . '</label></th><td><input type="checkbox" id="ut-rule-enabled" name="enabled" value="1" checked="checked" /></td></tr>';

		echo '</tbody></table>';

		submit_button( __( 'Save rule', 'universal-telegram' ) );
		echo '</form>';
	}
}
