<?php
/**
 * Rule builder admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Diagnostics\DiagnosticsPage;
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

	public const SLUG = 'universal-telegram-rules';

	/**
	 * Constructor.
	 *
	 * @param NotificationRuleRepository $rules        Notification rules.
	 * @param Registry                   $registry     The current request's event registry.
	 * @param BotProfileRepository       $bots         Bot profiles.
	 * @param DestinationRepository      $destinations Destinations.
	 */
	public function __construct(
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations
	) {}

	/**
	 * Registers the admin menu entry.
	 */
	public function register_menu(): void {
		add_submenu_page(
			DiagnosticsPage::SLUG,
			__( 'Notification Rules', 'universal-telegram' ),
			__( 'Rules', 'universal-telegram' ),
			CapabilityRegistrar::MANAGE_AUTOMATIONS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Notification Rules', 'universal-telegram' ) . '</h1>';

		$this->render_rule_list();
		$this->render_rule_form();

		echo '</div>';
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
				esc_html( $rule->event_type() ),
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
		echo '</select></td></tr>';

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
