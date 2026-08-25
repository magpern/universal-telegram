<?php
/**
 * Hub diagnostics tab for Support Chat adapter status and settings.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\SupportChatAdapter\Diagnostics;

use UniversalTelegram\Administration\Shared\BotDestinationPairFields;
use UniversalTelegram\Automations\Digest\DigestEligibility;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Core\Configuration\Settings;
use UniversalTelegram\SupportChatAdapter\AdapterAvailability;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\ContractConstants;
use UniversalTelegram\SupportChatAdapter\DiscoveryClient;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;

/**
 * Adapter status plus enable/bot/destination settings for administrators.
 */
final class AdapterStatusPage {

	public const TAB_ID       = 'support-chat-adapter';
	public const SAVE_ACTION  = 'universal_telegram_support_chat_adapter_save';
	public const NONCE_ACTION = 'universal_telegram_support_chat_adapter_save';

	/**
	 * Constructor.
	 *
	 * @param Settings                 $settings           Plugin settings.
	 * @param DiscoveryClient          $discovery          Discovery client.
	 * @param ChannelBindingRepository $bindings           Binding repository.
	 * @param BotProfileRepository     $bots               Bot listing for dropdowns.
	 * @param DigestEligibility        $digest_eligibility Eligible parent destinations.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly DiscoveryClient $discovery,
		private readonly ChannelBindingRepository $bindings,
		private readonly BotProfileRepository $bots,
		private readonly DigestEligibility $digest_eligibility
	) {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/**
	 * Persists adapter settings from the Hub tab form.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			wp_die( esc_html__( 'Forbidden', 'universal-telegram' ), 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$posted = isset( $_POST['adapter_settings'] ) && is_array( $_POST['adapter_settings'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			? wp_unslash( $_POST['adapter_settings'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via Settings::sanitize().
			: array();

		$current = $this->settings->get();
		$input   = array(
			'support_chat_adapter_enabled'        => isset( $_POST['support_chat_adapter_enabled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
			'support_chat_adapter_bot_id'         => isset( $posted['support_chat_adapter_bot_id'] ) ? (string) $posted['support_chat_adapter_bot_id'] : '',
			'support_chat_adapter_destination_id' => isset( $posted['support_chat_adapter_destination_id'] ) ? (string) $posted['support_chat_adapter_destination_id'] : '',
		);

		$merged = array_merge( $current, $input );
		update_option( Settings::OPTION_NAME, $this->settings->sanitize( $merged ) );

		$redirect = add_query_arg(
			array(
				'page'    => 'universal-telegram',
				'tab'     => self::TAB_ID,
				'updated' => '1',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Renders Hub tab content.
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE ) ) {
			return;
		}

		$values  = $this->settings->get();
		$enabled = ! empty( $values['support_chat_adapter_enabled'] );
		$state   = $this->discovery->resolve( $enabled );
		$counts  = $this->bindings->count_by_status();

		echo '<div class="universal-telegram-support-chat-adapter-status">';
		echo '<h2>' . esc_html__( 'Support Chat adapter', 'universal-telegram' ) . '</h2>';
		echo '<p>' . esc_html__( 'Optional Telegram channel adapter for Universal Support Chat. Failures affect this channel only.', 'universal-telegram' ) . '</p>';

		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Adapter settings saved.', 'universal-telegram' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::SAVE_ACTION ) . '" />';
		wp_nonce_field( self::NONCE_ACTION );

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Enable adapter', 'universal-telegram' ) . '</th><td><label><input type="checkbox" name="support_chat_adapter_enabled" value="1" ' . checked( $enabled, true, false ) . ' /> ' . esc_html__( 'Accept Support Chat Contract calls when discovery is compatible', 'universal-telegram' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Telegram target', 'universal-telegram' ) . '</th><td>';
		( new BotDestinationPairFields( $this->bots, $this->digest_eligibility ) )->render(
			'adapter_settings',
			'support_chat_adapter_bot_id',
			'support_chat_adapter_destination_id',
			$values
		);
		echo '<p class="description">' . esc_html__( 'Forum/supergroup destination used when ensuring a new channel case topic.', 'universal-telegram' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';

		submit_button( __( 'Save adapter settings', 'universal-telegram' ) );
		echo '</form>';

		echo '<table class="widefat striped" style="max-width:40rem"><tbody>';
		echo '<tr><th>' . esc_html__( 'Availability', 'universal-telegram' ) . '</th><td>' . esc_html( $state->value ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Contract version', 'universal-telegram' ) . '</th><td><code>' . esc_html( ContractConstants::CONTRACT_VERSION_ID ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Contract pin SHA', 'universal-telegram' ) . '</th><td><code>' . esc_html( ContractConstants::CONTRACT_PIN_SHA ) . '</code></td></tr>';
		echo '<tr><th>' . esc_html__( 'Active bindings', 'universal-telegram' ) . '</th><td>' . esc_html( (string) $counts['active'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Unavailable bindings', 'universal-telegram' ) . '</th><td>' . esc_html( (string) $counts['unavailable'] ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Closed bindings', 'universal-telegram' ) . '</th><td>' . esc_html( (string) $counts['closed'] ) . '</td></tr>';
		echo '</tbody></table>';

		if ( $enabled && AdapterAvailability::Compatible !== $state ) {
			echo '<div class="notice notice-info inline"><p>';
			echo esc_html__(
				'Universal Telegram is installed and can be configured here, but the Support Chat channel remains Unavailable until Support Chat advertises an available Contract v1 with the Adapter M1 capability set. Current Support Chat (SC-M02) discovery is inert (channel_available=false). Operational Contract exchange waits for SC-M03 authenticated, capability-advertising Contract server.',
				'universal-telegram'
			);
			echo '</p></div>';
		}

		echo '<p><a href="' . esc_url( ContractConstants::CONTRACT_PIN_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open pinned Contract v1', 'universal-telegram' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'SC-M03 binding import: wp universal-telegram support-chat-bindings import [--dry-run|--apply]', 'universal-telegram' ) . '</p>';
		echo '</div>';
	}
}
