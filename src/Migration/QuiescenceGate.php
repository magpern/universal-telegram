<?php
/**
 * Legacy-chat quiescence state machine and drain proofs.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use ActionScheduler;
use ActionScheduler_Store;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\WorkerRunner;

/**
 * Owns Table 1 (`{$wpdb->prefix}universal_telegram_quiescence_state`), the
 * single-row CAS state machine every write-blocking gate in the plugin
 * reads, and every async-work drain proof (docs/adr/0040 §4–§6). Every
 * transition is a single `UPDATE ... WHERE id = 1 AND state = %s` CAS
 * statement, reusing `Persistence\MigrationLock`'s CAS-via-single-UPDATE
 * mechanic but deliberately not its staleness/auto-reclaim policy — there
 * is no TTL, staleness, or auto-expiry on this state, ever.
 */
class QuiescenceGate {

	private const SINGLETON_ID = 1;

	/**
	 * The five async job types this gate's drain proof covers
	 * (docs/adr/0040 §5), in the fixed order `status`/`confirm` report them.
	 *
	 * @var array<int, string>
	 */
	private const DRAIN_JOB_TYPES = array(
		'conversation_create_topic',
		'conversation_delete_topic',
		'conversation_route_outbound',
		'telegram_send_message',
		'ai_draft_generate',
	);

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth                   $schema_health    Checked before every operation.
	 * @param DeferredUpdateRepository       $deferred_updates Table 3 access, for the webhook buffer path and the backlog/oldest-row signals.
	 * @param QuiescenceTransitionRepository $transitions  Table 2 audit-trail writer.
	 */
	public function __construct(
		private readonly SchemaHealth $schema_health,
		private readonly DeferredUpdateRepository $deferred_updates,
		private readonly QuiescenceTransitionRepository $transitions
	) {}

	/**
	 * The current state. Every one of the eight §2 entry-point gates and
	 * the three §5 sweep gates reads this — Table 1 is the only table any
	 * write-gate check reads on its hot path (docs/adr/0040 §4).
	 *
	 * @return QuiescenceState
	 */
	public function state(): QuiescenceState {
		if ( ! $this->schema_health->is_available() ) {
			return QuiescenceState::IDLE;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT state FROM {$table} WHERE id = %d", self::SINGLETON_ID ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null === $value ? QuiescenceState::IDLE : ( QuiescenceState::tryFrom( (string) $value ) ?? QuiescenceState::IDLE );
	}

	/**
	 * Whether new legacy-chat write work is currently permitted. The
	 * single check every §2 entry-point gate calls.
	 *
	 * @return bool
	 */
	public function is_idle(): bool {
		return QuiescenceState::IDLE === $this->state();
	}

	/**
	 * The current epoch token.
	 *
	 * @return string
	 */
	public function token(): string {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;

		$value = $wpdb->get_var(
			$wpdb->prepare( "SELECT token FROM {$table} WHERE id = %d", self::SINGLETON_ID ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return null === $value ? '' : (string) $value;
	}

	/**
	 * When the current `quiescent` state was entered, or null when the
	 * plugin is not currently in `quiescent` state. Part of the frozen
	 * `QuiescenceStateProvider`-shaped signal (docs/adr/0040 §8).
	 *
	 * @return \DateTimeImmutable|null
	 */
	public function since(): ?\DateTimeImmutable {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT state, entered_quiescent_at FROM {$table} WHERE id = %d", self::SINGLETON_ID ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		if ( null === $row || 'quiescent' !== $row['state'] || null === $row['entered_quiescent_at'] ) {
			return null;
		}

		$since = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $row['entered_quiescent_at'], new \DateTimeZone( 'UTC' ) );

		return false === $since ? null : $since;
	}

	/**
	 * The current unreplayed deferred-update backlog count.
	 *
	 * @return int
	 */
	public function deferred_update_backlog_count(): int {
		return $this->deferred_updates->backlog_count();
	}

	/** Returned by with_quiescence_lock() when $work committed successfully. */
	public const LOCK_RESULT_COMMITTED = 'committed';

	/** Returned by with_quiescence_lock() when $work itself asked to roll back. */
	public const LOCK_RESULT_ROLLED_BACK = 'rolled_back';

	/** Returned by with_quiescence_lock() when state was not `quiescent`. */
	public const LOCK_RESULT_NOT_QUIESCENT = 'not_quiescent';

	/** Returned by with_quiescence_lock() when state was `quiescent` but the deferred-update backlog was nonempty. */
	public const LOCK_RESULT_BACKLOG_NONEMPTY = 'backlog_nonempty';

	/**
	 * The atomic, lock-scoped quiescence assertion Support Chat ADR-0009 §5
	 * / this repository's ADR-0041 §2 require for SC-M03 work package 5's
	 * `LegacyBindingImportServiceV1`: a second caller, besides
	 * decide_webhook_disposition() and attempt_replaying_to_idle() above,
	 * that needs to verify quiescence and perform a write atomically against
	 * it — reusing the identical lock discipline those two methods already
	 * establish against Table 1's singleton row, rather than a second,
	 * subtly different implementation.
	 *
	 * Opens a transaction, locks the singleton quiescence row, and — only
	 * if state is `quiescent` and the deferred-update backlog is empty,
	 * still holding that lock — invokes `$work`. Commits only if `$work`
	 * returns `true`; rolls back (writing nothing this method itself wrote,
	 * and undoing whatever `$work` wrote) in every other case, including
	 * `$work` returning `false` or throwing. `Core\Plugin::quiescence_status()`
	 * is explicitly not a substitute for this method: it is read-only and
	 * unlocked, exactly the TOCTOU gap this method exists to close.
	 *
	 * @param callable(): bool $work Invoked only while quiescent and the
	 *                                lock is held. Return `true` to commit
	 *                                everything performed inside `$work`
	 *                                (and this method's own lock
	 *                                acquisition) together; `false` to roll
	 *                                back and write nothing. A thrown
	 *                                exception rolls back and propagates.
	 *
	 * @return string One of the LOCK_RESULT_* constants above.
	 *
	 * @throws \Throwable Propagated from $work after rolling back.
	 */
	public function with_quiescence_lock( callable $work ): string {
		if ( ! $this->schema_health->is_available() ) {
			return self::LOCK_RESULT_NOT_QUIESCENT;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'START TRANSACTION' );

		$state = $wpdb->get_var(
			$wpdb->prepare( "SELECT state FROM {$table} WHERE id = %d FOR UPDATE", self::SINGLETON_ID ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( QuiescenceState::QUIESCENT->value !== $state ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return self::LOCK_RESULT_NOT_QUIESCENT;
		}

		if ( $this->deferred_updates->backlog_count() > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return self::LOCK_RESULT_BACKLOG_NONEMPTY;
		}

		try {
			$should_commit = (bool) $work();
		} catch ( \Throwable $exception ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			throw $exception;
		}

		if ( ! $should_commit ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return self::LOCK_RESULT_ROLLED_BACK;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );

		return self::LOCK_RESULT_COMMITTED;
	}

	/**
	 * The age, in seconds, of the oldest unreplayed row, or null.
	 *
	 * @return int|null
	 */
	public function oldest_unreplayed_age_seconds(): ?int {
		return $this->deferred_updates->oldest_unreplayed_age_seconds();
	}

	/**
	 * `idle → draining` (docs/adr/0040 §6). Idempotent: if already in
	 * `draining`, `quiescent`, or `replaying`, this reports success without
	 * a second CAS or a second audit row — "someone else already made this
	 * true" (§4).
	 *
	 * @param string   $requested_via 'wp-cli' for every caller in this milestone.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 *
	 * @return bool True if `idle → draining` now holds (freshly, or already).
	 */
	public function enter( string $requested_via = 'wp-cli', ?int $requested_by = null ): bool {
		if ( QuiescenceState::IDLE !== $this->state() ) {
			return true;
		}

		return $this->try_transition( 'idle', 'draining', 'entered_draining_at', $requested_via, $requested_by );
	}

	/**
	 * `draining → quiescent`, only if every §5 drain condition currently
	 * holds. Does not consider the deferred-update backlog (docs/adr/0040
	 * §6). Safe to re-run.
	 *
	 * @param string   $requested_via 'wp-cli' for every caller in this milestone.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 *
	 * @return array{success: bool, breakdown: array<string, int>}
	 */
	public function confirm( string $requested_via = 'wp-cli', ?int $requested_by = null ): array {
		$breakdown = $this->drain_breakdown();
		$state     = $this->state();

		if ( QuiescenceState::QUIESCENT === $state ) {
			return array(
				'success'   => true,
				'breakdown' => $breakdown,
			);
		}

		if ( QuiescenceState::DRAINING !== $state ) {
			return array(
				'success'   => false,
				'breakdown' => $breakdown,
			);
		}

		$drained = 0 === array_sum( $breakdown );

		if ( ! $drained ) {
			return array(
				'success'   => false,
				'breakdown' => $breakdown,
			);
		}

		$succeeded = $this->try_transition( 'draining', 'quiescent', 'entered_quiescent_at', $requested_via, $requested_by );

		return array(
			'success'   => $succeeded,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * `quiescent → replaying`, or `draining → replaying` when aborting
	 * before `confirm()` ever succeeded (docs/adr/0040 §6). Idempotent: if
	 * already `replaying`, reports success without a second CAS.
	 *
	 * @param string   $requested_via 'wp-cli' for every caller in this milestone.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 *
	 * @return bool
	 */
	public function exit( string $requested_via = 'wp-cli', ?int $requested_by = null ): bool {
		$state = $this->state();

		if ( QuiescenceState::REPLAYING === $state ) {
			return true;
		}

		if ( QuiescenceState::QUIESCENT === $state ) {
			return $this->try_transition( 'quiescent', 'replaying', 'entered_replaying_at', $requested_via, $requested_by );
		}

		if ( QuiescenceState::DRAINING === $state ) {
			return $this->try_transition( 'draining', 'replaying', 'entered_replaying_at', $requested_via, $requested_by );
		}

		return false;
	}

	/**
	 * The buffer-vs-process decision for one inbound webhook update
	 * (docs/adr/0040 §3): opens a transaction, locks Table 1's singleton
	 * row, and — still inside that transaction — either commits and
	 * reports `process` (state is `idle`), or durably buffers the encrypted
	 * update and commits, reporting `buffered`. Serializes against
	 * `attempt_replaying_to_idle()`'s own use of the identical lock, so the
	 * two can never interleave into a stranded row (§3's required
	 * invariant).
	 *
	 * @param int                  $bot_id      The receiving bot.
	 * @param int                  $update_id   Telegram's own update_id.
	 * @param string               $update_type The update's type (metadata only).
	 * @param array<string, mixed> $raw_payload The full decoded update body.
	 *
	 * @return string 'process' or 'buffered'.
	 */
	public function decide_webhook_disposition( int $bot_id, int $update_id, string $update_type, array $raw_payload ): string {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'START TRANSACTION' );

		$state = $wpdb->get_var(
			$wpdb->prepare( "SELECT state FROM {$table} WHERE id = %d FOR UPDATE", self::SINGLETON_ID ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		if ( 'idle' === $state ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'COMMIT' );

			return 'process';
		}

		$this->deferred_updates->buffer( $bot_id, $update_id, $update_type, $raw_payload );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );

		return 'buffered';
	}

	/**
	 * The final `replaying → idle` CAS (docs/adr/0040 §3/§6): opens a
	 * transaction, locks the identical Table 1 row
	 * `decide_webhook_disposition()` locks, and — still inside that
	 * transaction — re-counts the unreplayed backlog. Only if the count is
	 * exactly zero and state is still `replaying` does it perform the CAS
	 * to `idle` and commit; otherwise it rolls back (no writes occurred)
	 * and reports the remaining count. Called only by
	 * `replay-deferred-updates`, once every currently-known unreplayed row
	 * has already been successfully processed by that same run.
	 *
	 * @param string   $requested_via 'wp-cli' for every caller in this milestone.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 *
	 * @return array{success: bool, remaining: int}
	 */
	public function attempt_replaying_to_idle( string $requested_via = 'wp-cli', ?int $requested_by = null ): array {
		global $wpdb;

		$state_table    = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;
		$deferred_table = $wpdb->prefix . Migrator::QUIESCENCE_DEFERRED_UPDATES_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'START TRANSACTION' );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT state, token FROM {$state_table} WHERE id = %d FOR UPDATE", self::SINGLETON_ID ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		// Widened predicate (docs/adr/0042 §3): a row resolved by ordinary
		// legacy replay (`replayed_at`), a successful Support Chat handoff
		// (`handed_off_at`), or an explicitly resolved UT-only incident
		// (`incident_resolved_at`) no longer counts against this CAS. An
		// unresolved incident correctly, structurally blocks this
		// transition — taken inside the identical lock
		// decide_webhook_disposition() already uses, so this remains the
		// same single authoritative barrier ADR-0040 §3 already proves
		// cannot strand a row.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$deferred_table} WHERE replayed_at IS NULL AND handed_off_at IS NULL AND incident_resolved_at IS NULL" );

		if ( null === $row || 'replaying' !== $row['state'] || $remaining > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return array(
				'success'   => false,
				'remaining' => $remaining,
			);
		}

		$new_token = wp_generate_uuid4();
		$now       = current_time( 'mysql', true );

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$state_table} SET state = %s, token = %s, exited_at = %s, updated_at = %s WHERE id = %d AND state = %s AND token = %s",
				'idle',
				$new_token,
				$now,
				$now,
				self::SINGLETON_ID,
				'replaying',
				$row['token']
			)
		);

		if ( 1 !== $updated ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return array(
				'success'   => false,
				'remaining' => $remaining,
			);
		}

		$this->transitions->record( 'replaying', 'idle', $new_token, $requested_by, $requested_via );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );

		return array(
			'success'   => true,
			'remaining' => 0,
		);
	}

	/**
	 * Mints a narrow, epoch-bound replay authority — non-null only when
	 * `state === 'replaying'` (docs/adr/0040 §3). The sole caller permitted
	 * to obtain one is the internal replayer driven by
	 * `replay-deferred-updates`.
	 *
	 * @return DeferredReplayContext|null
	 */
	public function issue_replay_context(): ?DeferredReplayContext {
		if ( QuiescenceState::REPLAYING !== $this->state() ) {
			return null;
		}

		return DeferredReplayContext::issue( $this->token() );
	}

	/**
	 * Whether a replay context is currently valid for this gate — its
	 * token matches Table 1's current token and state is still `replaying`
	 * (docs/adr/0040 §3 defense against a stale context surviving into a
	 * later, different replaying episode).
	 *
	 * @param DeferredReplayContext|null $context The context to validate, or null.
	 *
	 * @return bool
	 */
	public function is_valid_replay_context( ?DeferredReplayContext $context ): bool {
		if ( null === $context ) {
			return false;
		}

		return QuiescenceState::REPLAYING === $this->state() && $context->token() === $this->token();
	}

	/**
	 * The full per-category drain breakdown (docs/adr/0040 §5), in the
	 * fixed order `status`/`confirm` report. Every count is zero once
	 * genuinely drained.
	 *
	 * @return array<string, int>
	 */
	public function drain_breakdown(): array {
		$buckets = $this->fetch_pending_job_buckets();

		return array(
			'conversation_create_topic'   => $buckets['conversation_create_topic'],
			'conversation_delete_topic'   => $buckets['conversation_delete_topic'],
			'topic_deletion_leases'       => $this->active_topic_deletion_leases_count(),
			'conversation_route_outbound' => $buckets['conversation_route_outbound'],
			'telegram_send_message'       => $buckets['telegram_send_message'],
			'ai_draft_generate'           => $buckets['ai_draft_generate'],
			'ai_draft_generation_leases'  => $this->active_ai_draft_generation_leases_count(),
		);
	}

	/**
	 * Performs one CAS with its matching audit-trail insert, both inside
	 * one transaction.
	 *
	 * @param string   $from_state       The state transitioned from.
	 * @param string   $to_state         The state transitioned to.
	 * @param string   $entered_at_column The Table 1 column stamped with "now" for this transition, e.g. `entered_draining_at`.
	 * @param string   $requested_via    'wp-cli' for every caller in this milestone.
	 * @param int|null $requested_by     The WP-CLI-authenticated OS user, if known.
	 *
	 * @return bool
	 */
	private function try_transition( string $from_state, string $to_state, string $entered_at_column, string $requested_via, ?int $requested_by ): bool {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_STATE_TABLE;

		$new_token = wp_generate_uuid4();
		$now       = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'START TRANSACTION' );

		$allowed_columns = array( 'entered_draining_at', 'entered_quiescent_at', 'entered_replaying_at' );

		if ( ! in_array( $entered_at_column, $allowed_columns, true ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return false;
		}

		$updated = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$table} SET state = %s, token = %s, {$entered_at_column} = %s, updated_at = %s WHERE id = %d AND state = %s",
				$to_state,
				$new_token,
				$now,
				$now,
				self::SINGLETON_ID,
				$from_state
			)
		);

		if ( 1 !== $updated ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( 'ROLLBACK' );

			return false;
		}

		$this->transitions->record( $from_state, $to_state, $new_token, $requested_by, $requested_via );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'COMMIT' );

		return true;
	}

	/**
	 * Pending-action counts for each of the five drain job types, sharing
	 * one query pass (docs/adr/0040 §5, Context items 1–2): fetches
	 * candidate pending action IDs scoped to this plugin's own hook/group
	 * via Action Scheduler's own store API, then decodes each candidate's
	 * `args` JSON to bucket by job_type — necessary because every job type
	 * shares the identical hook/group, including Support Chat adapter
	 * `telegram_send_message` traffic. For `telegram_send_message`
	 * specifically, a pending action is counted only when its
	 * `payload.destination_id` is owned by a legacy conversation (the
	 * `destination_id`-join refinement) — a Support Chat channel binding's
	 * `destination_id` is never also a legacy conversation's, by the
	 * UNIQUE(destination_id) exclusivity constraint (ADR-0031), so it is
	 * never counted here.
	 *
	 * @return array<string, int>
	 */
	private function fetch_pending_job_buckets(): array {
		$buckets = array_fill_keys( self::DRAIN_JOB_TYPES, 0 );

		if ( ! $this->schema_health->is_available() || ! class_exists( ActionScheduler::class ) ) {
			return $buckets;
		}

		$ids = ActionScheduler::store()->query_actions(
			array(
				'hook'   => WorkerRunner::HOOK,
				'group'  => WorkerRunner::GROUP,
				'status' => ActionScheduler_Store::STATUS_PENDING,
			)
		);

		if ( empty( $ids ) ) {
			return $buckets;
		}

		global $wpdb;

		$actions_table          = $wpdb->prefix . 'actionscheduler_actions';
		$ids                    = array_map( 'intval', (array) $ids );
		$placeholders           = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$legacy_destination_ids = $this->legacy_destination_ids();

		$job_type_path       = '$[0].job_type';
		$destination_id_path = '$[0].payload.destination_id';

		// Action Scheduler's own `args` column holds the actual JSON only
		// when it is short enough to fit its indexed VARCHAR column
		// (ActionScheduler_DBStore::save_action_to_db()); once a payload
		// exceeds that length — as every job type here does, carrying a
		// job_id UUID plus a payload sub-object — `args` instead holds an
		// md5 hash and the real JSON moves to `extended_args`. Both must be
		// considered.
		$effective_args = "IF(extended_args IS NOT NULL AND extended_args != '', extended_args, args)";

		$rows = $wpdb->get_results(
			$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				"SELECT JSON_UNQUOTE(JSON_EXTRACT({$effective_args}, %s)) AS job_type, JSON_UNQUOTE(JSON_EXTRACT({$effective_args}, %s)) AS destination_id FROM {$actions_table} WHERE action_id IN ({$placeholders})",
				array_merge( array( $job_type_path, $destination_id_path ), $ids )
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();

		foreach ( $rows as $row ) {
			$job_type = (string) ( $row['job_type'] ?? '' );

			if ( ! isset( $buckets[ $job_type ] ) ) {
				continue;
			}

			if ( 'telegram_send_message' === $job_type ) {
				$destination_id = null !== $row['destination_id'] ? (int) $row['destination_id'] : null;

				if ( null === $destination_id || ! in_array( $destination_id, $legacy_destination_ids, true ) ) {
					continue;
				}
			}

			++$buckets[ $job_type ];
		}

		return $buckets;
	}

	/**
	 * Every `destination_id` currently owned by a legacy conversation — the
	 * join key disambiguating legacy-origin `telegram_send_message` jobs
	 * from Support Chat adapter jobs of the identical job type (docs/adr/0040
	 * §5, `conversations.destination_id` is UNIQUE per Migrator::step_29).
	 *
	 * @return array<int, int>
	 */
	private function legacy_destination_ids(): array {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( "SELECT DISTINCT destination_id FROM {$table} WHERE destination_id IS NOT NULL" );

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Active topic-deletion leases (docs/adr/0040 §5): a delete_pending
	 * conversation with a still-unexpired claim.
	 *
	 * @return int
	 */
	private function active_topic_deletion_leases_count(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::CONVERSATIONS_TABLE;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE topic_lifecycle_state = %s AND topic_delete_claim_expires_at > %s",
				'delete_pending',
				current_time( 'mysql', true )
			)
		);

		return null === $count ? 0 : (int) $count;
	}

	/**
	 * Active AI draft generation leases (docs/adr/0040 §5): a generating
	 * draft with a still-unexpired lease.
	 *
	 * @return int
	 */
	private function active_ai_draft_generation_leases_count(): int {
		if ( ! $this->schema_health->is_available() ) {
			return 0;
		}

		global $wpdb;

		$table = $wpdb->prefix . Migrator::AI_DRAFTS_TABLE;

		$count = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND generation_lease_expires_at > %s",
				'generating',
				current_time( 'mysql', true )
			)
		);

		return null === $count ? 0 : (int) $count;
	}
}
