<?php
/**
 * Rule builder admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * CRUD over NotificationRuleRepository. The condition-clause editor here is
 * a JSON textarea constrained, authoritatively, only by
 * NotificationRuleRepository::save()'s own server-side allowlist check
 * (M02 plan §9.1) — this page's own rendering is advisory only.
 */
final class RuleBuilderPage {

	public const SLUG   = 'universal-telegram-rules';
	public const TAB_ID = 'rules';

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleRepository $rules              Notification rules.
	 * @param Registry                   $registry           The current request's event registry.
	 * @param BotProfileRepository       $bots               Bot profiles.
	 * @param DestinationRepository      $destinations       Destinations.
	 * @param DigestEligibility|null     $digest_eligibility Live "currently batched by Visitor Digest" state (M11A §3.1); null only for pre-M11A callers.
	 */
	public function __construct(
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly ?DigestEligibility $digest_eligibility = null
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
