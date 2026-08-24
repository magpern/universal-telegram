<?php
/**
 * Shared, cross-feature provider-concurrency admission mutex.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\AI\Provider;

use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;

/**
 * Locks the existing, migration-seeded singleton universal_telegram_ai_config
 * row (id=1) — the same admission mutex AI\Draft\AiDraftRepository::
 * claim_for_generation() already uses — and sums a caller-supplied set of
 * active-generation counts against a shared cap before admitting a claim,
 * so M09's AI draft assistant and M11B's operational-summary AI
 * summarization together never exceed one real, site-wide provider-
 * concurrency limit
 * (docs/plans/m11b-digests-and-operational-intelligence-plan-v1.md §3).
 *
 * This class deliberately never imports, type-hints, or instantiates
 * AiDraftRepository or any other domain-specific repository: it accepts
 * only plain `callable`/`int` values, supplied by each domain's own
 * already-permitted caller. That is what lets it enforce a shared cap
 * without requiring any change to AiDraftRepository's existing six-class
 * structural access allow-list (docs/adr/0028 decision 6) — this class is
 * not, and does not need to be, one of those six classes.
 */
final class ProviderConcurrencyGate {

	/**
	 * Constructor.
	 *
	 * @param SchemaHealth $schema_health Checked before every operation.
	 */
	public function __construct( private readonly SchemaHealth $schema_health ) {}

	/**
	 * Attempts to admit one claim: locks the shared config-row mutex, sums
	 * every supplied active-count callable, and — only if the sum is below
	 * the cap — invokes the supplied claim callable while still holding the
	 * lock, then commits. Returns null, without ever invoking $claim, if
	 * the cap is already reached (deferred, not failed) or the row cannot
	 * be locked.
	 *
	 * @param int                         $max_concurrent        The shared, site-wide cap (M09's own value, e.g. 2).
	 * @param array<int, callable(): int> $active_count_providers One callable per domain sharing this cap, each returning that domain's own current active-generation count.
	 * @param callable(): mixed           $claim                 Invoked, still inside the held lock, only if admission succeeds.
	 *
	 * @return mixed The claim callable's own return value, or null if deferred/unavailable.
	 */
	public function claim_or_defer( int $max_concurrent, array $active_count_providers, callable $claim ) {
		if ( ! $this->schema_health->is_available() ) {
			return null;
		}

		global $wpdb;

		$config_table = $wpdb->prefix . Migrator::AI_CONFIG_TABLE;

		$wpdb->query( 'START TRANSACTION' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$locked = $wpdb->get_var( "SELECT id FROM {$config_table} WHERE id = 1 FOR UPDATE" );

		if ( null === $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$total_active = 0;
		foreach ( $active_count_providers as $count_provider ) {
			$total_active += $count_provider();
		}

		if ( $total_active >= $max_concurrent ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}

		$result = $claim();

		$wpdb->query( 'COMMIT' );

		return $result;
	}
}
