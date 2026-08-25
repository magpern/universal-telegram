<?php
/**
 * OwnKeyManager test double that always reports itself unavailable.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Auth\Support;

use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\SupportChatAdapter\Auth\OwnKeyManager;

/**
 * Simulates "no key pair generated yet" or "vault unavailable" without
 * touching WordPress options.
 */
final class UnavailableOwnKeyManager extends OwnKeyManager {

	public function __construct() {
		parent::__construct( new CredentialVault() );
	}

	public function public_key(): ?array {
		return null;
	}

	public function secret_key_raw(): ?string {
		return null;
	}

	public function ensure_key_pair(): ?array {
		return null;
	}
}
