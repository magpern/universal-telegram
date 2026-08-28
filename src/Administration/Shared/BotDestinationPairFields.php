<?php
/**
 * Shared bot + eligible-destination dropdown pair for admin settings.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Administration\Shared;

use UniversalTelegram\Telegram\Configuration\DestinationEligibility;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Configuration\BotStatus;

/**
 * Renders a bot picker and a destination picker whose options are filtered
 * client-side to the currently selected bot. Every destination option for
 * every active bot is emitted up front (tagged with data-bot-id) so an
 * administrator sees eligible destinations immediately after choosing a
 * bot, without an intermediate save/reload — the server-side eligibility
 * rule (DestinationEligibility::eligible_destinations_for_bot()) remains the
 * single source of truth for which rows are offered at all.
 */
final class BotDestinationPairFields {

	/**
	 * Whether the dependent-dropdown script has already been printed.
	 *
	 * @var bool
	 */
	private static bool $sync_script_printed = false;

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository   $bots               Active-bot listing.
	 * @param DestinationEligibility $digest_eligibility Eligible-destination filter.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly DestinationEligibility $digest_eligibility
	) {}

	/**
	 * Renders one bot select, one destination select, and the shared helper
	 * text. Options for all active bots' eligible destinations are included;
	 * the inline script shows only those matching the selected bot.
	 *
	 * @param string               $name_prefix    The POST array prefix, e.g. visitor_settings.
	 * @param string               $bot_field      Settings key for the bot id.
	 * @param string               $dest_field     Settings key for the destination id.
	 * @param array<string, mixed> $values         Current settings values.
	 */
	public function render( string $name_prefix, string $bot_field, string $dest_field, array $values ): void {
		$selected_bot_id         = null === ( $values[ $bot_field ] ?? null ) ? 0 : (int) $values[ $bot_field ];
		$selected_destination_id = null === ( $values[ $dest_field ] ?? null ) ? 0 : (int) $values[ $dest_field ];

		echo '<div class="ut-bot-destination-pair" data-ut-bot-destination-pair>';

		echo '<p><label>' . esc_html__( 'Bot', 'universal-telegram' ) . ' ';
		printf(
			'<select name="%1$s[%2$s]" data-ut-bot-select>',
			esc_attr( $name_prefix ),
			esc_attr( $bot_field )
		);
		echo '<option value="">' . esc_html__( '— Select a bot —', 'universal-telegram' ) . '</option>';
		foreach ( $this->bots->all() as $bot ) {
			if ( BotStatus::ACTIVE !== $bot->status() ) {
				continue;
			}
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( (string) $bot->id() ),
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute fragment.
				selected( $selected_bot_id, $bot->id(), false ),
				esc_html( $bot->name() )
			);
		}
		echo '</select></label></p>';

		echo '<p><label>' . esc_html__( 'Destination', 'universal-telegram' ) . ' ';
		printf(
			'<select name="%1$s[%2$s]" data-ut-destination-select>',
			esc_attr( $name_prefix ),
			esc_attr( $dest_field )
		);
		echo '<option value="">' . esc_html__( '— Select a destination —', 'universal-telegram' ) . '</option>';

		foreach ( $this->bots->all() as $bot ) {
			if ( BotStatus::ACTIVE !== $bot->status() ) {
				continue;
			}

			foreach ( $this->digest_eligibility->eligible_destinations_for_bot( $bot->id() ) as $destination ) {
				printf(
					'<option value="%1$s" data-bot-id="%2$s" %3$s>%4$s</option>',
					esc_attr( (string) $destination->id() ),
					esc_attr( (string) $bot->id() ),
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute fragment.
					selected( $selected_destination_id, $destination->id(), false ),
					esc_html( $destination->label() )
				);
			}
		}

		echo '</select></label></p>';
		echo '<p class="description">' . esc_html__(
			'Only enabled, manually configured destinations belonging to the selected bot appear here — a destination created automatically for a website chat conversation can never be selected.',
			'universal-telegram'
		) . '</p>';
		echo '</div>';

		$this->maybe_print_sync_script();
	}

	/**
	 * Prints the dependent-dropdown script once per page render.
	 */
	private function maybe_print_sync_script(): void {
		if ( self::$sync_script_printed ) {
			return;
		}

		self::$sync_script_printed = true;
		?>
		<script>
		(function () {
			function syncDestinationOptions(botSelect, destinationSelect) {
				var botId = botSelect.value;
				var selected = destinationSelect.value;
				var selectedStillVisible = false;

				Array.prototype.forEach.call(destinationSelect.options, function (option) {
					if (!option.value) {
						return;
					}

					var matches = option.getAttribute('data-bot-id') === botId;
					option.hidden = !matches;
					option.disabled = !matches;

					if (matches && option.value === selected) {
						selectedStillVisible = true;
					}
				});

				if (!selectedStillVisible) {
					destinationSelect.value = '';
				}
			}

			document.querySelectorAll('[data-ut-bot-destination-pair]').forEach(function (pair) {
				var botSelect = pair.querySelector('[data-ut-bot-select]');
				var destinationSelect = pair.querySelector('[data-ut-destination-select]');

				if (!botSelect || !destinationSelect) {
					return;
				}

				botSelect.addEventListener('change', function () {
					syncDestinationOptions(botSelect, destinationSelect);
				});

				syncDestinationOptions(botSelect, destinationSelect);
			});
		}());
		</script>
		<?php
	}

	/**
	 * Resets the one-shot script guard — for tests only.
	 */
	public static function reset_script_guard_for_tests(): void {
		self::$sync_script_printed = false;
	}
}
