<?php
/**
 * Bot setup wizard renderer.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Telegram;

use UniversalTelegram\Administration\Hub\HubPage;
use UniversalTelegram\Administration\Hub\SettingsPage;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;

/**
 * Renders the five-step, progress-driven setup wizard shown on the Bots tab
 * (M06.1 plan §"Rendering") in place of the old static guidance panel. Every
 * step's own form is the shared TelegramFormFields markup — this class never
 * duplicates a form or introduces a new admin-post action. Step 5 only ever
 * links to the existing Settings tab; it never renders, embeds, or
 * cross-posts that tab's own form (doing so would risk silently altering
 * `remove_data_on_uninstall`, per SettingsPage's own combined save handler).
 */
final class BotSetupWizardRenderer {

	/**
	 * Constructor.
	 *
	 * @param BotSetupWizardState  $state Derives each step's completion state.
	 * @param TelegramFormFields   $forms Shared bot/destination/op form markup.
	 * @param BotProfileRepository $bots  Used only to detect additional, non-default bots.
	 */
	public function __construct(
		private readonly BotSetupWizardState $state,
		private readonly TelegramFormFields $forms,
		private readonly BotProfileRepository $bots
	) {}

	/**
	 * Renders the wizard for one already-validated step (1-5).
	 *
	 * @param int         $step     The step to display, already validated to the 1-5 range by the caller.
	 * @param string|null $bot_mode Step 1's landing choice — 'new'|'existing', or null for the landing
	 *                              choice itself. Ignored once a bot already exists (M06.1 corrective
	 *                              addendum: new-user guided setup).
	 */
	public function render( int $step, ?string $bot_mode = null ): void {
		$bot = $this->state->default_bot();

		echo '<div class="card" style="max-width:none;">';
		echo '<h2>' . esc_html__( 'Set up your Telegram bot', 'universal-telegram' ) . '</h2>';

		if ( null !== $bot ) {
			printf(
				// translators: %s: the bot's own admin-facing name, already escaped.
				'<p>' . esc_html__( 'Setting up: %s', 'universal-telegram' ) . '</p>', // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
				'<strong>' . esc_html( $bot->name() ) . '</strong>'
			);
		}

		if ( count( $this->bots->all() ) > 1 ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . BotManagementPage::TAB_ID ) ),
				esc_html__( 'Manage other bots →', 'universal-telegram' )
			);
		}

		echo '<p><a href="#wizard-current-step">' . esc_html__( 'Skip to current step', 'universal-telegram' ) . '</a></p>';

		$this->render_progress_nav( $step );
		$this->render_step( $step, $bot, $bot_mode );

		echo '</div>';
	}

	/**
	 * Renders the ordered progress list, one link per step.
	 *
	 * @param int $active_step The currently displayed step.
	 */
	private function render_progress_nav( int $active_step ): void {
		echo '<nav aria-label="' . esc_attr__( 'Setup progress', 'universal-telegram' ) . '"><ol>';

		for ( $n = 1; $n <= 5; $n++ ) {
			$is_active = $n === $active_step;

			printf(
				'<li><a href="%1$s"%2$s>%3$s</a> — %4$s</li>',
				esc_url( $this->step_url( $n ) ),
				$is_active ? ' aria-current="step"' : '',
				esc_html( $this->step_title( $n ) ),
				esc_html( $this->step_badge( $n ) )
			);
		}

		echo '</ol></nav>';
	}

	/**
	 * The step's short nav title.
	 *
	 * @param int $step The step number.
	 *
	 * @return string
	 */
	private function step_title( int $step ): string {
		switch ( $step ) {
			case 1:
				return __( '1. Create bot', 'universal-telegram' );
			case 2:
				return __( '2. Create support group', 'universal-telegram' );
			case 3:
				return __( '3. Add bot as administrator', 'universal-telegram' );
			case 4:
				return __( '4. Connect group', 'universal-telegram' );
			default:
				return __( '5. Activate chat widget', 'universal-telegram' );
		}
	}

	/**
	 * The step's completion badge — a real "Complete"/"Not yet" state for
	 * the WordPress-verifiable steps (1, 4, 5), and a distinct, neutral
	 * label for the Telegram-side manual steps (2, 3), which are never
	 * marked complete.
	 *
	 * @param int $step The step number.
	 *
	 * @return string
	 */
	private function step_badge( int $step ): string {
		switch ( $step ) {
			case 1:
				return $this->state->step_one_complete() ? __( 'Complete', 'universal-telegram' ) : __( 'Not yet', 'universal-telegram' );
			case 2:
			case 3:
				return __( 'Manual step — do this in Telegram', 'universal-telegram' );
			case 4:
				return $this->state->step_four_complete() ? __( 'Complete', 'universal-telegram' ) : __( 'Not yet', 'universal-telegram' );
			default:
				return $this->state->step_five_complete() ? __( 'Complete', 'universal-telegram' ) : __( 'Not yet', 'universal-telegram' );
		}
	}

	/**
	 * The URL for a given step, on this same Bots tab.
	 *
	 * @param int $step The step number.
	 *
	 * @return string
	 */
	private function step_url( int $step ): string {
		return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . BotManagementPage::TAB_ID . '&view=wizard&step=' . $step );
	}

	/**
	 * Step 1's URL for a specific landing-choice mode ('new'|'existing').
	 *
	 * @param string $mode 'new' or 'existing'.
	 *
	 * @return string
	 */
	private function step_one_url_with_mode( string $mode ): string {
		return $this->step_url( 1 ) . '&bot_mode=' . $mode;
	}

	/**
	 * Renders the active step's own content.
	 *
	 * @param int             $step     The step to render.
	 * @param BotProfile|null $bot      The default bot, if one is configured.
	 * @param string|null     $bot_mode Step 1's landing-choice mode, if any.
	 */
	private function render_step( int $step, ?BotProfile $bot, ?string $bot_mode ): void {
		echo '<section aria-labelledby="wizard-current-step">';
		echo '<h3 id="wizard-current-step" tabindex="-1">' . esc_html( $this->step_title( $step ) ) . '</h3>';

		switch ( $step ) {
			case 1:
				$this->render_step_1( $bot, $bot_mode );
				break;
			case 2:
				$this->render_step_2();
				break;
			case 3:
				$this->render_step_3();
				break;
			case 4:
				$this->render_step_4( $bot );
				break;
			default:
				$this->render_step_5( $bot );
				break;
		}

		echo '</section>';

		$this->render_step_nav( $step );
	}

	/**
	 * Renders step 1: create bot. Once a bot already exists, the landing
	 * choice no longer applies — the step simply shows the same create-bot
	 * form it always has (M06.1 corrective addendum: new-user guided setup
	 * only concerns the very first bot, before one exists at all).
	 *
	 * @param BotProfile|null $bot      The default bot, if one is configured.
	 * @param string|null     $bot_mode The landing-choice mode ('new'|'existing'), or null.
	 */
	private function render_step_1( ?BotProfile $bot, ?string $bot_mode ): void {
		if ( null !== $bot ) {
			$this->render_step_1_bot_form();
			return;
		}

		if ( null === $bot_mode ) {
			$this->render_step_1_landing_choice();
			return;
		}

		echo '<p><a href="' . esc_url( $this->step_url( 1 ) ) . '">' .
			esc_html__( '← Choose a different way to start', 'universal-telegram' ) .
			'</a></p>';

		if ( 'existing' === $bot_mode ) {
			$this->render_step_1_existing_bot_flow();
		} else {
			$this->render_step_1_new_bot_flow();
		}
	}

	/**
	 * The landing choice shown only for a brand-new setup (no bot at all).
	 */
	private function render_step_1_landing_choice(): void {
		echo '<p>' . esc_html__( 'How would you like to start?', 'universal-telegram' ) . '</p>';

		echo '<p>';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( $this->step_one_url_with_mode( 'existing' ) ),
			esc_html__( 'Set up an existing bot', 'universal-telegram' )
		);
		echo '</p>';
		echo '<p>' . esc_html__( 'I already created a bot with BotFather and have its token.', 'universal-telegram' ) . '</p>';

		echo '<p>';
		printf(
			'<a class="button" href="%1$s">%2$s</a>',
			esc_url( $this->step_one_url_with_mode( 'new' ) ),
			esc_html__( 'Create and set up a new bot', 'universal-telegram' )
		);
		echo '</p>';
		echo '<p>' . esc_html__( "I don't have a bot yet — walk me through creating one.", 'universal-telegram' ) . '</p>';
	}

	/**
	 * The "create and set up a new bot" flow: the full BotFather walkthrough
	 * before token entry.
	 */
	private function render_step_1_new_bot_flow(): void {
		echo '<p>' . esc_html__( 'Open BotFather in Telegram, run /newbot, and copy the token it provides.', 'universal-telegram' ) . '</p>';
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( 'https://core.telegram.org/bots#6-botfather' ),
			esc_html__( 'How to get a bot token', 'universal-telegram' )
		);
		$this->render_step_1_bot_form();
	}

	/**
	 * The "set up an existing bot" flow: token entry first, with the
	 * BotFather walkthrough available but de-emphasized for anyone who
	 * turns out not to have a bot yet after all.
	 */
	private function render_step_1_existing_bot_flow(): void {
		echo '<p>' . esc_html__( 'Enter your existing bot\'s token below.', 'universal-telegram' ) . '</p>';
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( 'https://core.telegram.org/bots#6-botfather' ),
			esc_html__( "Don't have a bot yet? Use BotFather's /newbot command.", 'universal-telegram' )
		);
		$this->render_step_1_bot_form();
	}

	/**
	 * The security warning and the shared create-bot form, common to both
	 * the new-bot and existing-bot flows, and to step 1 once a bot exists.
	 */
	private function render_step_1_bot_form(): void {
		echo '<div class="notice notice-warning inline"><p>' .
			esc_html__( 'Keep your bot token private. Anyone with it can control the bot.', 'universal-telegram' ) .
			'</p></div>';
		echo '<p>' . esc_html__( 'Enter the token only in the form below. It is never shown again once saved.', 'universal-telegram' ) . '</p>';
		$this->forms->create_bot_form();
	}

	/**
	 * Renders step 2: create support group (external, manual, never marked complete).
	 */
	private function render_step_2(): void {
		echo '<p><strong>' . esc_html__( 'Manual step — do this in Telegram. WordPress cannot create or verify this for you.', 'universal-telegram' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Create a private Telegram supergroup. Open its settings, enable Topics, and choose Tabs. Each website conversation will appear as its own topic inside this group.', 'universal-telegram' ) . '</p>';
	}

	/**
	 * Renders step 3: add bot as administrator (external, manual, never marked complete).
	 */
	private function render_step_3(): void {
		echo '<p><strong>' . esc_html__( 'Manual step — do this in Telegram. WordPress cannot verify this for you.', 'universal-telegram' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Add the bot to the group as an administrator. Once Topics is enabled, a "Manage topics" permission becomes available — enable it. No other permission is required.', 'universal-telegram' ) . '</p>';
	}

	/**
	 * Renders step 4: connect group.
	 *
	 * @param BotProfile|null $bot The default bot, if one is configured.
	 */
	private function render_step_4( ?BotProfile $bot ): void {
		if ( null === $bot ) {
			echo '<p>' . esc_html__( 'Complete step 1 (create bot) first.', 'universal-telegram' ) . '</p>';
			return;
		}

		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( 'https://telegram.me/chatIDrobot' ),
			esc_html__( 'How to find the group\'s numeric chat ID', 'universal-telegram' )
		);
		echo '<p>' . esc_html__( 'Use these values: Label "Website Support", Kind "supergroup", Chat ID the numeric group ID (commonly starting with -100), and leave Topic ID blank.', 'universal-telegram' ) . '</p>';

		$this->forms->create_destination_form( $bot->id(), __( 'Website Support', 'universal-telegram' ), 'supergroup' );

		$connected = $this->state->connected_destination();

		if ( null !== $connected ) {
			echo '<p>' . esc_html__( 'Recommended: send a test message to confirm the bot can post to this group. Delivery is queued and may take a short time to arrive.', 'universal-telegram' ) . '</p>';
			$this->forms->op_button_form(
				'send_test_message',
				array(
					'bot_id'         => $bot->id(),
					'destination_id' => $connected->id(),
				),
				__( 'Send test message', 'universal-telegram' )
			);
		}
	}

	/**
	 * Renders step 5: activate chat widget.
	 *
	 * @param BotProfile|null $bot The default bot, if one is configured.
	 */
	private function render_step_5( ?BotProfile $bot ): void {
		if ( null === $bot ) {
			echo '<p>' . esc_html__( 'Complete the earlier steps first.', 'universal-telegram' ) . '</p>';
			return;
		}

		echo '<p>' . esc_html__( 'Register the webhook so Telegram can reach this site.', 'universal-telegram' ) . '</p>';
		$this->forms->op_button_form( 'register_webhook', array( 'bot_id' => $bot->id() ), __( 'Register webhook', 'universal-telegram' ) );

		printf(
			// translators: %s: a link to the existing Hub Settings tab, already escaped.
			'<p>' . esc_html__( 'Then open %s and enable the chat widget, then return to this Bots tab.', 'universal-telegram' ) . '</p>', // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . SettingsPage::TAB_ID ) ) . '">' . esc_html__( 'Settings', 'universal-telegram' ) . '</a>'
		);

		echo '<p>' . esc_html__( 'To verify: visit an uncached public page in a private/incognito browser window. Page caching can retain an older version of the page without the widget.', 'universal-telegram' ) . '</p>';
	}

	/**
	 * Renders the Previous/Next links for the active step.
	 *
	 * @param int $step The active step.
	 */
	private function render_step_nav( int $step ): void {
		echo '<p>';

		if ( $step > 1 ) {
			printf(
				'<a href="%1$s">%2$s</a> ',
				esc_url( $this->step_url( $step - 1 ) ),
				esc_html__( '← Back', 'universal-telegram' )
			);
		}

		if ( $step < 5 ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->step_url( $step + 1 ) ),
				esc_html__( 'Continue →', 'universal-telegram' )
			);
		}

		echo '</p>';
	}
}
