<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\AI;

use UniversalTelegram\Administration\AI\AIDiagnosticsPanel;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\SchemaHealth;
use UniversalTelegram\Queue\RetryPolicy;
use UniversalTelegram\Telegram\Reliability\CircuitBreaker;
use WP_UnitTestCase;

/**
 * docs/adr/0028 decision 6: read-only aggregate counts and circuit state
 * only — never draft content, a credential, or a model identifier.
 */
final class AIDiagnosticsPanelTest extends WP_UnitTestCase {

	protected function tear_down(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_circuit_breaker_state WHERE scope_type = 'ai_provider'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		parent::tear_down();
	}

	private function drafts(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_id(): int {
		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );

		return $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null )->id();
	}

	public function test_renders_status_counts_and_circuit_state_without_leaking_draft_content(): void {
		$drafts = $this->drafts();
		$draft  = $drafts->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim  = $drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'secret draft body text', '[]', str_repeat( 'a', 64 ), 'v1' );

		$another = $drafts->create( $this->conversation_id(), 1, 'openai', 'gpt-4o-mini', 'v1' );

		$panel = new AIDiagnosticsPanel( $drafts, new CircuitBreaker( new SchemaHealth(), new RetryPolicy() ) );

		ob_start();
		$panel->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Closed', $output );
		$this->assertStringNotContainsString( 'secret draft body text', $output );
	}
}
