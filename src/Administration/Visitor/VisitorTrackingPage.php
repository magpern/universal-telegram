<?php
/**
 * Visitor tracking settings admin page.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Visitor;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;

/**
 * Gated on the existing CapabilityRegistrar::MANAGE capability (ADR-0010)
 * — no new capability constant is introduced (M04 plan §6). Every action
 * independently re-verifies both the capability and its own nonce, never
 * relying solely on menu-registration-time gating, mirroring
 * RuleBuilderRequestHandler's exact existing pattern.
 *
 * Not declared final: tests override redirect_and_exit() to avoid a real
 * exit call terminating the test process, matching
 * RuleBuilderRequestHandler's exact existing precedent.
 */
class VisitorTrackingPage {

	public const SLUG              = 'universal-telegram-visitor-tracking';
	public const TAB_ID            = 'visitor-tracking';
	public const ADMIN_POST_ACTION = 'universal_telegram_visitor_tracking_save';
	public const NONCE_ACTION      = 'universal_telegram_visitor_tracking_save';

	/**
	 * Every boolean field this page's form submits, mirrored against
	 * Settings::sanitize()'s own $boolean_fields list for the
	 * visitor_* subset.
	 *
	 * @var string[]
	 */
	private const BOOLEAN_FIELDS = array(
		'visitor_tracking_enabled',
		'visitor_family_page_views',
		'visitor_family_navigation',
		'visitor_family_search',
		'visitor_family_errors',
		'visitor_family_commerce',
		'visitor_exclude_administrators',
		'visitor_digest_enabled',
	);

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings           Reads/writes the current visitor tracking configuration.
	 * @param BotProfileRepository $bots               Populates the digest bot dropdown.
	 * @param DigestEligibility    $digest_eligibility Populates the eligible-destination dropdown and invalidates its cache on save.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly BotProfileRepository $bots,
		private readonly DigestEligibility $digest_eligibility
	) {}

	/**
	 * The admin-post save handler.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$input = isset( $_POST['visitor_settings'] ) && is_array( $_POST['visitor_settings'] )
			? wp_unslash( $_POST['visitor_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();

		// Unchecked checkboxes are omitted from $_POST entirely, so their
		// absence must be treated as an explicit false here — otherwise the
		// array_merge below would fall back to the old stored value and an
		// unchecked box could never actually be saved as off (matches
		// SettingsPage's exact existing pattern for the same problem).
		foreach ( self::BOOLEAN_FIELDS as $field ) {
			$input[ $field ] = isset( $input[ $field ] );
		}

		// Developer-only click tracking is not administrator-configurable
		// (bug-fix authorization, corrective removal): any click-related
		// keys a crafted request might still submit are dropped here so
		// they never reach Settings::sanitize(), which independently
		// ignores them anyway.
		unset( $input['visitor_family_clicks'], $input['visitor_click_target_allowlist'] );

		$sanitized = $this->settings->sanitize( array_merge( $this->settings->get(), $input ) );
		update_option( Settings::OPTION_NAME, $sanitized );

		// The five visitor_digest_* fields above just changed; the shared
		// DigestEligibility::is_active() gate (RuleEvaluator suppression and
		// the counter increment both consult it) must reflect this save
		// immediately, not after its own transient's TTL expires
		// (docs/plans/m11a-visitor-activity-digests-plan-v1.md §3.1).
		$this->digest_eligibility->invalidate();

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . self::TAB_ID ) );
	}

	/**
	 * Renders this tab's content only (no outer .wrap/<h1> — owned by
	 * HubPage).
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$values = $this->settings->get();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';

		$this->checkbox( $values, 'visitor_tracking_enabled', __( 'Enable visitor tracking', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_page_views', __( 'Page views', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_navigation', __( 'Navigation', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_search', __( 'Search', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_errors', __( 'JavaScript errors', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_family_commerce', __( 'WooCommerce (product views, classic add-to-cart, checkout entry)', 'universal-telegram' ) );
		$this->checkbox( $values, 'visitor_exclude_administrators', __( 'Exclude administrators', 'universal-telegram' ) );

		echo '<p><label>' . esc_html__( 'Consent mode', 'universal-telegram' ) . ' ';
		echo '<select name="visitor_settings[visitor_consent_mode]">';
		foreach ( array( 'required', 'disabled' ) as $mode ) {
			printf(
				'<option value="%1$s" %2$s>%1$s</option>',
				esc_attr( $mode ),
				selected( $values['visitor_consent_mode'], $mode, false )
			);
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__(
			"This checks a signal from your consent tool before collecting data in the visitor's browser. It cannot be verified by the server and is not a substitute for your own compliance review.",
			'universal-telegram'
		) . '</p>';

		echo '<p><label>' . esc_html__( 'Sampling percent', 'universal-telegram' ) . ' ';
		echo '<input type="number" min="1" max="100" name="visitor_settings[visitor_sampling_percent]" value="' . esc_attr( (string) $values['visitor_sampling_percent'] ) . '" />';
		echo '</label></p>';
		echo '<p class="description">' . esc_html__(
			'The percentage of visitor sessions to record, chosen once per session. 100 records every session; a lower value reduces stored event volume on high-traffic sites but means some sessions are not tracked at all.',
			'universal-telegram'
		) . '</p>';

		$this->render_digest_fieldset( $values );

		submit_button();
		echo '</form>';
	}

	/**
	 * Renders one boolean settings checkbox.
	 *
	 * @param array<string, mixed> $values Current settings values.
	 * @param string               $field  The settings field name.
	 * @param string               $label  The visible label.
	 */
	private function checkbox( array $values, string $field, string $label ): void {
		printf(
			'<p><label><input type="checkbox" name="visitor_settings[%1$s]" value="1" %2$s /> %3$s</label></p>',
			esc_attr( $field ),
			checked( ! empty( $values[ $field ] ), true, false ),
			esc_html( $label )
		);
	}

	/**
	 * Renders the Visitor Digest fieldset: enable checkbox, bot dropdown,
	 * destination dropdown scoped to that bot and filtered to
	 * DigestEligibility's own eligibility rule (never lists a
	 * conversation-linked destination — §4), and the threshold/max-wait
	 * numeric fields.
	 *
	 * @param array<string, mixed> $values Current settings values.
	 */
	private function render_digest_fieldset( array $values ): void {
		echo '<h3>' . esc_html__( 'Visitor Activity Digest', 'universal-telegram' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'When enabled, routine page-view, navigation, search, and cart-intent activity is batched into a periodic aggregate summary instead of one Telegram message per event. Existing notification rules for these event types stop sending individually while this is enabled with a valid target below; if the target becomes invalid, they resume automatically.',
			'universal-telegram'
		) . '</p>';

		$this->checkbox( $values, 'visitor_digest_enabled', __( 'Enable Visitor Activity Digest', 'universal-telegram' ) );

		$selected_bot_id = null === $values['visitor_digest_bot_id'] ? 0 : (int) $values['visitor_digest_bot_id'];

		echo '<p><label>' . esc_html__( 'Bot', 'universal-telegram' ) . ' ';
		echo '<select name="visitor_settings[visitor_digest_bot_id]">';
		echo '<option value="">' . esc_html__( '— Select a bot —', 'universal-telegram' ) . '</option>';
		foreach ( $this->bots->all() as $bot ) {
			if ( BotStatus::ACTIVE !== $bot->status() ) {
				continue;
			}
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $bot->id() ),
				selected( $selected_bot_id, $bot->id(), false ),
				esc_html( $bot->name() )
			);
		}
		echo '</select></label></p>';

		$selected_destination_id = null === $values['visitor_digest_destination_id'] ? 0 : (int) $values['visitor_digest_destination_id'];
		$eligible_destinations   = $selected_bot_id > 0 ? $this->digest_eligibility->eligible_destinations_for_bot( $selected_bot_id ) : array();

		echo '<p><label>' . esc_html__( 'Destination', 'universal-telegram' ) . ' ';
		echo '<select name="visitor_settings[visitor_digest_destination_id]">';
		echo '<option value="">' . esc_html__( '— Select a destination —', 'universal-telegram' ) . '</option>';
		foreach ( $eligible_destinations as $destination ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $destination->id() ),
				selected( $selected_destination_id, $destination->id(), false ),
				esc_html( $destination->label() )
			);
		}
		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__(
			'Only enabled, manually configured destinations belonging to the selected bot appear here — a destination created automatically for a website chat conversation can never be selected.',
			'universal-telegram'
		) . '</p>';

		echo '<p><label>' . esc_html__( 'Event threshold', 'universal-telegram' ) . ' ';
		echo '<input type="number" min="10" max="500" name="visitor_settings[visitor_digest_threshold]" value="' . esc_attr( (string) $values['visitor_digest_threshold'] ) . '" />';
		echo '</label></p>';

		echo '<p><label>' . esc_html__( 'Maximum wait (minutes)', 'universal-telegram' ) . ' ';
		echo '<input type="number" min="5" max="60" name="visitor_settings[visitor_digest_max_wait_minutes]" value="' . esc_attr( (string) $values['visitor_digest_max_wait_minutes'] ) . '" />';
		echo '</label></p>';
		echo '<p class="description">' . esc_html__(
			'A digest sends as soon as either the event threshold or the maximum wait is reached.',
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
}
