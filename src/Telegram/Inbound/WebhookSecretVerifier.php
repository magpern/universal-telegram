<?php
/**
 * Webhook secret verification.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Inbound;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Telegram\Configuration\BotProfile;
use UniversalTelegram\Telegram\Configuration\BotProfileRepository;

/**
 * Implements the full authenticity and traffic-based confirmation protocol
 * of docs/adr/0013: constant-time comparison against the bot's active
 * secret first, then its pending secret (if one is currently set — with no
 * time limit on that acceptance); a pending match promotes it to active; an
 * active match while a registration is 'uncertain' with no pending secret
 * confirms the initial registration. No code path here ever discards a
 * pending secret or changes anything on an active match while a pending
 * secret still exists — that is not evidence of anything.
 */
final class WebhookSecretVerifier {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository $bots         Reads and mutates pending-secret/registration-state fields.
	 * @param AuditLogger          $audit_logger Records every traffic-based confirmation.
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly AuditLogger $audit_logger
	) {}

	/**
	 * Verifies an inbound request's secret header against a bot.
	 *
	 * @param BotProfile  $bot            The bot the request's bot_uuid resolved to.
	 * @param string|null $header_secret  The X-Telegram-Bot-Api-Secret-Token header value, if present.
	 *
	 * @return bool Whether the request is authentic.
	 */
	public function verify( BotProfile $bot, ?string $header_secret ): bool {
		if ( null === $header_secret || '' === $header_secret ) {
			return false;
		}

		$active = $this->bots->decrypt_webhook_secret( $bot );

		if ( CredentialState::AVAILABLE === $active->state() && null !== $active->plaintext() && hash_equals( $active->plaintext(), $header_secret ) ) {
			if ( 'uncertain' === $bot->webhook_registration_state() && ! $bot->has_pending_secret() ) {
				$this->bots->mark_registered( $bot->id() );
				$this->record( 'telegram_webhook_registration_confirmed_via_traffic', $bot );
			}

			return true;
		}

		if ( $bot->has_pending_secret() ) {
			$pending = $this->bots->decrypt_pending_webhook_secret( $bot );

			if ( null !== $pending && CredentialState::AVAILABLE === $pending->state() && null !== $pending->plaintext() && hash_equals( $pending->plaintext(), $header_secret ) ) {
				$this->bots->promote_pending_secret( $bot->id() );
				$this->record( 'telegram_webhook_secret_rotation_confirmed_via_traffic', $bot );

				return true;
			}
		}

		return false;
	}

	/**
	 * Records a traffic-based-confirmation audit entry.
	 *
	 * @param string     $action The audit action name.
	 * @param BotProfile $bot    The bot being confirmed.
	 */
	private function record( string $action, BotProfile $bot ): void {
		$this->audit_logger->record(
			$action,
			'system',
			null,
			array( 'bot_id' => $bot->id() ),
			array( 'bot_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
	}
}
