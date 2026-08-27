<?php
/**
 * Unit tests for the legacy binding preparation boundary's WP-CLI gate.
 *
 * @package UniversalTelegram
 */

declare( strict_types=1 );

namespace UniversalTelegram\Tests\SupportChatAdapter\Migration;

use PHPUnit\Framework\TestCase;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Migration\DeferredUpdateRepository;
use UniversalTelegram\Migration\QuiescenceGate;
use UniversalTelegram\Migration\QuiescenceTransitionRepository;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\SupportChatAdapter\ChannelBindingRepository;
use UniversalTelegram\SupportChatAdapter\Migration\LegacyBindingImportContextRejectedException;
use UniversalTelegram\SupportChatAdapter\Migration\LegacyBindingImportServiceV1;

/**
 * ChannelBindingRepository is `final` and cannot be doubled, but this gate
 * (assert_wp_cli_context(), mirroring LegacyExportServiceV1's identical
 * gate) throws before any collaborator method is ever called, so real,
 * cheaply-constructed instances (no DB access at construction time) are
 * sufficient here. No collaborator's DB-touching methods are exercised
 * without WP_CLI defined, unlike this repository's integration suite,
 * which always runs with WP_CLI already true and cannot observe this path
 * at all (see the integration test file's own class-level note).
 *
 * @covers \UniversalTelegram\SupportChatAdapter\Migration\LegacyBindingImportServiceV1
 */
final class LegacyBindingImportServiceV1Test extends TestCase {

	private function service(): LegacyBindingImportServiceV1 {
		$schema_health = new SchemaHealth();

		return new LegacyBindingImportServiceV1(
			$this->createMock( ConversationRepository::class ),
			new ChannelBindingRepository( $schema_health ),
			new QuiescenceGate(
				$schema_health,
				new DeferredUpdateRepository( $schema_health, new CredentialVault() ),
				new QuiescenceTransitionRepository()
			),
			$schema_health
		);
	}

	/**
	 * The service's only authority check is `defined('WP_CLI') && WP_CLI`
	 * (Support Chat ADR-0009 §7, ADR-0041 §2) — the identical gate
	 * LegacyExportServiceV1 already uses. Web, Ajax, REST, and cron
	 * invocations all share the identical precondition this service can
	 * observe: the constant is not defined.
	 */
	public function test_rejects_web_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	public function test_rejects_ajax_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	public function test_rejects_rest_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	public function test_rejects_cron_context(): void {
		$this->assertRejectsOutsideWpCli();
	}

	private function assertRejectsOutsideWpCli(): void {
		$this->expectException( LegacyBindingImportContextRejectedException::class );
		$this->service()->import_batch( array() );
	}
}
