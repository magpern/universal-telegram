<?php
/**
 * Quiescence state-transition audit trail.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Migration;

use UniversalTelegram\Persistence\Migrator;

/**
 * Append-only writer for Table 2,
 * `{$wpdb->prefix}universal_telegram_quiescence_transitions`
 * (docs/adr/0040 §4). Contains no payload content — only the transition
 * shape, its token, and who/how requested it. `QuiescenceGate` inserts
 * exactly one row here in the same database transaction as every
 * successful Table 1 CAS.
 */
final class QuiescenceTransitionRepository {

	/**
	 * Records one successful transition. Callers are responsible for
	 * calling this inside the same transaction as the Table 1 CAS it
	 * documents.
	 *
	 * @param string   $from_state    The state transitioned from.
	 * @param string   $to_state      The state transitioned to.
	 * @param string   $token         The new epoch token stamped by this transition.
	 * @param int|null $requested_by  The WP-CLI-authenticated OS user, if known.
	 * @param string   $requested_via 'wp-cli' for every row in this milestone.
	 */
	public function record( string $from_state, string $to_state, string $token, ?int $requested_by, string $requested_via ): void {
		global $wpdb;

		$table = $wpdb->prefix . Migrator::QUIESCENCE_TRANSITIONS_TABLE;

		$wpdb->insert(
			$table,
			array(
				'from_state'    => $from_state,
				'to_state'      => $to_state,
				'token'         => $token,
				'requested_by'  => $requested_by,
				'requested_via' => $requested_via,
				'occurred_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s' )
		);
	}
}
