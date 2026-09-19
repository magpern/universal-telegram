<?php
/**
 * Support-ticket digest settings section.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Automations;

use DateTimeImmutable;
use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Administration\Shared\BotDestinationPairFields;
use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\DigestSchedule;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\TicketDigestJob;
use UniversalTelegram\Integrations\FluentContactInbox\Digest\TicketDigestSettings;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\DestinationEligibility;

/**
 * A section of the Notifications & activity area, present only while the
 * support desk is active (docs/adr/0046). Saves through the digest job so
 * the pending occurrence is replaced deterministically.
 */
final class SupportDigestSettingsPage {

	public const SECTION_ID        = 'support-digest';
	public const ADMIN_POST_ACTION = 'universal_telegram_save_support_digest';
	public const NONCE_ACTION      = 'universal_telegram_save_support_digest';

	/**
	 * Constructor.
	 *
	 * @param TicketDigestSettings   $settings    Digest settings.
	 * @param TicketDigestJob        $job         Reschedules on save.
	 * @param BotProfileRepository   $bots        Bot listing.
	 * @param DestinationEligibility $eligibility Shared destination-eligibility rule.
	 */
	public function __construct(
		private readonly TicketDigestSettings $settings,
		private readonly TicketDigestJob $job,
		private readonly BotProfileRepository $bots,
		private readonly DestinationEligibility $eligibility
	) {}

	/**
	 * Renders this section's content.
	 */
	public function render_tab_content(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'universal-telegram' ) );
		}

		$values = $this->settings->get();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag set by our own redirect.
		if ( isset( $_GET['digest_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Support digest settings saved.', 'universal-telegram' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Support desk digest', 'universal-telegram' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A recurring Telegram summary of support tickets: how many are unanswered (open), how many await the customer (pending), and the oldest unanswered tickets. Times use the site timezone; weekly digests are sent on Mondays.', 'universal-telegram' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ADMIN_POST_ACTION ) . '" />';

		echo '<p><label><input type="checkbox" name="digest_settings[enabled]" value="1" ' . checked( $values['enabled'], true, false ) . ' /> ' . esc_html__( 'Send the digest', 'universal-telegram' ) . '</label></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe attribute fragment.

		( new BotDestinationPairFields( $this->bots, $this->eligibility ) )->render( 'digest_settings', 'bot_id', 'destination_id', $values );

		echo '<p><label>' . esc_html__( 'How often', 'universal-telegram' ) . ' ';
		echo '<select name="digest_settings[interval]">';
		printf( '<option value="daily" %s>%s</option>', selected( $values['interval'], 'daily', false ), esc_html__( 'Daily', 'universal-telegram' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute fragment.
		printf( '<option value="weekly" %s>%s</option>', selected( $values['interval'], 'weekly', false ), esc_html__( 'Weekly (Mondays)', 'universal-telegram' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute fragment.
		echo '</select></label> ';
		echo '<label>' . esc_html__( 'At', 'universal-telegram' ) . ' <input type="time" name="digest_settings[time]" value="' . esc_attr( $values['time'] ) . '" /></label></p>';

		if ( $values['enabled'] && null !== $values['bot_id'] && null !== $values['destination_id'] ) {
			$next = DigestSchedule::next_occurrence( time(), $values['interval'], $values['time'], wp_timezone() );
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: local date and time of the next digest. */
					__( 'Next digest: %s', 'universal-telegram' ),
					( new DateTimeImmutable( '@' . $next ) )->setTimezone( wp_timezone() )->format( 'Y-m-d H:i' )
				)
			) . '</p>';
		}

		submit_button( __( 'Save digest settings', 'universal-telegram' ) );
		echo '</form>';
	}

	/**
	 * The admin-post handler.
	 */
	public function handle_request(): void {
		if ( ! current_user_can( CapabilityRegistrar::MANAGE_AUTOMATIONS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'universal-telegram' ), '', 403 );
		}

		check_admin_referer( self::NONCE_ACTION );

		$input = isset( $_POST['digest_settings'] ) && is_array( $_POST['digest_settings'] )
			? wp_unslash( $_POST['digest_settings'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by TicketDigestSettings::sanitize().
			: array();

		$clean = TicketDigestSettings::sanitize( $input );

		if ( null !== $clean['bot_id'] && null !== $clean['destination_id'] && ! $this->eligibility->destination_is_eligible( $clean['bot_id'], $clean['destination_id'] ) ) {
			$clean['destination_id'] = null;
		}

		$this->settings->save( $clean );
		$this->job->reschedule();

		$this->redirect_and_exit( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=notifications-activity&section=' . self::SECTION_ID . '&digest_saved=1' ) );
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
