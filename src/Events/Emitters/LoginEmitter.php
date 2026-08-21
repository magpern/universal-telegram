<?php
/**
 * Login/admin-login/login-failure event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Core\Capabilities\CapabilityRegistrar;
use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WP_User;

/**
 * Thin, reviewed callbacks on wp_login and wp_login_failed. None of these
 * occurrences is deduplicable — WordPress core fires each exactly once per
 * attempt and never retries them (M02 plan §8).
 */
final class LoginEmitter {

	public const LOGIN_SUCCEEDED = 'wordpress.login_succeeded';
	public const ADMIN_LOGIN     = 'wordpress.admin_login';
	public const LOGIN_FAILED    = 'wordpress.login_failed';

	/**
	 * Registers this emitter's event types.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$user_fields = array(
			'actor.user_id'    => Classification::PUBLIC,
			'actor.user_login' => Classification::INTERNAL,
		);

		$registry->register( self::LOGIN_SUCCEEDED, 1, $user_fields, array( 'actor.user_id', 'actor.user_login' ), array( 'actor.user_id' ) );
		$registry->register( self::ADMIN_LOGIN, 1, $user_fields, array( 'actor.user_id', 'actor.user_login' ), array( 'actor.user_id' ) );
		$registry->register( self::LOGIN_FAILED, 1, array( 'context.username' => Classification::INTERNAL ), array( 'context.username' ), array() );
	}

	/**
	 * The wp_login callback.
	 *
	 * @param string  $user_login The logged-in username.
	 * @param WP_User $user       The logged-in user.
	 */
	public function on_login( string $user_login, WP_User $user ): void {
		$data = array(
			'actor' => array(
				'user_id'    => (int) $user->ID,
				'user_login' => $user_login,
			),
		);

		universal_telegram_emit_event( self::LOGIN_SUCCEEDED, $data, wp_generate_uuid4() );

		if ( user_can( $user, CapabilityRegistrar::MANAGE ) ) {
			universal_telegram_emit_event( self::ADMIN_LOGIN, $data, wp_generate_uuid4() );
		}
	}

	/**
	 * The wp_login_failed callback.
	 *
	 * @param string         $username The submitted username. Free-form,
	 *                                  possibly attacker-controlled; never
	 *                                  projected to durable history.
	 * @param \WP_Error|null $error   The failure detail. Never read.
	 */
	public function on_login_failed( string $username, $error = null ): void {
		universal_telegram_emit_event(
			self::LOGIN_FAILED,
			array( 'context' => array( 'username' => $username ) ),
			wp_generate_uuid4()
		);
	}
}
