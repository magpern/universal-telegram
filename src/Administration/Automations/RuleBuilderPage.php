<?php
/**
 * Rule builder admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use UniversalTelegram\Administration\Shared\BotDestinationPairFields;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Automations\Intelligence\IntelligenceSettings;
use UniversalTelegram\Automations\NotificationRuleRepository;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Integrations\WooCommerce\WooCommerceSupport;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationRepository;

/**
 * CRUD over NotificationRuleRepository. The Add Rule form is a friendly,
 * WordPress-native flow (M08.1): a grouped plain-language event picker and
 * a visual "Only when…" condition-row builder, with no JSON textarea and
 * no technical identifier ever shown as visible text — constrained,
 * authoritatively, only by NotificationRuleRepository::save()'s own
 * server-side allowlist check (M02 plan §9.1, unchanged); this page's own
 * rendering is advisory only. Also composes the M11B "Intelligence"
 * settings section (operational summary + threshold alerts) — the natural
 * existing home for automation-adjacent configuration, per
 * docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §5,
 * rather than a new Hub tab.
 */
final class RuleBuilderPage {

	public const SLUG   = 'universal-telegram-rules';
	public const TAB_ID = 'rules';

	public const INTELLIGENCE_ADMIN_POST_ACTION = 'universal_telegram_intelligence_settings_save';
	public const INTELLIGENCE_NONCE_ACTION      = 'universal_telegram_intelligence_settings_save';

	public const PREVIEW_ADMIN_POST_ACTION = 'universal_telegram_rule_preview';
	public const PREVIEW_NONCE_ACTION      = 'universal_telegram_rule_preview';

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
	 * @param WooCommerceSupport|null    $woocommerce_support    Gates WooCommerce-only event families and presets (M08.1). Null only for pre-M08.1 callers, treated as WooCommerce-inactive.
	 */
	public function __construct(
		private readonly NotificationRuleRepository $rules,
		private readonly Registry $registry,
		private readonly BotProfileRepository $bots,
		private readonly DestinationRepository $destinations,
		private readonly ?DigestEligibility $digest_eligibility = null,
		private readonly ?Settings $settings = null,
		private readonly ?IntelligenceSettings $intelligence_settings = null,
		private readonly ?IntelligencePanel $intelligence_panel = null,
		private readonly ?WooCommerceSupport $woocommerce_support = null
	) {}

	/**
	 * Plain-language event families (M08.1 plan "Friendly labels"): grouping
	 * only, derived from the existing event_type list — no Registry change.
	 * `visitor.click` is deliberately excluded from every family (task
	 * requirement); it keeps its EventCatalogLabels entry for other read
	 * paths (Events tab, history) unaffected.
	 *
	 * @var array<string, array{label: string, requires_woocommerce: bool, event_types: array<int, string>}>
	 */
	private const EVENT_FAMILIES = array(
		'website_and_users'   => array(
			'label'                => 'Website and users',
			'requires_woocommerce' => false,
			'event_types'          => array(
				'wordpress.login_succeeded',
				'wordpress.admin_login',
				'wordpress.login_failed',
				'wordpress.user_registered',
				'wordpress.user_role_changed',
				'wordpress.password_reset',
				'wordpress.post_published',
				'wordpress.comment_submitted',
				'wordpress.plugin_activated',
				'wordpress.plugin_deactivated',
				'wordpress.update_available',
				'wordpress.update_completed',
			),
		),
		'store_orders'        => array(
			'label'                => 'Store orders and payments',
			'requires_woocommerce' => true,
			'event_types'          => array(
				'woocommerce.order_created',
				'woocommerce.order_status_changed',
				'woocommerce.payment_completed',
				'woocommerce.order_failed',
				'woocommerce.order_cancelled',
				'woocommerce.refund_created',
			),
		),
		'stock_and_checkout'  => array(
			'label'                => 'Stock and checkout',
			'requires_woocommerce' => true,
			'event_types'          => array(
				'woocommerce.stock_threshold_crossed',
				'woocommerce.cart_item_added',
				'woocommerce.coupon_applied',
				'woocommerce.coupon_rejected',
				'woocommerce.checkout_validation_failed',
			),
		),
		'website_health'      => array(
			'label'                => 'Website health',
			'requires_woocommerce' => false,
			'event_types'          => array(
				'wordpress.scheduled_task_failed',
				'wordpress.rest_request_failed',
				'wordpress.email_sending_failed',
				'wordpress.fatal_error',
			),
		),
		'visitor_activity'    => array(
			'label'                => 'Visitor activity',
			'requires_woocommerce' => false,
			'event_types'          => array(
				'visitor.session_started',
				'visitor.page_viewed',
				'visitor.navigation',
				'visitor.search_performed',
				'visitor.javascript_error',
				'visitor.product_viewed',
				'visitor.add_to_cart_intent',
				'visitor.checkout_started_intent',
			),
		),
	);

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		if ( isset( $_GET['view'] ) && 'starter_set' === $_GET['view'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only view switch, no mutation; the confirmation POST below re-verifies capability and nonce itself.
			$this->render_starter_set_review();
			return;
		}

		$editing = null;
		if ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a GET-only edit-mode switch, no mutation; the save POST below re-verifies capability and nonce itself.
			$rule = $this->rules->find( (int) $_GET['edit'] );
			if ( null !== $rule ) {
				$editing = RuleEditor::from_existing( $rule );
			}
		}

		$this->render_rule_list();

		if ( null === $editing ) {
			$this->render_presets();
		} else {
			echo '<p><a href="' . esc_url( $this->rules_tab_url() ) . '">&laquo; ' . esc_html__( 'Back to presets', 'universal-telegram' ) . '</a></p>';
		}

		$this->render_rule_form( $editing );
		$this->render_intelligence_settings();
	}

	/**
	 * The URL for the Store-essentials two-step review screen (step one:
	 * a plain GET, no mutation).
	 *
	 * @return string
	 */
	private function starter_set_review_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . self::TAB_ID . '&view=starter_set' );
	}

	/**
	 * The plain Rules tab URL, used as the "« Back to presets" link and as
	 * the post-confirmation redirect target.
	 *
	 * @return string
	 */
	private function rules_tab_url(): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . self::TAB_ID );
	}

	/**
	 * Renders "1. Start with a common notification": individual preset
	 * cards (each a starting configuration only — selecting one fills the
	 * builder below via JS, it never saves or enables a rule by itself),
	 * the Store-essentials starter-set entry point (WooCommerce-active
	 * only), and "Create a custom notification".
	 */
	private function render_presets(): void {
		echo '<h2>' . esc_html__( 'Start with a common notification', 'universal-telegram' ) . '</h2>';

		$presets = array();
		foreach ( PresetCatalog::all() as $preset ) {
			if ( $preset['requires_woocommerce'] && ! $this->woocommerce_active() ) {
				continue;
			}
			$presets[] = $preset;
		}

		echo '<div id="ut-preset-cards" class="ut-preset-cards">';
		foreach ( $presets as $preset ) {
			echo '<div class="ut-preset-card" tabindex="0" role="button" data-preset-key="' . esc_attr( $preset['key'] ) . '">';
			echo '<strong>' . esc_html( $preset['title'] ) . '</strong>';
			echo '<p>' . esc_html( $preset['description'] ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		if ( $this->woocommerce_active() ) {
			echo '<p><a class="button" href="' . esc_url( $this->starter_set_review_url() ) . '">' . esc_html__( 'Store essentials starter set', 'universal-telegram' ) . '</a></p>';
		}

		echo '<p><button type="button" id="ut-custom-notification" class="button">' . esc_html__( 'Create a custom notification', 'universal-telegram' ) . '</button></p>';

		echo '<script type="application/json" id="ut-preset-data">' . wp_json_encode( array_values( $presets ) ) . '</script>';
	}

	/**
	 * Renders the Store-essentials two-step review screen (step one):
	 * lists all three draft rules and their full messages, and asks for a
	 * single bot/destination applied to all three. Nothing is created
	 * until the "Create draft rules" confirmation below is submitted
	 * (M08.1 plan "Fix the starter-set flow").
	 */
	private function render_starter_set_review(): void {
		echo '<h2>' . esc_html__( 'Store essentials starter set', 'universal-telegram' ) . '</h2>';
		echo '<p><a href="' . esc_url( $this->rules_tab_url() ) . '">&laquo; ' . esc_html__( 'Back to presets', 'universal-telegram' ) . '</a></p>';

		if ( ! $this->woocommerce_active() ) {
			echo '<p class="description">' . esc_html__( 'Requires WooCommerce, which is not currently active on this site.', 'universal-telegram' ) . '</p>';
			return;
		}

		if ( isset( $_GET['error'] ) && 'missing_destination' === $_GET['error'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display of a prior POST's own outcome, not a mutation.
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Choose a bot and destination before creating the draft rules.', 'universal-telegram' ) . '</p></div>';
		}

		echo '<p>' . esc_html__( 'This creates three disabled draft rules you can review and enable individually:', 'universal-telegram' ) . '</p>';
		echo '<ul>';
		foreach ( PresetCatalog::starter_set() as $preset ) {
			echo '<li><strong>' . esc_html( $preset['title'] ) . '</strong>: ' . esc_html( $preset['message'] ) . '</li>';
		}
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( RuleBuilderRequestHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( RuleBuilderRequestHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="create_starter_set" />';

		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label for="ut-starter-bot">' . esc_html__( 'Bot', 'universal-telegram' ) . '</label></th><td><select id="ut-starter-bot" name="bot_id"><option value="0">' . esc_html__( 'Choose a bot…', 'universal-telegram' ) . '</option>';
		foreach ( $this->bots->all() as $bot ) {
			printf( '<option value="%d">%s</option>', (int) $bot->id(), esc_html( $bot->name() ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="ut-starter-destination">' . esc_html__( 'Destination', 'universal-telegram' ) . '</label></th><td><select id="ut-starter-destination" name="destination_id"><option value="0">' . esc_html__( 'Choose a destination…', 'universal-telegram' ) . '</option>';
		foreach ( $this->bots->all() as $bot ) {
			foreach ( $this->eligible_destinations( $bot->id() ) as $destination ) {
				printf( '<option value="%d">%s</option>', (int) $destination->id(), esc_html( $bot->name() . ' / ' . $destination->label() ) );
			}
		}
		echo '</select></td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Create draft rules', 'universal-telegram' ) );
		echo '</form>';
	}

	/**
	 * The "Example notification preview" endpoint: a read-only render, not
	 * a mutation, so it responds with JSON rather than a redirect — still
	 * routed through the same admin-post.php dispatch, capability check,
	 * and nonce verification every other action here uses (M08.1 plan
	 * "Define the preview precisely"). Never touches a database, an HTTP
	 * client, or any real event/order/visitor/Telegram data — only
	 * PreviewRenderer's own fixed FieldTypeCatalog sample values.
	 */
	public function handle_preview_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_send_json_error( array(), 403 );
			return;
		}

		if ( ! check_ajax_referer( self::PREVIEW_NONCE_ACTION, '_wpnonce', false ) ) {
			wp_send_json_error( array(), 403 );
			return;
		}

		$event_type = isset( $_POST['event_type'] ) ? sanitize_text_field( wp_unslash( $_POST['event_type'] ) ) : '';
		$template   = isset( $_POST['template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['template'] ) ) : '';

		$preview = ( new PreviewRenderer( $this->registry ) )->render( $event_type, $template );

		wp_send_json_success( array( 'preview' => $preview ) );
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

		$current   = $this->settings->get();
		$sanitized = $this->settings->sanitize( array_merge( $current, $input ) );
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

		$values = null !== $this->intelligence_settings
			? array_merge( $this->settings->get(), array() )
			: $this->settings->get();

		// Prefer the typed reader when present so the property is not write-only.
		if ( null !== $this->intelligence_settings ) {
			$values['operational_summary_enabled']  = $this->intelligence_settings->operational_summary_enabled();
			$values['operational_summary_hour_utc'] = $this->intelligence_settings->operational_summary_hour_utc();
		}

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
		if ( null === $this->digest_eligibility ) {
			return;
		}

		( new BotDestinationPairFields( $this->bots, $this->digest_eligibility ) )->render(
			'intelligence_settings',
			$bot_field,
			$destination_field,
			$values
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
				'<td>%1$s</td><td>%2$s%3$s</td><td>%4$s</td><td>%5$s</td><td>%6$s</td>',
				esc_html( $rule->name() ),
				esc_html( $rule->event_type() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- digest_badge() returns escaped HTML or ''.
				$this->digest_badge( $rule->event_type() ),
				$rule->enabled() ? esc_html__( 'Yes', 'universal-telegram' ) : esc_html__( 'No', 'universal-telegram' ),
				esc_html( (string) $rule->priority() ),
				esc_html( (string) $rule->cooldown_seconds() )
			);
			echo '<td>';
			echo '<a class="button" href="' . esc_url( $this->rules_tab_url() . '&edit=' . $rule->id() ) . '">' . esc_html__( 'Edit', 'universal-telegram' ) . '</a> ';
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
	 * Whether WooCommerce is currently active. Treated as inactive when no
	 * WooCommerceSupport was supplied (pre-M08.1 test doubles).
	 *
	 * @return bool
	 */
	private function woocommerce_active(): bool {
		return null !== $this->woocommerce_support && $this->woocommerce_support->is_active();
	}

	/**
	 * Renders the create- or edit-rule form: the friendly, grouped event
	 * picker and the visual "Only when…" condition builder (M08.1). When
	 * $editing is unrepresentable (M08.1 plan "Existing-rule compatibility
	 * strategy"), the event type and conditions are locked and preserved
	 * byte-for-byte via hidden fields instead of the editable controls.
	 *
	 * @param array{id: int, name: string, event_type: string, representable: bool, conditions: array<int, array<string, mixed>>, match_mode: string, conditions_json: string, bot_id: int, destination_id: int, template: string, enabled: bool, priority: int, cooldown_seconds: int}|null $editing The rule being edited, or null when creating.
	 */
	private function render_rule_form( ?array $editing = null ): void {
		$locked = null !== $editing && ! $editing['representable'];

		echo '<h2>' . ( null === $editing ? esc_html__( 'Add rule', 'universal-telegram' ) : esc_html__( 'Edit rule', 'universal-telegram' ) ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( RuleBuilderRequestHandler::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( RuleBuilderRequestHandler::ADMIN_POST_ACTION ) . '" />';
		echo '<input type="hidden" name="op" value="save_rule" />';
		if ( null !== $editing ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( (string) $editing['id'] ) . '" />';
		}

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ut-rule-name">' . esc_html__( 'Name', 'universal-telegram' ) . '</label></th><td><input type="text" id="ut-rule-name" name="name" class="regular-text" value="' . esc_attr( $editing['name'] ?? '' ) . '" /></td></tr>';

		echo '</tbody></table>';

		echo '<h3>' . esc_html__( '1. When this happens', 'universal-telegram' ) . '</h3>';
		$this->render_event_picker( $editing['event_type'] ?? '', $locked );

		echo '<h3>' . esc_html__( '2. Only when…', 'universal-telegram' ) . '</h3>';
		if ( $locked ) {
			$this->render_locked_conditions_notice( $editing );
		} else {
			$this->render_condition_builder(
				$editing['event_type'] ?? '',
				$editing['conditions'] ?? array(),
				$editing['match_mode'] ?? 'all'
			);
		}

		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="ut-rule-bot">' . esc_html__( 'Bot', 'universal-telegram' ) . '</label></th><td><select id="ut-rule-bot" name="bot_id">';
		foreach ( $this->bots->all() as $bot ) {
			printf( '<option value="%d" %s>%s</option>', (int) $bot->id(), selected( $editing['bot_id'] ?? 0, $bot->id(), false ), esc_html( $bot->name() ) );
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="ut-rule-destination">' . esc_html__( 'Destination', 'universal-telegram' ) . '</label></th><td><select id="ut-rule-destination" name="destination_id">';
		foreach ( $this->bots->all() as $bot ) {
			foreach ( $this->eligible_destinations( $bot->id() ) as $destination ) {
				printf( '<option value="%d" %s>%s</option>', (int) $destination->id(), selected( $editing['destination_id'] ?? 0, $destination->id(), false ), esc_html( $bot->name() . ' / ' . $destination->label() ) );
			}
		}
		echo '</select></td></tr>';

		echo '<tr><th><label for="ut-rule-template">' . esc_html__( 'Message', 'universal-telegram' ) . '</label></th><td>';
		echo '<p><label for="ut-insert-field" class="screen-reader-text">' . esc_html__( 'Insert field', 'universal-telegram' ) . '</label>';
		echo '<select id="ut-insert-field"><option value="">' . esc_html__( 'Insert field…', 'universal-telegram' ) . '</option></select> ';
		echo '<button type="button" id="ut-insert-field-button" class="button">' . esc_html__( 'Insert', 'universal-telegram' ) . '</button></p>';
		echo '<textarea id="ut-rule-template" name="template" class="large-text" rows="3">' . esc_textarea( $editing['template'] ?? '' ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'The final message uses the real event information when it is sent.', 'universal-telegram' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Example notification preview', 'universal-telegram' ) . '</strong></p>';
		echo '<p id="ut-message-preview" class="ut-message-preview"></p>';
		echo '</td></tr>';

		echo '<tr><th><label for="ut-rule-priority">' . esc_html__( 'Priority', 'universal-telegram' ) . '</label></th><td><input type="number" id="ut-rule-priority" name="priority" value="' . esc_attr( (string) ( $editing['priority'] ?? 100 ) ) . '" /></td></tr>';

		echo '<tr><th><label for="ut-rule-cooldown">' . esc_html__( 'Cooldown seconds', 'universal-telegram' ) . '</label></th><td><input type="number" id="ut-rule-cooldown" name="cooldown_seconds" value="' . esc_attr( (string) ( $editing['cooldown_seconds'] ?? 0 ) ) . '" min="0" /></td></tr>';

		$enabled_checked = null === $editing ? true : $editing['enabled'];
		echo '<tr><th><label for="ut-rule-enabled">' . esc_html__( 'Enabled', 'universal-telegram' ) . '</label></th><td><input type="checkbox" id="ut-rule-enabled" name="enabled" value="1" ' . checked( $enabled_checked, true, false ) . ' /></td></tr>';

		echo '</tbody></table>';

		submit_button( null === $editing ? __( 'Save rule', 'universal-telegram' ) : __( 'Save changes', 'universal-telegram' ) );
		echo '</form>';

		$this->render_builder_script();
	}

	/**
	 * Renders the read-only compatibility notice for a rule whose
	 * conditions the visual builder cannot represent, and the hidden
	 * fields that resubmit those exact conditions/match_mode unchanged —
	 * never an editable JSON field in the normal admin UI (M08.1 plan
	 * "Existing-rule compatibility strategy").
	 *
	 * @param array{conditions_json: string, match_mode: string} $editing The rule being edited.
	 */
	private function render_locked_conditions_notice( array $editing ): void {
		echo '<p class="description">' . esc_html__(
			'This rule\'s conditions were created with a format the visual builder cannot display; they still apply exactly as saved.',
			'universal-telegram'
		) . '</p>';
		echo '<details><summary>' . esc_html__( 'Technical details', 'universal-telegram' ) . '</summary><pre>' . esc_html( $editing['conditions_json'] ) . '</pre></details>';

		echo '<input type="hidden" name="conditions_locked" value="1" />';
		echo '<input type="hidden" name="conditions_preserved_json" value="' . esc_attr( $editing['conditions_json'] ) . '" />';
		echo '<input type="hidden" name="match_mode" value="' . esc_attr( $editing['match_mode'] ) . '" />';
	}

	/**
	 * The destinations eligible for one bot: reuses DigestEligibility's own
	 * "enabled, never conversation-linked" rule (M08.1 plan §4 "reuse
	 * existing eligibility and destination safety rules") when available,
	 * falling back to an enabled-only filter for pre-M11A callers.
	 *
	 * @param int $bot_id The bot's primary key.
	 *
	 * @return array<int, \UniversalTelegram\Telegram\Configuration\Destination>
	 */
	private function eligible_destinations( int $bot_id ): array {
		if ( null !== $this->digest_eligibility ) {
			return $this->digest_eligibility->eligible_destinations_for_bot( $bot_id );
		}

		return array_values( array_filter( $this->destinations->for_bot( $bot_id ), static fn( $destination ) => $destination->enabled() ) );
	}

	/**
	 * Renders the family-grouped, friendly event-type picker. A
	 * WooCommerce-only family is disabled with a clear explanation when
	 * WooCommerce is inactive, rather than omitted (task requirement — an
	 * admin should understand why options are missing).
	 */
	private function render_event_picker( string $selected_event_type = '', bool $locked = false ): void {
		$woocommerce_active = $this->woocommerce_active();

		echo '<p><label for="ut-rule-event-type" class="screen-reader-text">' . esc_html__( 'Event type', 'universal-telegram' ) . '</label>';
		echo '<select id="ut-rule-event-type" name="' . ( $locked ? '' : 'event_type' ) . '"' . ( $locked ? ' disabled="disabled"' : '' ) . '>';

		foreach ( self::EVENT_FAMILIES as $family ) {
			$family_disabled = $family['requires_woocommerce'] && ! $woocommerce_active;

			printf(
				'<optgroup label="%s"%s>',
				esc_attr( $family['label'] ),
				$family_disabled ? ' disabled="disabled"' : ''
			);

			foreach ( $family['event_types'] as $event_type ) {
				if ( ! $this->registry->is_registered( $event_type ) ) {
					continue;
				}

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

		echo '</select>';
		if ( $locked ) {
			echo '<input type="hidden" name="event_type" value="' . esc_attr( $selected_event_type ) . '" />';
		}
		echo '</p>';

		foreach ( self::EVENT_FAMILIES as $family ) {
			if ( $family['requires_woocommerce'] && ! $woocommerce_active ) {
				printf(
					'<p class="description">%s: %s</p>',
					esc_html( $family['label'] ),
					esc_html__( 'Requires WooCommerce, which is not currently active on this site.', 'universal-telegram' )
				);
			}
		}

		if ( null !== $this->digest_eligibility && $this->digest_eligibility->is_active() ) {
			echo '<p class="description">' . esc_html__(
				'Visitor Digest is currently enabled and active: page view, navigation, search, product view, and cart/checkout-intent event types will not send individually while that remains the case.',
				'universal-telegram'
			) . '</p>';
		}
	}

	/**
	 * Renders the visual "Only when…" condition builder. Hidden by default
	 * (zero-state — no rows, no all/any control) until "Add a condition" is
	 * clicked, per the frozen plan's progressive-disclosure requirement —
	 * unless $conditions is non-empty (editing a representable rule that
	 * already has conditions), in which case the existing rows render
	 * server-side and visible immediately, so an admin without JavaScript
	 * still sees their rule's own conditions.
	 *
	 * @param string                           $event_type The rule's own event type, for row field options.
	 * @param array<int, array<string, mixed>> $conditions Existing clauses to pre-render, or empty for a blank builder.
	 * @param string                           $match_mode 'all' or 'any'.
	 */
	private function render_condition_builder( string $event_type = '', array $conditions = array(), string $match_mode = 'all' ): void {
		echo '<div id="ut-conditions-wrap"' . ( array() === $conditions ? ' style="display:none"' : '' ) . '>';

		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'How conditions combine', 'universal-telegram' ) . '</legend>';
		echo '<label><input type="radio" name="match_mode" value="all" ' . checked( $match_mode, 'all', false ) . ' /> ' . esc_html__( 'All conditions must match', 'universal-telegram' ) . '</label> ';
		echo '<label><input type="radio" name="match_mode" value="any" ' . checked( $match_mode, 'any', false ) . ' /> ' . esc_html__( 'Any condition may match', 'universal-telegram' ) . '</label>';
		echo '</fieldset>';

		echo '<div id="ut-condition-rows">';
		foreach ( $conditions as $index => $clause ) {
			ConditionRowRenderer::render( $index, $event_type, $this->registry, $clause );
		}
		echo '</div>';

		echo '</div>';

		echo '<p><button type="button" id="ut-add-condition" class="button">' . esc_html__( '+ Add a condition', 'universal-telegram' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'A rule with no conditions notifies every time its event happens.', 'universal-telegram' ) . '</p>';

		echo '<div id="ut-condition-row-template" style="display:none">';
		ConditionRowRenderer::render( 0, '', $this->registry );
		echo '</div>';
	}

	/**
	 * Embeds the per-event-type friendly field/operator/choice metadata as
	 * JSON and the small vanilla-JS behavior for: the event picker and
	 * condition builder (row add/remove, rebuilding a row's own
	 * operator/value controls when its field or the event type changes);
	 * the message field-insert menu (inserting the literal `{{field.path}}`
	 * token at the textarea cursor); and the "Example notification
	 * preview" (debounced fetch to handle_preview_request(), rendered via
	 * textContent only — never innerHTML). No build pipeline, no
	 * framework — plain DOM script, matching the frozen plan's "no
	 * SPA/React/Vue" requirement.
	 */
	private function render_builder_script(): void {
		$metadata = array();
		foreach ( $this->registry->all() as $entry ) {
			$metadata[ $entry['event_type'] ] = ConditionRowRenderer::field_metadata_for_event( $entry['event_type'], $this->registry );
		}

		echo '<script type="application/json" id="ut-condition-field-metadata">' . wp_json_encode( $metadata ) . '</script>';

		$preview_config = array(
			'ajaxUrl' => admin_url( 'admin-post.php' ),
			'action'  => self::PREVIEW_ADMIN_POST_ACTION,
			'nonce'   => wp_create_nonce( self::PREVIEW_NONCE_ACTION ),
		);
		echo '<script type="application/json" id="ut-preview-config">' . wp_json_encode( $preview_config ) . '</script>';
		?>
		<script>
		( function () {
			var metadata = JSON.parse( document.getElementById( 'ut-condition-field-metadata' ).textContent || '{}' );
			var previewConfig = JSON.parse( document.getElementById( 'ut-preview-config' ).textContent || '{}' );
			var eventSelect = document.getElementById( 'ut-rule-event-type' );
			var wrap = document.getElementById( 'ut-conditions-wrap' );
			var rows = document.getElementById( 'ut-condition-rows' );
			var template = document.getElementById( 'ut-condition-row-template' );
			var addButton = document.getElementById( 'ut-add-condition' );
			var templateTextarea = document.getElementById( 'ut-rule-template' );
			var insertFieldSelect = document.getElementById( 'ut-insert-field' );
			var insertFieldButton = document.getElementById( 'ut-insert-field-button' );
			var previewEl = document.getElementById( 'ut-message-preview' );
			var rowIndex = rows.querySelectorAll( '.ut-condition-row' ).length;
			var previewTimer = null;

			function optionsHtml( items, selectedValue ) {
				var html = '';
				for ( var i = 0; i < items.length; i++ ) {
					var selected = items[ i ].value === selectedValue ? ' selected' : '';
					html += '<option value="' + items[ i ].value + '"' + selected + '>' + items[ i ].label + '</option>';
				}
				return html;
			}

			function fieldOptionsHtml( eventType ) {
				var fields = metadata[ eventType ] || {};
				var html = '';
				for ( var field in fields ) {
					if ( Object.prototype.hasOwnProperty.call( fields, field ) ) {
						html += '<option value="' + field + '">' + fields[ field ].label + '</option>';
					}
				}
				return html;
			}

			function rebuildOperatorAndValue( row ) {
				var eventType = eventSelect.value;
				var fieldSelect = row.querySelector( '.ut-condition-field' );
				var operatorSelect = row.querySelector( '.ut-condition-operator' );
				var valueContainer = row.querySelector( '.ut-condition-value' );
				var fields = metadata[ eventType ] || {};
				var field = fields[ fieldSelect.value ];

				if ( ! field ) {
					return;
				}

				operatorSelect.innerHTML = optionsHtml( field.operators, '' );

				var name = valueContainer.getAttribute( 'name' );
				var newValueEl;

				if ( field.type === 'choice' || field.type === 'boolean' ) {
					newValueEl = document.createElement( 'select' );
					newValueEl.innerHTML = optionsHtml( field.choice_options || [], '' );
				} else {
					newValueEl = document.createElement( 'input' );
					newValueEl.type = ( field.type === 'number' || field.type === 'money' ) ? 'number' : 'text';
					if ( field.type === 'money' ) {
						newValueEl.step = '0.01';
					}
				}

				newValueEl.className = 'ut-condition-value regular-text';
				newValueEl.setAttribute( 'name', name );
				valueContainer.parentNode.replaceChild( newValueEl, valueContainer );
			}

			function rebuildFieldOptions( row ) {
				var eventType = eventSelect.value;
				var fieldSelect = row.querySelector( '.ut-condition-field' );
				fieldSelect.innerHTML = fieldOptionsHtml( eventType );
				rebuildOperatorAndValue( row );
			}

			function addRow( clause ) {
				var row = template.firstElementChild.cloneNode( true );
				var html = row.outerHTML.replace( /conditions\[0\]/g, 'conditions[' + rowIndex + ']' );
				var wrapperDiv = document.createElement( 'div' );
				wrapperDiv.innerHTML = html;
				row = wrapperDiv.firstElementChild;
				rowIndex++;

				row.querySelector( '.ut-remove-condition' ).addEventListener( 'click', function () {
					row.parentNode.removeChild( row );
					if ( ! rows.children.length ) {
						wrap.style.display = 'none';
					}
				} );

				row.querySelector( '.ut-condition-field' ).addEventListener( 'change', function () {
					rebuildOperatorAndValue( row );
				} );

				rows.appendChild( row );
				rebuildFieldOptions( row );
				wrap.style.display = '';

				if ( clause ) {
					row.querySelector( '.ut-condition-field' ).value = clause.field;
					rebuildOperatorAndValue( row );
					row.querySelector( '.ut-condition-operator' ).value = clause.operator;
					row.querySelector( '.ut-condition-value' ).value = clause.value;
				}

				return row;
			}

			addButton.addEventListener( 'click', function () { addRow( null ); } );

			function wireExistingRow( row ) {
				row.querySelector( '.ut-remove-condition' ).addEventListener( 'click', function () {
					row.parentNode.removeChild( row );
					if ( ! rows.children.length ) {
						wrap.style.display = 'none';
					}
				} );

				row.querySelector( '.ut-condition-field' ).addEventListener( 'change', function () {
					rebuildOperatorAndValue( row );
				} );
			}

			var existingServerRows = rows.querySelectorAll( '.ut-condition-row' );
			for ( var s = 0; s < existingServerRows.length; s++ ) {
				wireExistingRow( existingServerRows[ s ] );
			}

			function rebuildInsertFieldOptions() {
				var eventType = eventSelect.value;
				insertFieldSelect.innerHTML = '<option value="">' + insertFieldSelect.options[ 0 ].text + '</option>' + fieldOptionsHtml( eventType );
			}

			insertFieldButton.addEventListener( 'click', function () {
				var token = insertFieldSelect.value;
				if ( ! token ) {
					return;
				}

				var placeholder = '{{' + token + '}}';
				var start = templateTextarea.selectionStart || 0;
				var end = templateTextarea.selectionEnd || 0;
				var value = templateTextarea.value;

				templateTextarea.value = value.slice( 0, start ) + placeholder + value.slice( end );
				templateTextarea.focus();
				templateTextarea.selectionStart = templateTextarea.selectionEnd = start + placeholder.length;

				schedulePreview();
			} );

			function fetchPreview() {
				if ( ! previewConfig.ajaxUrl ) {
					return;
				}

				var body = new URLSearchParams();
				body.set( 'action', previewConfig.action );
				body.set( '_wpnonce', previewConfig.nonce );
				body.set( 'event_type', eventSelect.value );
				body.set( 'template', templateTextarea.value );

				fetch( previewConfig.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( json ) {
						previewEl.textContent = ( json && json.success && json.data ) ? json.data.preview : '';
					} )
					.catch( function () {
						previewEl.textContent = '';
					} );
			}

			function schedulePreview() {
				if ( previewTimer ) {
					clearTimeout( previewTimer );
				}
				previewTimer = setTimeout( fetchPreview, 400 );
			}

			templateTextarea.addEventListener( 'input', schedulePreview );

			eventSelect.addEventListener( 'change', function () {
				var existingRows = rows.querySelectorAll( '.ut-condition-row' );
				for ( var i = 0; i < existingRows.length; i++ ) {
					rebuildFieldOptions( existingRows[ i ] );
				}
				rebuildInsertFieldOptions();
				schedulePreview();
			} );

			rebuildInsertFieldOptions();
			schedulePreview();

			var presetDataEl = document.getElementById( 'ut-preset-data' );
			var presets = presetDataEl ? JSON.parse( presetDataEl.textContent || '[]' ) : [];
			var customButton = document.getElementById( 'ut-custom-notification' );
			var nameInput = document.getElementById( 'ut-rule-name' );

			function clearConditionRows() {
				while ( rows.firstChild ) {
					rows.removeChild( rows.firstChild );
				}
				wrap.style.display = 'none';
			}

			function applyFields( data ) {
				eventSelect.value = data.event_type;
				eventSelect.dispatchEvent( new Event( 'change' ) );

				clearConditionRows();
				for ( var i = 0; i < data.conditions.length; i++ ) {
					addRow( data.conditions[ i ] );
				}

				var matchModeInputs = document.getElementsByName( 'match_mode' );
				for ( var m = 0; m < matchModeInputs.length; m++ ) {
					matchModeInputs[ m ].checked = ( matchModeInputs[ m ].value === data.match_mode );
				}

				if ( typeof data.message !== 'undefined' ) {
					templateTextarea.value = data.message;
				}

				schedulePreview();
			}

			function applyPreset( preset ) {
				applyFields( preset );
				nameInput.value = preset.title;
				nameInput.focus();
			}

			var presetCards = document.querySelectorAll( '.ut-preset-card' );
			for ( var p = 0; p < presetCards.length; p++ ) {
				presetCards[ p ].addEventListener( 'click', function () {
					var key = this.getAttribute( 'data-preset-key' );
					for ( var j = 0; j < presets.length; j++ ) {
						if ( presets[ j ].key === key ) {
							applyPreset( presets[ j ] );
							break;
						}
					}
				} );
				presetCards[ p ].addEventListener( 'keydown', function ( event ) {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						this.click();
					}
				} );
			}

			if ( customButton ) {
				customButton.addEventListener( 'click', function () {
					nameInput.value = '';
					templateTextarea.value = '';
					clearConditionRows();
					nameInput.focus();
				} );
			}
		} )();
		</script>
		<?php
	}
}
