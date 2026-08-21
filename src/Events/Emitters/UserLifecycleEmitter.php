<?php
/**
 * User registration, role change, and password-reset event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WP_User;

/**
 * Thin, reviewed callbacks on user_register, set_user_role, and
 * after_password_reset. None of these occurrences is deduplicable — each
 * is a one-shot, non-retried WordPress core action (M02 plan §8). The
 * password-reset emitter never reads the new plaintext password.
 */
final class UserLifecycleEmitter {

	public const USER_REGISTERED   = 'wordpress.user_registered';
	public const USER_ROLE_CHANGED = 'wordpress.user_role_changed';
	public const PASSWORD_RESET    = 'wordpress.password_reset';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$registry->register(
			self::USER_REGISTERED,
			1,
			array( 'subject.user_id' => Classification::PUBLIC ),
			array( 'subject.user_id' ),
			array( 'subject.user_id' )
		);

		$registry->register(
			self::USER_ROLE_CHANGED,
			1,
			array(
				'subject.user_id'       => Classification::PUBLIC,
				'payload.new_role'      => Classification::PUBLIC,
				'payload.old_roles_csv' => Classification::INTERNAL,
			),
			array( 'subject.user_id', 'payload.new_role', 'payload.old_roles_csv' ),
			array( 'subject.user_id', 'payload.new_role' )
		);

		$registry->register(
			self::PASSWORD_RESET,
			1,
			array( 'subject.user_id' => Classification::PUBLIC ),
			array( 'subject.user_id' ),
			array( 'subject.user_id' )
		);
	}

	/**
	 * The user_register callback.
	 *
	 * @param int $user_id The newly registered user's ID.
	 */
	public function on_user_registered( int $user_id ): void {
		universal_telegram_emit_event(
			self::USER_REGISTERED,
			array( 'subject' => array( 'user_id' => $user_id ) ),
			wp_generate_uuid4()
		);
	}

	/**
	 * The set_user_role callback.
	 *
	 * @param int                $user_id  The user whose role changed.
	 * @param string             $role     The new role.
	 * @param array<int, string> $old_roles The user's previous roles.
	 */
	public function on_role_changed( int $user_id, string $role, array $old_roles ): void {
		universal_telegram_emit_event(
			self::USER_ROLE_CHANGED,
			array(
				'subject' => array( 'user_id' => $user_id ),
				'payload' => array(
					'new_role'      => $role,
					'old_roles_csv' => implode( ',', $old_roles ),
				),
			),
			wp_generate_uuid4()
		);
	}

	/**
	 * The after_password_reset callback. $new_pass is never read.
	 *
	 * @param WP_User $user     The user whose password was reset.
	 * @param string  $new_pass The new plaintext password. Never dereferenced.
	 */
	public function on_password_reset( WP_User $user, string $new_pass ): void {
		universal_telegram_emit_event(
			self::PASSWORD_RESET,
			array( 'subject' => array( 'user_id' => (int) $user->ID ) ),
			wp_generate_uuid4()
		);
	}
}
