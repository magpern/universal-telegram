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
 * Renders the progress-driven setup wizard shown on the Bots tab (M06.1
 * plan §"Rendering"; corrective addendum: new-user guided setup + any-bot
 * configuration) in place of the old static guidance panel. Every step's
 * own form is the shared TelegramFormFields markup — this class never
 * duplicates a form or introduces a new admin-post action. Step 5 only
 * ever links to the existing Settings tab; it never renders, embeds, or
 * cross-posts that tab's own form (doing so would risk silently altering
 * `remove_data_on_uninstall`, per SettingsPage's own combined save
 * handler). No bot selection made here ever changes which bot the chat
 * widget is wired to — that stays exactly BotSetupWizardState::default_bot(),
 * unaffected by this class (docs/adr — ChatWidgetAvailability's own
 * single-default-bot design is unchanged).
 */
final class BotSetupWizardRenderer {

	/**
	 * Constructor.
	 *
	 * @param BotSetupWizardState  $state Derives each step's completion state.
	 * @param TelegramFormFields   $forms Shared bot/destination/op form markup.
	 * @param BotProfileRepository $bots  Every configured bot, and lookups by id.
	 */
	public function __construct(
		private readonly BotSetupWizardState $state,
		private readonly TelegramFormFields $forms,
		private readonly BotProfileRepository $bots
	) {}

	/**
	 * Renders the wizard.
	 *
	 * @param int         $step     The step to display once a bot is selected, already validated to the 1-5 range by the caller.
	 * @param string|null $bot_mode The top-level landing choice — 'new'|'existing', or null for the landing choice itself.
	 * @param int|null    $bot_id   The bot being configured, already validated to exist by the caller, or null if none is selected yet.
	 */
	public function render( int $step, ?string $bot_mode = null, ?int $bot_id = null ): void {
		$selected_bot = null !== $bot_id ? $this->bots->find( $bot_id ) : null;

		echo '<div class="card" style="max-width:none;">';
		echo '<h2>' . esc_html__( 'Set up your Telegram bot', 'universal-telegram' ) . '</h2>';

		if ( null !== $selected_bot ) {
			$this->render_selected_bot_wizard( $step, $selected_bot );
		} elseif ( 'new' === $bot_mode ) {
			$this->render_new_bot_flow();
		} elseif ( 'existing' === $bot_mode ) {
			$this->render_existing_bot_picker();
		} else {
			$this->render_landing_choice();
		}

		echo '</div>';
	}

	/**
	 * The top-level landing choice: create a new bot, or configure one that
	 * already exists (only offered when at least one bot exists).
	 */
	private function render_landing_choice(): void {
		echo '<p>' . esc_html__( 'How would you like to start?', 'universal-telegram' ) . '</p>';

		echo '<p>';
		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( $this->landing_choice_url( 'new' ) ),
			esc_html__( 'Create and set up a new bot', 'universal-telegram' )
		);
		echo '</p>';
		echo '<p>' . esc_html__( "I don't have a bot yet — walk me through creating one.", 'universal-telegram' ) . '</p>';

		if ( array() !== $this->bots->all() ) {
			echo '<p>';
			printf(
				'<a class="button" href="%1$s">%2$s</a>',
				esc_url( $this->landing_choice_url( 'existing' ) ),
				esc_html__( 'Configure an existing bot', 'universal-telegram' )
			);
			echo '</p>';
			echo '<p>' . esc_html__( 'Continue setting up a bot you already added.', 'universal-telegram' ) . '</p>';
		}
	}

	/**
	 * The "create and set up a new bot" flow: the BotFather walkthrough,
	 * then the shared create-bot form. On submission, the controller
	 * returns here with the new bot selected (`?bot_id=latest`), continuing
	 * straight into its own checklist.
	 */
	private function render_new_bot_flow(): void {
		echo '<p><a href="' . esc_url( $this->wizard_landing_url() ) . '">' .
			esc_html__( '← Choose a different way to start', 'universal-telegram' ) .
			'</a></p>';

		echo '<p>' . esc_html__( 'Open BotFather in Telegram, run /newbot, and copy the token it provides.', 'universal-telegram' ) . '</p>';
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( 'https://core.telegram.org/bots#6-botfather' ),
			esc_html__( 'How to get a bot token', 'universal-telegram' )
		);
		echo '<div class="notice notice-warning inline"><p>' .
			esc_html__( 'Keep your bot token private. Anyone with it can control the bot.', 'universal-telegram' ) .
			'</p></div>';
		echo '<p>' . esc_html__( 'Enter the token only in the form below. It is never shown again once saved.', 'universal-telegram' ) . '</p>';
		$this->forms->create_bot_form( true );
	}

	/**
	 * The "configure an existing bot" flow: a picker across every
	 * configured bot, skipped straight through when there is only one.
	 */
	private function render_existing_bot_picker(): void {
		$all = $this->bots->all();

		if ( array() === $all ) {
			echo '<p>' . esc_html__( "You don't have any bots yet.", 'universal-telegram' ) . '</p>';
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $this->landing_choice_url( 'new' ) ),
				esc_html__( 'Create and set up a new bot', 'universal-telegram' )
			);
			return;
		}

		if ( 1 === count( $all ) ) {
			$only = $all[0];
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $this->bot_step_url( $only->id(), $this->state->current_step( $only ) ) ),
				esc_html(
					sprintf(
						/* translators: %s: the bot's own admin-facing name. */
						__( 'Continue configuring %s →', 'universal-telegram' ),
						$only->name()
					)
				)
			);
			return;
		}

		echo '<p><a href="' . esc_url( $this->wizard_landing_url() ) . '">' .
			esc_html__( '← Choose a different way to start', 'universal-telegram' ) .
			'</a></p>';
		echo '<p>' . esc_html__( 'Choose a bot to configure:', 'universal-telegram' ) . '</p>';
		echo '<ul>';

		foreach ( $all as $bot ) {
			printf(
				'<li><a href="%1$s">%2$s</a> — %3$s</li>',
				esc_url( $this->bot_step_url( $bot->id(), $this->state->current_step( $bot ) ) ),
				esc_html( $bot->name() ),
				$this->state->is_complete( $bot ) ? esc_html__( 'Complete', 'universal-telegram' ) : esc_html__( 'Not yet', 'universal-telegram' )
			);
		}

		echo '</ul>';
	}

	/**
	 * Renders the five-step checklist for one already-selected bot.
	 *
	 * @param int        $step The step to display.
	 * @param BotProfile $bot  The bot being configured.
	 */
	private function render_selected_bot_wizard( int $step, BotProfile $bot ): void {
		printf(
			// translators: %s: the bot's own admin-facing name, already escaped.
			'<p>' . esc_html__( 'Configuring: %s', 'universal-telegram' ) . '</p>', // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
			'<strong>' . esc_html( $bot->name() ) . '</strong>'
		);

		if ( ! $this->state->is_default_bot( $bot ) ) {
			$default = $this->state->default_bot();
			echo '<p>' . esc_html__( "This is not your website's chat-widget bot.", 'universal-telegram' );
			if ( null !== $default ) {
				printf(
					// translators: %s: the default bot's own admin-facing name, already escaped.
					' ' . esc_html__( 'Only %s connects to the chat widget.', 'universal-telegram' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
					esc_html( $default->name() )
				);
			}
			echo '</p>';
		}

		if ( count( $this->bots->all() ) > 1 ) {
			printf(
				'<p><a href="%1$s">%2$s</a></p>',
				esc_url( $this->landing_choice_url( 'existing' ) ),
				esc_html__( 'Choose a different bot →', 'universal-telegram' )
			);
		}

		echo '<p><a href="#wizard-current-step">' . esc_html__( 'Skip to current step', 'universal-telegram' ) . '</a></p>';

		$this->render_progress_nav( $step, $bot );
		$this->render_step( $step, $bot );
	}

	/**
	 * Renders the ordered progress list, one link per step, scoped to the
	 * bot being configured.
	 *
	 * @param int        $active_step The currently displayed step.
	 * @param BotProfile $bot         The bot being configured.
	 */
	private function render_progress_nav( int $active_step, BotProfile $bot ): void {
		echo '<nav aria-label="' . esc_attr__( 'Setup progress', 'universal-telegram' ) . '"><ol>';

		for ( $n = 1; $n <= 5; $n++ ) {
			$is_active = $n === $active_step;

			printf(
				'<li><a href="%1$s"%2$s>%3$s</a> — %4$s</li>',
				esc_url( $this->bot_step_url( $bot->id(), $n ) ),
				$is_active ? ' aria-current="step"' : '',
				esc_html( $this->step_title( $n ) ),
				esc_html( $this->step_badge( $n, $bot ) )
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
	 * @param int        $step The step number.
	 * @param BotProfile $bot  The bot being configured.
	 *
	 * @return string
	 */
	private function step_badge( int $step, BotProfile $bot ): string {
		switch ( $step ) {
			case 1:
				return $this->state->step_one_complete( $bot ) ? __( 'Complete', 'universal-telegram' ) : __( 'Not yet', 'universal-telegram' );
			case 2:
			case 3:
				return __( 'Manual step — do this in Telegram', 'universal-telegram' );
			case 4:
				return $this->state->step_four_complete( $bot ) ? __( 'Complete', 'universal-telegram' ) : __( 'Not yet', 'universal-telegram' );
			default:
				return $this->state->step_five_complete( $bot ) ? __( 'Complete', 'universal-telegram' ) : __( 'Not yet', 'universal-telegram' );
		}
	}

	/**
	 * The URL for a given step of a given bot's checklist, on this same
	 * Bots tab.
	 *
	 * @param int $bot_id The bot's primary key.
	 * @param int $step   The step number.
	 *
	 * @return string
	 */
	private function bot_step_url( int $bot_id, int $step ): string {
		return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . BotManagementPage::TAB_ID . '&view=wizard&bot_id=' . $bot_id . '&step=' . $step );
	}

	/**
	 * The wizard's own bare landing URL (no bot selected, no mode chosen).
	 *
	 * @return string
	 */
	private function wizard_landing_url(): string {
		return admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . BotManagementPage::TAB_ID . '&view=wizard' );
	}

	/**
	 * The URL for a specific top-level landing-choice mode ('new'|'existing').
	 *
	 * @param string $mode 'new' or 'existing'.
	 *
	 * @return string
	 */
	private function landing_choice_url( string $mode ): string {
		return $this->wizard_landing_url() . '&bot_mode=' . $mode;
	}

	/**
	 * Renders the active step's own content.
	 *
	 * @param int        $step The step to render.
	 * @param BotProfile $bot  The bot being configured.
	 */
	private function render_step( int $step, BotProfile $bot ): void {
		echo '<section aria-labelledby="wizard-current-step">';
		echo '<h3 id="wizard-current-step" tabindex="-1">' . esc_html( $this->step_title( $step ) ) . '</h3>';

		switch ( $step ) {
			case 1:
				$this->render_step_1( $bot );
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

		$this->render_step_nav( $step, $bot );
	}

	/**
	 * Renders step 1 for an already-selected bot: a plain confirmation,
	 * never another create-bot form (that would risk creating an unrelated
	 * second bot while configuring this one).
	 *
	 * @param BotProfile $bot The bot being configured.
	 */
	private function render_step_1( BotProfile $bot ): void {
		if ( $this->state->step_one_complete( $bot ) ) {
			echo '<p>' . esc_html__( 'Bot created and its token validated.', 'universal-telegram' ) . '</p>';
			return;
		}

		// Not reachable through the normal wizard flow (every bot the
		// create_bot op creates is validated first), kept only as an
		// honest fallback rather than silently claiming completion.
		echo '<p>' . esc_html__( "This bot's token has not been validated yet. Use Test connection on the Bots tab.", 'universal-telegram' ) . '</p>';
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
	 * @param BotProfile $bot The bot being configured.
	 */
	private function render_step_4( BotProfile $bot ): void {
		printf(
			'<p><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
			esc_url( 'https://telegram.me/chatIDrobot' ),
			esc_html__( 'How to find the group\'s numeric chat ID', 'universal-telegram' )
		);
		echo '<p>' . esc_html__( 'Use these values: Label "Website Support", Kind "supergroup", Chat ID the numeric group ID (commonly starting with -100), and leave Topic ID blank.', 'universal-telegram' ) . '</p>';

		$this->forms->create_destination_form( $bot->id(), __( 'Website Support', 'universal-telegram' ), 'supergroup' );

		$connected = $this->state->connected_destination( $bot );

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
	 * Renders step 5: register the webhook and, for the default bot only,
	 * activate the chat widget.
	 *
	 * @param BotProfile $bot The bot being configured.
	 */
	private function render_step_5( BotProfile $bot ): void {
		echo '<p>' . esc_html__( 'Register the webhook so Telegram can reach this site.', 'universal-telegram' ) . '</p>';
		$this->forms->op_button_form( 'register_webhook', array( 'bot_id' => $bot->id() ), __( 'Register webhook', 'universal-telegram' ) );

		if ( ! $this->state->is_default_bot( $bot ) ) {
			echo '<p>' . esc_html__( "This bot is not connected to the website's chat widget, so no further action is needed here.", 'universal-telegram' ) . '</p>';
			return;
		}

		printf(
			// translators: %s: a link to the existing Hub Settings tab, already escaped.
			'<p>' . esc_html__( 'Then open %s and enable the chat widget, then return to this Bots tab.', 'universal-telegram' ) . '</p>', // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.Security.EscapeOutput.OutputNotEscaped
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . HubPage::SLUG . '&tab=' . SettingsPage::TAB_ID ) ) . '">' . esc_html__( 'Settings', 'universal-telegram' ) . '</a>'
		);

		echo '<p>' . esc_html__( 'To verify: visit an uncached public page in a private/incognito browser window. Page caching can retain an older version of the page without the widget.', 'universal-telegram' ) . '</p>';
	}

	/**
	 * Renders the Previous/Next links for the active step, scoped to the
	 * bot being configured.
	 *
	 * @param int        $step The active step.
	 * @param BotProfile $bot  The bot being configured.
	 */
	private function render_step_nav( int $step, BotProfile $bot ): void {
		echo '<p>';

		if ( $step > 1 ) {
			printf(
				'<a href="%1$s">%2$s</a> ',
				esc_url( $this->bot_step_url( $bot->id(), $step - 1 ) ),
				esc_html__( '← Back', 'universal-telegram' )
			);
		}

		if ( $step < 5 ) {
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $this->bot_step_url( $bot->id(), $step + 1 ) ),
				esc_html__( 'Continue →', 'universal-telegram' )
			);
		}

		echo '</p>';
	}
}
