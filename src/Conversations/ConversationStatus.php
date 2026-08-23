<?php
/**
 * Conversation status transition map.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Conversations;

/**
 * The frozen status/assignment transition map (M05 plan §7, docs/adr/0021).
 * This class only describes which transitions are structurally valid; it
 * does not decide which actor may perform one — that is enforced by which
 * code path calls ConversationRepository::transition() in the first place.
 * `resolved` is reachable from any open/waiting state to support M07's
 * operator UI. M07 (docs/adr/0026) adds a single `resolved -> open` reopen
 * path; `archived` remains fully terminal, since an archived conversation's
 * bearer secret has already been revoked and reopening it has no coherent
 * visitor-side contract.
 */
final class ConversationStatus {

	public const NEW                  = 'new';
	public const OPEN                 = 'open';
	public const WAITING_FOR_VISITOR  = 'waiting_for_visitor';
	public const WAITING_FOR_OPERATOR = 'waiting_for_operator';
	public const RESOLVED             = 'resolved';
	public const ARCHIVED             = 'archived';

	/**
	 * The complete, frozen transition map.
	 *
	 * @return array<string, array<int, string>>
	 */
	private static function map(): array {
		return array(
			self::NEW                  => array( self::OPEN ),
			self::OPEN                 => array( self::WAITING_FOR_VISITOR, self::WAITING_FOR_OPERATOR, self::RESOLVED ),
			self::WAITING_FOR_VISITOR  => array( self::OPEN, self::RESOLVED ),
			self::WAITING_FOR_OPERATOR => array( self::OPEN, self::RESOLVED ),
			self::RESOLVED             => array( self::ARCHIVED, self::OPEN ),
			self::ARCHIVED             => array(),
		);
	}

	/**
	 * Whether a transition from one status to another is structurally valid.
	 *
	 * @param string $from The current status.
	 * @param string $to   The proposed status.
	 *
	 * @return bool
	 */
	public static function is_valid_transition( string $from, string $to ): bool {
		$map = self::map();

		return isset( $map[ $from ] ) && in_array( $to, $map[ $from ], true );
	}

	/**
	 * Every status this class recognizes.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array_keys( self::map() );
	}
}
