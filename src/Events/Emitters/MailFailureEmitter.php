<?php
/**
 * Failed wp_mail() event emission.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Events\Emitters;

use UniversalTelegram\Events\Registry;
use UniversalTelegram\Privacy\Classification;
use WP_Error;

/**
 * A thin, reviewed callback on wp_mail_failed. Never reads the error
 * message text (which may embed recipient addresses or other message
 * content) — only the fixed WP_Error code. Not deduplicable — WordPress
 * does not itself retry wp_mail(), so consecutive firings are independent
 * failures (M02 plan §8).
 */
final class MailFailureEmitter {

	public const EMAIL_SENDING_FAILED = 'wordpress.email_sending_failed';

	/**
	 * Registers this emitter's event type.
	 *
	 * @param Registry $registry The current request's event registry.
	 */
	public function register_event_types( Registry $registry ): void {
		$registry->register(
			self::EMAIL_SENDING_FAILED,
			1,
			array( 'payload.error_code' => Classification::PUBLIC ),
			array( 'payload.error_code' ),
			array( 'payload.error_code' )
		);
	}

	/**
	 * The wp_mail_failed callback.
	 *
	 * @param WP_Error $error The failure detail. Only its fixed error code is ever read.
	 */
	public function on_mail_failed( WP_Error $error ): void {
		universal_telegram_emit_event(
			self::EMAIL_SENDING_FAILED,
			array( 'payload' => array( 'error_code' => $error->get_error_code() ) ),
			wp_generate_uuid4()
		);
	}
}
