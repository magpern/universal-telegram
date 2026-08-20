<?php
/**
 * Webhook registration and rotation coordinator.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Telegram\Configuration;

use UniversalTelegram\Audit\AuditLogger;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Privacy\Classification;
use UniversalTelegram\Telegram\Client\TelegramApiClient;
use UniversalTelegram\Telegram\Client\TelegramApiException;
use UniversalTelegram\Telegram\Client\TelegramApiResult;

/**
 * Implements the four failure-safe operations of docs/adr/0013: register()
 * (initial registration, sends the bot's one existing active secret, never
 * generates a second one), rotate() (starts a genuinely new rotation),
 * retry_pending() (resends the byte-identical pending secret), and
 * rollback() (re-affirms active, discarding pending only on a confirmed
 * clean success). Every operation unconditionally records
 * webhook_last_attempt_at before branching on its outcome. No operation
 * here, and no other code path in this milestone, ever discards, replaces,
 * or promotes a pending secret on the basis of elapsed time.
 */
final class WebhookRegistrationCoordinator {

	/**
	 * Constructor.
	 *
	 * @param BotProfileRepository $bots            Reads and mutates secret/registration-state fields.
	 * @param TelegramApiClient    $client          Calls Telegram's own setWebhook.
	 * @param AuditLogger          $audit_logger    Records every step of the protocol.
	 * @param string               $webhook_base_url The REST route base URL, e.g. rest_url('universal-telegram/v1/webhook/').
	 */
	public function __construct(
		private readonly BotProfileRepository $bots,
		private readonly TelegramApiClient $client,
		private readonly AuditLogger $audit_logger,
		private readonly string $webhook_base_url
	) {}

	/**
	 * Initial registration: sends the bot's one existing active secret.
	 * Never generates or stores a second secret. Valid only when no
	 * rotation is in progress.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return RegistrationOutcome
	 */
	public function register( BotProfile $bot ): RegistrationOutcome {
		if ( $bot->has_pending_secret() ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$token = $this->decrypt_token( $bot );

		if ( null === $token ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$secret = $this->decrypt_active_secret( $bot );

		if ( null === $secret ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$outcome = $this->attempt_set_webhook( $token, $bot->bot_uuid(), $secret );
		$this->bots->touch_last_attempt( $bot->id() );

		if ( RegistrationOutcome::SUCCESS === $outcome ) {
			$this->bots->mark_registered( $bot->id() );
			$this->record( 'telegram_webhook_registration_confirmed_immediate', $bot );
		} elseif ( RegistrationOutcome::REJECTED === $outcome ) {
			$this->bots->mark_unregistered( $bot->id() );
			$this->record( 'telegram_webhook_registration_rejected', $bot );
		} else {
			$this->bots->mark_uncertain( $bot->id() );
			$this->record( 'telegram_webhook_registration_uncertain', $bot );
		}

		return $outcome;
	}

	/**
	 * Starts a new rotation: writes a genuinely new pending secret, then
	 * attempts to register it. Valid only when no rotation is already in
	 * progress.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return RegistrationOutcome
	 */
	public function rotate( BotProfile $bot ): RegistrationOutcome {
		if ( $bot->has_pending_secret() ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$token = $this->decrypt_token( $bot );

		if ( null === $token ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$new_secret = bin2hex( random_bytes( 24 ) );
		$this->bots->start_pending_secret( $bot->id(), $new_secret );
		$this->record( 'telegram_webhook_secret_rotation_initiated', $bot );

		$outcome = $this->attempt_set_webhook( $token, $bot->bot_uuid(), $new_secret );
		$this->bots->touch_last_attempt( $bot->id() );

		$this->resolve_rotation_outcome( $bot, $outcome );

		return $outcome;
	}

	/**
	 * Resends the existing, unmodified pending secret. Valid only when a
	 * rotation is already in progress. Never generates or substitutes a
	 * different secret.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return RegistrationOutcome
	 */
	public function retry_pending( BotProfile $bot ): RegistrationOutcome {
		if ( ! $bot->has_pending_secret() ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$token = $this->decrypt_token( $bot );

		if ( null === $token ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$pending = $this->decrypt_pending_secret( $bot );

		if ( null === $pending ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$this->record( 'telegram_webhook_secret_rotation_retry_attempted', $bot );

		$outcome = $this->attempt_set_webhook( $token, $bot->bot_uuid(), $pending );
		$this->bots->touch_last_attempt( $bot->id() );

		$this->resolve_rotation_outcome( $bot, $outcome );

		return $outcome;
	}

	/**
	 * Re-affirms the existing active secret. Only a confirmed clean
	 * success discards the pending secret; a failed or uncertain rollback
	 * leaves both secrets completely untouched.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return RegistrationOutcome
	 */
	public function rollback( BotProfile $bot ): RegistrationOutcome {
		if ( ! $bot->has_pending_secret() ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$token = $this->decrypt_token( $bot );

		if ( null === $token ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$active = $this->decrypt_active_secret( $bot );

		if ( null === $active ) {
			return RegistrationOutcome::INVALID_STATE;
		}

		$outcome = $this->attempt_set_webhook( $token, $bot->bot_uuid(), $active );
		$this->bots->touch_last_attempt( $bot->id() );

		if ( RegistrationOutcome::SUCCESS === $outcome ) {
			$this->bots->discard_pending_secret( $bot->id() );
			$this->bots->mark_registered( $bot->id() );
			$this->record( 'telegram_webhook_secret_rotation_rollback_confirmed', $bot );
		} elseif ( RegistrationOutcome::REJECTED === $outcome ) {
			$this->record( 'telegram_webhook_secret_rotation_rollback_failed', $bot );
		} else {
			$this->record( 'telegram_webhook_secret_rotation_rollback_uncertain', $bot );
		}

		return $outcome;
	}

	/**
	 * Applies rotate()'s and retry_pending()'s identical three-outcome
	 * handling.
	 *
	 * @param BotProfile          $bot     The bot.
	 * @param RegistrationOutcome $outcome The attempt's outcome.
	 */
	private function resolve_rotation_outcome( BotProfile $bot, RegistrationOutcome $outcome ): void {
		if ( RegistrationOutcome::SUCCESS === $outcome ) {
			$this->bots->promote_pending_secret( $bot->id() );
			$this->record( 'telegram_webhook_secret_rotation_confirmed_immediate', $bot );
		} elseif ( RegistrationOutcome::REJECTED === $outcome ) {
			$this->bots->discard_pending_secret( $bot->id() );
			$this->record( 'telegram_webhook_secret_rotation_rejected', $bot );
		} else {
			$this->bots->mark_uncertain( $bot->id() );
			$this->record( 'telegram_webhook_secret_rotation_uncertain', $bot );
		}
	}

	/**
	 * Calls setWebhook and classifies the outcome into exactly three cases.
	 *
	 * @param string $token    The bot's decrypted token.
	 * @param string $bot_uuid The bot's opaque webhook-route UUID.
	 * @param string $secret   The secret to send as secret_token.
	 *
	 * @return RegistrationOutcome SUCCESS, REJECTED, or UNCERTAIN.
	 */
	private function attempt_set_webhook( string $token, string $bot_uuid, string $secret ): RegistrationOutcome {
		try {
			$result = $this->client->set_webhook( $token, $this->webhook_base_url . $bot_uuid, $secret );
		} catch ( TelegramApiException $exception ) {
			return RegistrationOutcome::UNCERTAIN;
		}

		return $this->classify( $result );
	}

	/**
	 * Classifies a setWebhook API result into success/rejected/uncertain.
	 *
	 * @param TelegramApiResult $result The API result.
	 *
	 * @return RegistrationOutcome
	 */
	private function classify( TelegramApiResult $result ): RegistrationOutcome {
		if ( $result->is_network_error() ) {
			return RegistrationOutcome::UNCERTAIN;
		}

		return $result->ok() ? RegistrationOutcome::SUCCESS : RegistrationOutcome::REJECTED;
	}

	/**
	 * Decrypts a bot's own token, or null if unavailable.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return string|null
	 */
	private function decrypt_token( BotProfile $bot ): ?string {
		$result = $this->bots->decrypt_token( $bot );

		return CredentialState::AVAILABLE === $result->state() ? $result->plaintext() : null;
	}

	/**
	 * Decrypts a bot's own active webhook secret, or null if unavailable.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return string|null
	 */
	private function decrypt_active_secret( BotProfile $bot ): ?string {
		$result = $this->bots->decrypt_webhook_secret( $bot );

		return CredentialState::AVAILABLE === $result->state() ? $result->plaintext() : null;
	}

	/**
	 * Decrypts a bot's own pending webhook secret, or null if unavailable.
	 *
	 * @param BotProfile $bot The bot.
	 *
	 * @return string|null
	 */
	private function decrypt_pending_secret( BotProfile $bot ): ?string {
		$result = $this->bots->decrypt_pending_webhook_secret( $bot );

		return null !== $result && CredentialState::AVAILABLE === $result->state() ? $result->plaintext() : null;
	}

	/**
	 * Records a protocol-step audit entry.
	 *
	 * @param string     $action The audit action name.
	 * @param BotProfile $bot    The bot.
	 */
	private function record( string $action, BotProfile $bot ): void {
		$this->audit_logger->record(
			$action,
			'user',
			get_current_user_id(),
			array( 'bot_id' => $bot->id() ),
			array( 'bot_id' => Classification::INTERNAL ),
			Classification::INTERNAL
		);
	}
}
