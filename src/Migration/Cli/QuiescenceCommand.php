<?php
/**
 * WP-CLI legacy-chat quiescence operator surface.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration\Cli;

use UniversalTelegram\Migration\CutoverIncidentReason;
use UniversalTelegram\Migration\CutoverReplayDispatcher;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceState;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;
use UniversalTelegram\Telegram\Inbound\WebhookController;

/**
 * `wp universal-telegram quiescence <enter|status|confirm|exit|replay-deferred-updates>`
 * (docs/adr/0040 §6.1). WP-CLI-only, the same OS-shell authority boundary
 * `LegacyExportServiceV1` already uses (ADR-0039 §2) — registered under the
 * identical `defined( 'WP_CLI' ) && WP_CLI` guard `BindingImportCommand`
 * uses.
 */
final class QuiescenceCommand {

	/**
	 * Constructor.
	 *
	 * @param QuiescenceGate           $gate               The state machine and drain proofs.
	 * @param DeferredUpdateRepository $deferred           Table 3 access, for replay.
	 * @param BotProfileRepository     $bots               Resolves a deferred row's bot_id back to a BotProfile for replay.
	 * @param WebhookController        $webhook            Owns process_update(), the shared live/replay processing pipeline.
	 * @param ChannelBindingRepository $bindings           Resolves, per row, whether an active binding now exists (docs/adr/0042 §3).
	 * @param CutoverReplayDispatcher  $cutover_dispatcher Dispositions a row whose topic now has an active binding.
	 */
	public function __construct(
		private readonly QuiescenceGate $gate,
		private readonly DeferredUpdateRepository $deferred,
		private readonly BotProfileRepository $bots,
		private readonly WebhookController $webhook,
		private readonly ChannelBindingRepository $bindings,
		private readonly CutoverReplayDispatcher $cutover_dispatcher
	) {}

	/**
	 * Registers the WP-CLI command when WP_CLI is present.
	 */
	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'universal-telegram quiescence', array( $this, 'dispatch' ) );
	}

	/**
	 * WP-CLI dispatcher.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : enter|status|confirm|exit|replay-deferred-updates
	 *
	 * [--assume-quiescence-authority]
	 * : Required by enter and exit.
	 *
	 * @param array<int, string>   $args       Positional args.
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	public function dispatch( array $args, array $assoc_args ): void {
		$action = $args[0] ?? '';

		switch ( $action ) {
			case 'enter':
				$this->enter( $assoc_args );
				break;
			case 'status':
				$this->status();
				break;
			case 'confirm':
				$this->confirm();
				break;
			case 'exit':
				$this->exit_command( $assoc_args );
				break;
			case 'replay-deferred-updates':
				$this->replay_deferred_updates();
				break;
			default:
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error -- WP-CLI path only.
				\WP_CLI::error( 'Usage: wp universal-telegram quiescence <enter|status|confirm|exit|replay-deferred-updates>' ); // @phpstan-ignore class.notFound
		}
	}

	/**
	 * `enter`: `idle → draining`. Requires --assume-quiescence-authority.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function enter( array $assoc_args ): void {
		if ( ! isset( $assoc_args['assume-quiescence-authority'] ) ) {
			\WP_CLI::error( '--assume-quiescence-authority is required.' ); // @phpstan-ignore class.notFound
			return;
		}

		$succeeded = $this->gate->enter( 'wp-cli', $this->current_os_user_id() );

		if ( ! $succeeded ) {
			\WP_CLI::error( 'Could not enter draining state.' ); // @phpstan-ignore class.notFound
			return;
		}

		\WP_CLI::warning( 'Live visitor chat traffic and Telegram commands are now being refused/buffered.' ); // @phpstan-ignore class.notFound
		\WP_CLI::success( 'State is now: draining.' ); // @phpstan-ignore class.notFound
	}

	/**
	 * `status`: read-only. Never surfaces deferred-update content.
	 */
	private function status(): void {
		$state        = $this->gate->state();
		$breakdown    = $this->gate->drain_breakdown();
		$backlog      = $this->gate->deferred_update_backlog_count();
		$oldest_age   = $this->gate->oldest_unreplayed_age_seconds();
		$is_quiescent = QuiescenceState::QUIESCENT === $state && 0 === $backlog;

		\WP_CLI::log( 'State: ' . $state->value ); // @phpstan-ignore class.notFound
		\WP_CLI::log( 'Drain breakdown:' ); // @phpstan-ignore class.notFound

		foreach ( $breakdown as $category => $count ) {
			\WP_CLI::log( sprintf( '  %s: %d', $category, $count ) ); // @phpstan-ignore class.notFound
		}

		\WP_CLI::log( sprintf( 'Deferred-update backlog: %d', $backlog ) ); // @phpstan-ignore class.notFound

		if ( null !== $oldest_age ) {
			\WP_CLI::log( sprintf( 'Oldest unreplayed row age: %d seconds', $oldest_age ) ); // @phpstan-ignore class.notFound

			if ( $oldest_age > DAY_IN_SECONDS ) {
				\WP_CLI::warning( 'Oldest unreplayed row exceeds 24 hours — run replay-deferred-updates.' ); // @phpstan-ignore class.notFound
			}
		}

		\WP_CLI::log( 'is_quiescent(): ' . ( $is_quiescent ? 'true' : 'false' ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * `confirm`: `draining → quiescent`, only if every drain condition
	 * holds. Safe to re-run.
	 */
	private function confirm(): void {
		$result = $this->gate->confirm( 'wp-cli', $this->current_os_user_id() );

		if ( $result['success'] ) {
			\WP_CLI::success( 'State is now: quiescent.' ); // @phpstan-ignore class.notFound
			return;
		}

		$pending = array_filter( $result['breakdown'] );

		if ( array() === $pending ) {
			\WP_CLI::error( 'Not currently draining — nothing to confirm.' ); // @phpstan-ignore class.notFound
			return;
		}

		$parts = array();

		foreach ( $pending as $category => $count ) {
			$parts[] = "{$count} {$category}";
		}

		\WP_CLI::error( 'Still draining: ' . implode( ', ', $parts ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * `exit`: `quiescent → replaying` (or `draining → replaying` if
	 * aborting). Requires --assume-quiescence-authority.
	 *
	 * @param array<string, mixed> $assoc_args Flags.
	 */
	private function exit_command( array $assoc_args ): void {
		if ( ! isset( $assoc_args['assume-quiescence-authority'] ) ) {
			\WP_CLI::error( '--assume-quiescence-authority is required.' ); // @phpstan-ignore class.notFound
			return;
		}

		$succeeded = $this->gate->exit( 'wp-cli', $this->current_os_user_id() );

		if ( ! $succeeded ) {
			\WP_CLI::error( 'Could not exit — not currently draining or quiescent.' ); // @phpstan-ignore class.notFound
			return;
		}

		$backlog = $this->gate->deferred_update_backlog_count();

		\WP_CLI::log( sprintf( 'State is now: replaying. Deferred-update backlog: %d', $backlog ) ); // @phpstan-ignore class.notFound
		\WP_CLI::success( 'Run `wp universal-telegram quiescence replay-deferred-updates` to drain the backlog and return to idle.' ); // @phpstan-ignore class.notFound
	}

	/**
	 * `replay-deferred-updates`: decrypts and dispositions every currently-
	 * unreplayed row, grouped by bot_id, ordered by update_id ascending
	 * (id tie-breaker) — the single authoritative, cohort-aware drain
	 * (docs/adr/0042 §3). Per row, a live check decides disposition:
	 * whether an **active** Support Chat binding currently exists for the
	 * row's `(bot_id, telegram_topic_id)` — the identical predicate
	 * `InboundAdapterBridge::try_handle()` itself already evaluates for
	 * live traffic, evaluated here fresh at drain time, never from a
	 * cohort list computed earlier. An active-binding row is dispositioned
	 * via `CutoverReplayDispatcher` (message/command/lifecycle-event/
	 * incident); every other row replays through the existing, unchanged
	 * legacy `process_update()` path. There is no separate "final handoff
	 * scan" step — this loop, and the widened backlog predicate the final
	 * CAS below observes, are the single authoritative barrier. Safe to
	 * re-run repeatedly.
	 */
	private function replay_deferred_updates(): void {
		$context = $this->gate->issue_replay_context();

		if ( null === $context ) {
			\WP_CLI::error( 'Not currently replaying — run `exit` first.' ); // @phpstan-ignore class.notFound
			return;
		}

		$grouped    = $this->deferred->unreplayed_grouped_by_bot();
		$replayed   = 0;
		$handed_off = 0;
		$incidents  = 0;
		$retried    = 0;

		foreach ( $grouped as $bot_id => $records ) {
			$bot = $this->bots->find( $bot_id );

			if ( null === $bot ) {
				\WP_CLI::error( sprintf( 'Cannot replay: bot_id=%d no longer exists.', $bot_id ) ); // @phpstan-ignore class.notFound
				return;
			}

			foreach ( $records as $record ) {
				$payload = $this->deferred->decrypt_payload( $record );

				if ( null === $payload ) {
					$this->deferred->record_incident( $record->id(), CutoverIncidentReason::DECRYPT_FAILED );
					++$incidents;
					continue;
				}

				list( , , $message_thread_id ) = $this->webhook->extract_metadata_for_cutover_replay( $payload );

				$binding = null !== $message_thread_id ? $this->bindings->find_by_bot_topic( $bot_id, $message_thread_id ) : null;

				if ( null !== $binding && $binding->is_active() ) {
					$outcome = $this->cutover_dispatcher->dispatch( $bot, $record, $binding, $payload );

					switch ( $outcome ) {
						case CutoverReplayDispatcher::OUTCOME_HANDED_OFF:
							++$handed_off;
							break;
						case CutoverReplayDispatcher::OUTCOME_INCIDENT:
							++$incidents;
							break;
						default:
							++$retried;
							break;
					}

					continue;
				}

				try {
					$this->webhook->process_update( $bot, $payload, $context );
				} catch ( \Throwable $exception ) {
					\WP_CLI::error( sprintf( 'Failed to process deferred update bot_id=%d update_id=%d.', $record->bot_id(), $record->update_id() ) ); // @phpstan-ignore class.notFound
					return;
				}

				$this->deferred->mark_replayed( $record->id() );
				++$replayed;
			}
		}

		$attempt = $this->gate->attempt_replaying_to_idle( 'wp-cli', $this->current_os_user_id() );

		$summary = sprintf(
			'Replayed %d update(s), handed off %d, %d incident(s), %d retryable.',
			$replayed,
			$handed_off,
			$incidents,
			$retried
		);

		if ( $attempt['success'] ) {
			\WP_CLI::success( $summary . ' State is now: idle.' ); // @phpstan-ignore class.notFound
			return;
		}

		\WP_CLI::log( $summary . sprintf( ' %d row(s) still remain (including any unresolved incidents) — re-run this command.', $attempt['remaining'] ) ); // @phpstan-ignore class.notFound
	}

	/**
	 * The current OS-shell user's WordPress id, if WP-CLI can resolve one.
	 * Best-effort only — Table 2's `requested_by` column is nullable
	 * specifically because this is not always resolvable.
	 *
	 * @return int|null
	 */
	private function current_os_user_id(): ?int {
		$id = get_current_user_id();

		return $id > 0 ? $id : null;
	}
}
