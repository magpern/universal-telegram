<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Core\Security;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Core\Security\CredentialState;
use UniversalTelegram\Core\Security\CredentialUnavailableException;
use UniversalTelegram\Core\Security\CredentialVault;

final class CredentialVaultTest extends TestCase {

	private const CONTEXT = 'diagnostics.self_test';

	public function test_encrypt_then_decrypt_round_trips(): void {
		$vault = new CredentialVault();

		$stored = $vault->encrypt( 'super-secret-value', self::CONTEXT );
		$result = $vault->decrypt( $stored, self::CONTEXT );

		$this->assertSame( CredentialState::AVAILABLE, $result->state() );
		$this->assertSame( 'super-secret-value', $result->plaintext() );
	}

	public function test_decrypting_under_a_different_context_never_returns_plaintext(): void {
		$vault = new CredentialVault();

		$stored = $vault->encrypt( 'super-secret-value', 'context-a' );
		$result = $vault->decrypt( $stored, 'context-b' );

		$this->assertSame( CredentialState::INVALIDATED, $result->state() );
		$this->assertNull( $result->plaintext() );
	}

	public function test_a_malformed_explicit_key_constant_fails_closed_without_falling_through(): void {
		$vault = new class() extends CredentialVault {
			protected function explicit_key_constant(): ?string {
				return 'not-a-valid-64-character-hex-string';
			}
		};

		$this->expectException( CredentialUnavailableException::class );

		$vault->encrypt( 'value', self::CONTEXT );
	}

	public function test_a_key_material_change_invalidates_without_erasing_the_ciphertext(): void {
		$original_key = str_repeat( 'ab', 32 );
		$rotated_key  = str_repeat( 'cd', 32 );

		$vault_before = new class( $original_key ) extends CredentialVault {
			public function __construct( private string $key ) {}
			protected function explicit_key_constant(): ?string {
				return $this->key;
			}
		};

		$vault_after = new class( $rotated_key ) extends CredentialVault {
			public function __construct( private string $key ) {}
			protected function explicit_key_constant(): ?string {
				return $this->key;
			}
		};

		$stored = $vault_before->encrypt( 'super-secret-value', self::CONTEXT );

		$result = $vault_after->decrypt( $stored, self::CONTEXT );

		$this->assertSame( CredentialState::INVALIDATED, $result->state() );

		// The stored ciphertext is byte-for-byte unmodified: decrypting it
		// again with the original key still works.
		$still_works = $vault_before->decrypt( $stored, self::CONTEXT );
		$this->assertSame( CredentialState::AVAILABLE, $still_works->state() );
		$this->assertSame( 'super-secret-value', $still_works->plaintext() );
	}

	public function test_reencrypt_moves_a_value_to_the_currently_resolved_key(): void {
		$old_key = str_repeat( 'ab', 32 );
		$new_key = str_repeat( 'cd', 32 );

		$vault_old = new class( $old_key ) extends CredentialVault {
			public function __construct( private string $key ) {}
			protected function explicit_key_constant(): ?string {
				return $this->key;
			}
		};

		$vault_new = new class( $new_key ) extends CredentialVault {
			public function __construct( private string $key ) {}
			protected function explicit_key_constant(): ?string {
				return $this->key;
			}
		};

		$stored_under_old = $vault_old->encrypt( 'super-secret-value', self::CONTEXT );

		$stored_under_new = $vault_new->reencrypt( $stored_under_old, self::CONTEXT, $old_key );

		$new_result = $vault_new->decrypt( $stored_under_new, self::CONTEXT );
		$this->assertSame( CredentialState::AVAILABLE, $new_result->state() );
		$this->assertSame( 'super-secret-value', $new_result->plaintext() );

		$old_result = $vault_old->decrypt( $stored_under_new, self::CONTEXT );
		$this->assertSame( CredentialState::INVALIDATED, $old_result->state() );
	}
}
