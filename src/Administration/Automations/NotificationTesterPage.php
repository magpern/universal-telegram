<?php
/**
 * Friendly notification tester admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Automations\NotificationRule;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * The M08.2 "Test notifications" tab, replacing the developer-oriented
 * Simulator (M08.2 plan). Two modes, each a plain GET selection step
 * (bookmarkable — the selected rule id or event type is a catalog key,
 * never administrator-entered data) followed by a nonce-protected POST
 * that renders its own result inline in the same response: nothing is
 * ever persisted, cached, logged, dispatched, or sent to Telegram
 * (NotificationTester's own structural guarantee). Every label shown is
 * friendly (EventCatalogLabels/FieldTypeCatalog); no event id, field
 * path, JSON, or raw `{{template}}` token is ever printed here.
 */
final class NotificationTesterPage {

	public const SLUG   = 'universal-telegram-rule-simulator';
	public const TAB_ID = 'test-notifications';

	public const NONCE_ACTION = 'universal_telegram_notification_test';

	private const MODE_RULE  = 'rule';
	private const MODE_EVENT = 'event';

	/**
	 * Constructor.
	 *
	 * @param NotificationTester         $tester              The no-dispatch test engine.
	 * @param NotificationRuleRepository $rules               Notification rules.
	 * @param Registry                   $registry             The current request's event registry.
	 * @param BotProfileRepository       $bots                 Bot profiles, for the "About this notification" destination label.
	 * @param DestinationRepository      $destinations         Destinations, for the same label.
	 * @param WooCommerceSupport|null    $woocommerce_support   Gates WooCommerce-only event families, matching RuleBuilderPage. Null only for pre-M08.1-style callers, treated as inactive.
	 */
	public function __construct(
		private readonly NotificationTester $tester,
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly ?WooCommerceSupport $woocommerce_support = null
	) {}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$this->render_styles();

		echo '<p>' . esc_html__( 'Test your notification setup safely. No Telegram message is sent.', 'universal-telegram' ) . '</p>';

		$mode = $this->requested_mode();

		$this->render_mode_selector( $mode );

		if ( self::MODE_EVENT === $mode ) {
			$this->render_custom_scenario_mode();
			return;
		}

		$this->render_existing_notification_mode();
	}

	/**
	 * The requested mode — always read from GET, a catalog selector, never
	 * administrator-entered data.
	 *
	 * @return string
	 */
	private function requested_mode(): string {
		$requested = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only mode switch, no mutation.

		return self::MODE_EVENT === $requested ? self::MODE_EVENT : self::MODE_RULE;
	}

	/**
	 * The two-option mode switch, a plain GET form so both the switch
	 * itself and the resulting selection screen stay bookmarkable.
	 *
	 * @param string $active_mode The currently selected mode.
	 */
	private function render_mode_selector( string $active_mode ): void {
		echo '<form method="get" class="ut-tester-mode-form">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HubPage::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_ID ) . '" />';
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Test mode', 'universal-telegram' ) . '</legend>';

		echo '<label class="ut-mode-option"><input type="radio" name="mode" value="' . esc_attr( self::MODE_RULE ) . '" ' . checked( self::MODE_RULE, $active_mode, false ) . ' onchange="this.form.submit()" /> ' . esc_html__( 'Test an existing notification', 'universal-telegram' ) . '</label> ';
		echo '<label class="ut-mode-option"><input type="radio" name="mode" value="' . esc_attr( self::MODE_EVENT ) . '" ' . checked( self::MODE_EVENT, $active_mode, false ) . ' onchange="this.form.submit()" /> ' . esc_html__( 'Test a custom scenario', 'universal-telegram' ) . '</label>';

		echo '</fieldset>';
		echo '<noscript><p><button type="submit" class="button">' . esc_html__( 'Switch mode', 'universal-telegram' ) . '</button></p></noscript>';
		echo '</form>';
	}

	/**
	 * Mode 1: "Test an existing notification."
	 */
	private function render_existing_notification_mode(): void {
		$rule = $this->requested_rule();

		$this->render_rule_picker( null !== $rule ? $rule->id() : null );

		if ( null === $rule ) {
			return;
		}

		echo '<fieldset class="ut-tester-about"><legend>' . esc_html__( 'About this notification', 'universal-telegram' ) . '</legend>';
		$this->render_rule_summary( $rule );
		echo '</fieldset>';

		$sample_values = null;

		if ( self::is_valid_test_post( self::MODE_RULE, (string) $rule->id() ) ) {
			$sample_values = $this->collect_posted_sample_values( $rule->event_type() );
		}

		$this->render_example_values_form(
			$rule->event_type(),
			self::MODE_RULE,
			array( 'rule_id' => (string) $rule->id() ),
			$sample_values ?? array()
		);

		if ( null !== $sample_values ) {
			$this->render_result_region( array( $this->tester->test_rule( $rule, $sample_values ) ) );
		}
	}

	/**
	 * The rule requested via GET, or null if none/unknown.
	 *
	 * @return NotificationRule|null
	 */
	private function requested_rule(): ?NotificationRule {
		if ( ! isset( $_GET['rule_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only selector, no mutation.
			return null;
		}

		return $this->rules->find( absint( wp_unslash( $_GET['rule_id'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same GET-only selector as above.
	}

	/**
	 * The friendly rule picker: grouped by event family, GET-submitted.
	 *
	 * @param int|null $selected_rule_id The currently selected rule id, if any.
	 */
	private function render_rule_picker( ?int $selected_rule_id ): void {
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HubPage::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_ID ) . '" />';
		echo '<input type="hidden" name="mode" value="' . esc_attr( self::MODE_RULE ) . '" />';

		echo '<p><label for="ut-tester-rule">' . esc_html__( 'Notification', 'universal-telegram' ) . '</label><br />';
		echo '<select id="ut-tester-rule" name="rule_id">';
		echo '<option value="">' . esc_html__( 'Choose a notification…', 'universal-telegram' ) . '</option>';

		foreach ( $this->rules->all() as $rule ) {
			printf(
				'<option value="%d" %s>%s (%s)</option>',
				(int) $rule->id(),
				selected( $selected_rule_id, $rule->id(), false ),
				esc_html( $rule->name() ),
				esc_html( EventCatalogLabels::event_type_label( $rule->event_type() ) )
			);
		}

		echo '</select> ';
		submit_button( __( 'Load', 'universal-telegram' ), 'secondary', '', false );
		echo '</p></form>';
	}

	/**
	 * The read-only "About this notification" summary: friendly event,
	 * destination, plain-language conditions (or the legacy compatibility
	 * notice), and a rendered synthetic example preview — never the raw
	 * template text or a `{{token}}`.
	 *
	 * @param NotificationRule $rule The selected rule.
	 */
	private function render_rule_summary( NotificationRule $rule ): void {
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Event:', 'universal-telegram' ), esc_html( EventCatalogLabels::event_type_label( $rule->event_type() ) ) );
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Destination:', 'universal-telegram' ), esc_html( $this->destination_label( $rule->bot_id(), $rule->destination_id() ) ) );

		if ( ! $rule->enabled() ) {
			echo '<p class="notice notice-warning inline"><span class="dashicons dashicons-marker" aria-hidden="true"></span> ' . esc_html__( 'This notification is currently turned off.', 'universal-telegram' ) . '</p>';
		}

		echo '<p><strong>' . esc_html__( 'Conditions:', 'universal-telegram' ) . '</strong></p>';
		$this->render_condition_summary( $rule );

		$preview = ( new PreviewRenderer( $this->registry ) )->render( $rule->event_type(), $rule->template() );

		echo '<p><strong>' . esc_html__( 'Example notification preview:', 'universal-telegram' ) . '</strong></p>';
		if ( '' === $preview ) {
			echo '<p class="notice notice-error inline">' . esc_html__( 'This notification\'s message could not be built for preview.', 'universal-telegram' ) . '</p>';
		} else {
			echo '<blockquote class="ut-tester-preview">' . nl2br( esc_html( $preview ) ) . '</blockquote>';
		}
	}

	/**
	 * The plain-language conditions summary, or the compatibility notice
	 * for a rule the visual builder cannot represent (never raw JSON).
	 *
	 * @param NotificationRule $rule The selected rule.
	 */
	private function render_condition_summary( NotificationRule $rule ): void {
		if ( array() === $rule->conditions() ) {
			echo '<p>' . esc_html__( 'This notification always sends for this event — it has no extra conditions.', 'universal-telegram' ) . '</p>';
			return;
		}

		if ( ! RuleEditor::from_existing( $rule )['representable'] ) {
			echo '<p class="description">' . esc_html__( 'This notification uses a condition format that cannot be shown in friendly terms here. It is still tested using its real, saved conditions.', 'universal-telegram' ) . '</p>';
			return;
		}

		$joiner = 'any' === $rule->match_mode()
			? __( 'Sends when any of the following match:', 'universal-telegram' )
			: __( 'Sends when all of the following match:', 'universal-telegram' );

		echo '<p>' . esc_html( $joiner ) . '</p><ul>';
		foreach ( $rule->conditions() as $clause ) {
			$field = (string) ( $clause['field'] ?? '' );
			printf(
				'<li>%s %s %s</li>',
				esc_html( (string) FieldTypeCatalog::label( $field ) ),
				esc_html( FailingConditionExplainer::operator_label( (string) ( $clause['operator'] ?? '' ) ) ),
				esc_html( FailingConditionExplainer::format_value( $field, $clause['value'] ?? null ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Mode 2: "Test a custom scenario."
	 */
	private function render_custom_scenario_mode(): void {
		$event_type = $this->requested_event_type();

		$this->render_event_picker( $event_type );

		if ( '' === $event_type || ! $this->registry->is_registered( $event_type ) ) {
			return;
		}

		$sample_values = null;

		if ( self::is_valid_test_post( self::MODE_EVENT, $event_type ) ) {
			$sample_values = $this->collect_posted_sample_values( $event_type );
		}

		$this->render_example_values_form(
			$event_type,
			self::MODE_EVENT,
			array( 'event_type' => $event_type ),
			$sample_values ?? array()
		);

		if ( null === $sample_values ) {
			return;
		}

		$results = $this->tester->test_event( $event_type, $sample_values );

		if ( array() === $results ) {
			echo '<p class="notice notice-info inline" tabindex="-1" id="ut-tester-result">' . esc_html__( 'No notification rules are currently set up for this event.', 'universal-telegram' ) . '</p>';
			$this->render_focus_script();
			return;
		}

		$this->render_result_region( $results );
	}

	/**
	 * The event type requested via GET, or ''.
	 *
	 * @return string
	 */
	private function requested_event_type(): string {
		return isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only selector, no mutation.
	}

	/**
	 * The family-grouped, friendly event picker — the same grouping
	 * RuleBuilderPage's own event picker uses (EventFamilyCatalog, M08.2
	 * plan §4), WooCommerce-only families disabled with an explanation
	 * when WooCommerce is inactive rather than silently omitted.
	 *
	 * @param string $selected_event_type The currently selected event type.
	 */
	private function render_event_picker( string $selected_event_type ): void {
		$woocommerce_active = null !== $this->woocommerce_support && $this->woocommerce_support->is_active();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( HubPage::SLUG ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( self::TAB_ID ) . '" />';
		echo '<input type="hidden" name="mode" value="' . esc_attr( self::MODE_EVENT ) . '" />';

		echo '<p><label for="ut-tester-event">' . esc_html__( 'Event', 'universal-telegram' ) . '</label><br />';
		echo '<select id="ut-tester-event" name="event_type">';
		echo '<option value="">' . esc_html__( 'Choose an event…', 'universal-telegram' ) . '</option>';

		foreach ( EventFamilyCatalog::families() as $family ) {
			$family_disabled = $family['requires_woocommerce'] && ! $woocommerce_active;

			printf( '<optgroup label="%s"%s>', esc_attr( $family['label'] ), $family_disabled ? ' disabled="disabled"' : '' );
			foreach ( $family['event_types'] as $event_type ) {
				printf(
					'<option value="%s" %s%s>%s</option>',
					esc_attr( $event_type ),
					selected( $selected_event_type, $event_type, false ),
					$family_disabled ? ' disabled="disabled"' : '',
					esc_html( EventCatalogLabels::event_type_label( $event_type ) )
				);
			}
			echo '</optgroup>';
		}

		echo '</select> ';
		submit_button( __( 'Choose', 'universal-telegram' ), 'secondary', '', false );
		echo '</p>';

		if ( ! $woocommerce_active ) {
			echo '<p class="description">' . esc_html__( 'WooCommerce event families are shown disabled because WooCommerce is not currently active on this site.', 'universal-telegram' ) . '</p>';
		}

		echo '</form>';
	}

	/**
	 * The "Example values" POST form: a plain, typed control per eligible
	 * field, pre-filled from FieldTypeCatalog::preview_value() or the
	 * administrator's own previously submitted value. This is the only
	 * form on this page that submits by POST — every example value is
	 * administrator-entered data, so it is never carried in the URL
	 * (M08.2 plan §3).
	 *
	 * @param string                $event_type    The selected event type.
	 * @param string                $mode          The active mode, carried as a hidden field.
	 * @param array<string, string> $hidden_fields Additional hidden selector fields (rule_id or event_type).
	 * @param array<string, mixed>  $current_values The values to pre-fill, if a test already ran this request.
	 */
	private function render_example_values_form( string $event_type, string $mode, array $hidden_fields, array $current_values ): void {
		// The action URL repeats mode/rule_id/event_type as query params
		// (identical to the hidden fields below) so $_GET still carries
		// them on the POST response — requested_mode()/requested_rule()/
		// requested_event_type() all resolve from $_GET, exactly as they
		// do for the GET picker forms. Without this, submitting the form
		// would land back on an empty picker with no result shown at all,
		// since $_GET (not $_POST) drives which rule/event is selected.
		$action_url = 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID . '&mode=' . rawurlencode( $mode );
		foreach ( $hidden_fields as $field_name => $field_value ) {
			$action_url .= '&' . rawurlencode( $field_name ) . '=' . rawurlencode( (string) $field_value );
		}

		echo '<form method="post" action="' . esc_url( admin_url( $action_url ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="mode" value="' . esc_attr( $mode ) . '" />';
		foreach ( $hidden_fields as $field_name => $field_value ) {
			echo '<input type="hidden" name="' . esc_attr( $field_name ) . '" value="' . esc_attr( $field_value ) . '" />';
		}

		echo '<fieldset><legend>' . esc_html__( 'Example values', 'universal-telegram' ) . '</legend>';
		echo '<p class="description">' . esc_html__( 'These are example values only — nothing here is sent or saved.', 'universal-telegram' ) . '</p>';
		echo '<table class="form-table"><tbody>';

		foreach ( ConditionRowRenderer::eligible_fields( $event_type, $this->registry ) as $field_path ) {
			$field_id = 'ut-tester-value-' . sanitize_html_class( $field_path );
			$value    = $current_values[ $field_path ] ?? FieldTypeCatalog::preview_value( $field_path );

			echo '<tr><th><label for="' . esc_attr( $field_id ) . '">' . esc_html( (string) FieldTypeCatalog::label( $field_path ) ) . '</label></th><td>';
			$this->render_value_input( $field_id, $field_path, (string) $value );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
		submit_button( __( 'Test this notification', 'universal-telegram' ) );
		echo '</fieldset></form>';
	}

	/**
	 * One example-value control, typed per FieldTypeCatalog::type() — the
	 * same type-to-control mapping ConditionRowRenderer's own condition
	 * value input uses, rendered here under the `values[field_path]` POST
	 * name instead of `conditions[i][value]`.
	 *
	 * @param string $field_id The input's id/for.
	 * @param string $field    The field path (used as the POST array key).
	 * @param string $value    The pre-filled value.
	 */
	private function render_value_input( string $field_id, string $field, string $value ): void {
		$type = FieldTypeCatalog::type( $field );
		$name = 'values[' . $field . ']';

		if ( FieldTypeCatalog::TYPE_CHOICE === $type ) {
			echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( FieldTypeCatalog::choice_options( $field ) as $option_value => $option_label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $option_value ), selected( $value, $option_value, false ), esc_html( $option_label ) );
			}
			echo '</select>';
			return;
		}

		if ( FieldTypeCatalog::TYPE_BOOLEAN === $type ) {
			echo '<select id="' . esc_attr( $field_id ) . '" name="' . esc_attr( $name ) . '">';
			foreach ( ConditionRowRenderer::boolean_value_labels() as $option_value => $option_label ) {
				printf( '<option value="%s" %s>%s</option>', esc_attr( $option_value ), selected( $value, $option_value, false ), esc_html( $option_label ) );
			}
			echo '</select>';
			return;
		}

		$input_type = in_array( $type, array( FieldTypeCatalog::TYPE_NUMBER, FieldTypeCatalog::TYPE_MONEY ), true ) ? 'number' : 'text';
		$step       = FieldTypeCatalog::TYPE_MONEY === $type ? ' step="0.01"' : '';

		printf(
			'<input type="%s" id="%s" class="regular-text" name="%s" value="%s"%s />',
			esc_attr( $input_type ),
			esc_attr( $field_id ),
			esc_attr( $name ),
			esc_attr( $value ),
			$step // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed literal string, not user input.
		);
	}

	/**
	 * Whether the current request is a valid, nonce-verified POST for the
	 * given mode/selector — dies via check_admin_referer() on an invalid
	 * or missing nonce, exactly like every other mutating admin form in
	 * this plugin, before any example value is ever read.
	 *
	 * @param string $expected_mode     The mode this form was rendered for.
	 * @param string $expected_selector The rule id or event type this form was rendered for.
	 *
	 * @return bool
	 */
	private static function is_valid_test_post( string $expected_mode, string $expected_selector ): bool {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return false;
		}

		$posted_mode     = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '';
		$posted_selector = isset( $_POST['rule_id'] ) ? sanitize_text_field( wp_unslash( $_POST['rule_id'] ) ) : ( isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '' );

		if ( $posted_mode !== $expected_mode || $posted_selector !== $expected_selector ) {
			return false;
		}

		check_admin_referer( self::NONCE_ACTION );

		return true;
	}

	/**
	 * Sanitizes every posted example value per its own FieldTypeCatalog
	 * type — never json_decode(), never a raw passthrough (M08.2 plan §6).
	 *
	 * @param string $event_type The selected event type.
	 *
	 * @return array<string, mixed>
	 */
	private function collect_posted_sample_values( string $event_type ): array {
		$raw_values = isset( $_POST['values'] ) && is_array( $_POST['values'] ) ? $_POST['values'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by is_valid_test_post() before this method is ever called.

		$sample = array();

		foreach ( ConditionRowRenderer::eligible_fields( $event_type, $this->registry ) as $field_path ) {
			if ( ! isset( $raw_values[ $field_path ] ) || ! is_string( $raw_values[ $field_path ] ) ) {
				continue;
			}

			$sample[ $field_path ] = $this->sanitize_posted_value( $field_path, sanitize_text_field( wp_unslash( $raw_values[ $field_path ] ) ) );
		}

		return $sample;
	}

	/**
	 * One posted value, sanitized per its own field type.
	 *
	 * @param string $field The field path.
	 * @param string $raw   The already `sanitize_text_field()`-cleaned raw value.
	 *
	 * @return mixed
	 */
	private function sanitize_posted_value( string $field, string $raw ): mixed {
		return match ( FieldTypeCatalog::type( $field ) ) {
			FieldTypeCatalog::TYPE_NUMBER  => (string) absint( $raw ),
			FieldTypeCatalog::TYPE_MONEY   => number_format( (float) $raw, 2, '.', '' ),
			FieldTypeCatalog::TYPE_BOOLEAN => in_array( $raw, array( 'true', 'false' ), true ) ? $raw : 'false',
			FieldTypeCatalog::TYPE_CHOICE  => array_key_exists( $raw, FieldTypeCatalog::choice_options( $field ) ) ? $raw : (string) FieldTypeCatalog::preview_value( $field ),
			default => $raw,
		};
	}

	/**
	 * Renders the result region for one or more tested rules — a single
	 * aria-live container, focus moved onto it after the POST response
	 * loads, colour never the sole status cue.
	 *
	 * @param array<int, NotificationTestResult> $results One or more results.
	 */
	private function render_result_region( array $results ): void {
		echo '<div id="ut-tester-result" class="ut-tester-result" tabindex="-1" aria-live="polite">';
		echo '<h2>' . esc_html__( 'Result', 'universal-telegram' ) . '</h2>';

		foreach ( $results as $result ) {
			$this->render_one_result( $result );
		}

		echo '</div>';
		$this->render_focus_script();
	}

	/**
	 * One rule's own result block.
	 *
	 * @param NotificationTestResult $result The rule's own test result.
	 */
	private function render_one_result( NotificationTestResult $result ): void {
		echo '<div class="ut-tester-result-row">';

		printf( '<h3>%s</h3>', esc_html( $result->rule_name() ) );

		if ( $result->has_unrepresentable_legacy_conditions() ) {
			echo '<p class="description">' . esc_html__( 'This notification uses a condition format that cannot be shown in friendly terms here. It is still tested using its real, saved conditions.', 'universal-telegram' ) . '</p>';
		}

		[ $icon, $status_class, $message ] = $this->outcome_presentation( $result->outcome() );

		printf(
			'<p class="ut-tester-outcome %s"><span class="dashicons %s" aria-hidden="true"></span> %s</p>',
			esc_attr( $status_class ),
			esc_attr( $icon ),
			esc_html( $message )
		);

		if ( NotificationTestOutcome::NOT_MATCHED === $result->outcome() && array() !== $result->failing_reasons() ) {
			echo '<ul class="ut-tester-reasons">';
			foreach ( $result->failing_reasons() as $reason ) {
				echo '<li>' . esc_html( $reason ) . '</li>';
			}
			echo '</ul>';
		}

		if ( NotificationTestOutcome::WOULD_SEND === $result->outcome() && null !== $result->rendered_preview() ) {
			echo '<p><strong>' . esc_html__( 'Example notification preview:', 'universal-telegram' ) . '</strong></p>';
			echo '<blockquote class="ut-tester-preview">' . nl2br( esc_html( $result->rendered_preview() ) ) . '</blockquote>';
		}

		echo '</div>';
	}

	/**
	 * The icon class, status class, and plain-language sentence for one
	 * outcome — colour is always a secondary cue alongside both the icon
	 * and the sentence, never the only signal (M08.2 plan §5).
	 *
	 * @param NotificationTestOutcome $outcome The result's own outcome.
	 *
	 * @return array{0: string, 1: string, 2: string}
	 */
	private function outcome_presentation( NotificationTestOutcome $outcome ): array {
		return match ( $outcome ) {
			NotificationTestOutcome::WOULD_SEND => array( 'dashicons-yes', 'is-success', __( 'This notification would be sent.', 'universal-telegram' ) ),
			NotificationTestOutcome::NOT_MATCHED => array( 'dashicons-no', 'is-not-matched', __( 'This notification would not be sent.', 'universal-telegram' ) ),
			NotificationTestOutcome::DISABLED => array( 'dashicons-no', 'is-disabled', __( 'This notification would not be sent because it is turned off.', 'universal-telegram' ) ),
			NotificationTestOutcome::DESTINATION_INELIGIBLE => array( 'dashicons-warning', 'is-ineligible', __( 'This notification\'s conditions matched, but it would not be sent because its destination is currently disabled.', 'universal-telegram' ) ),
			NotificationTestOutcome::TEMPLATE_INVALID => array( 'dashicons-warning', 'is-invalid', __( 'This notification\'s conditions matched, but its message could not be built. Edit the notification\'s message and try again.', 'universal-telegram' ) ),
		};
	}

	/**
	 * "<bot name> / <destination label>" for one rule's own bot/destination
	 * pair, or a plain fallback if either was since deleted.
	 *
	 * @param int $bot_id         The rule's bot id.
	 * @param int $destination_id The rule's destination id.
	 *
	 * @return string
	 */
	private function destination_label( int $bot_id, int $destination_id ): string {
		$bot = $this->bots->find( $bot_id );

		if ( null === $bot ) {
			return __( 'Unknown destination', 'universal-telegram' );
		}

		foreach ( $this->destinations->for_bot( $bot_id ) as $destination ) {
			if ( $destination->id() === $destination_id ) {
				return $bot->name() . ' / ' . $destination->label();
			}
		}

		return __( 'Unknown destination', 'universal-telegram' );
	}

	/**
	 * Moves keyboard/screen-reader focus onto the result region once the
	 * POST response loads — the same tabindex="-1" + inline-script pattern
	 * RuleBuilderPage's own error summary already uses (M08.2 plan §5).
	 */
	private function render_focus_script(): void {
		echo '<script>document.addEventListener( "DOMContentLoaded", function () { var el = document.getElementById( "ut-tester-result" ); if ( el ) { el.focus(); } } );</script>';
	}

	/**
	 * A small inline stylesheet — no new build pipeline, matching
	 * RuleBuilderPage's own existing approach.
	 */
	private function render_styles(): void {
		echo '<style>
			.ut-mode-option { margin-right: 24px; font-weight: 600; }
			.ut-tester-about, .ut-tester-result { border: 1px solid #dcdcde; border-radius: 4px; padding: 12px 16px; margin: 16px 0; background: #fff; }
			.ut-tester-preview { border-left: 4px solid #2271b1; margin: 8px 0; padding: 8px 12px; background: #f6f7f7; white-space: pre-wrap; }
			.ut-tester-outcome { font-weight: 600; display: flex; align-items: center; gap: 6px; }
			.ut-tester-outcome.is-success { color: #1a7f37; }
			.ut-tester-outcome.is-not-matched { color: #646970; }
			.ut-tester-outcome.is-disabled { color: #646970; }
			.ut-tester-outcome.is-ineligible, .ut-tester-outcome.is-invalid { color: #b32d2e; }
			.ut-tester-reasons { margin: 4px 0 12px 20px; }
			.ut-tester-result-row { padding: 8px 0; border-top: 1px solid #f0f0f1; }
			.ut-tester-result-row:first-of-type { border-top: none; }
		</style>';
	}
}
