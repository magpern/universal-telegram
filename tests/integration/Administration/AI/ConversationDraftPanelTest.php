<?php
/**
 * @package UniversalTelegram
 */

namespace UniversalTelegram\Tests\Integration\Administration\AI;

use UniversalTelegram\Administration\AI\ConversationDraftPanel;
use UniversalTelegram\AI\Config\AIProviderRepository;
use UniversalTelegram\AI\Draft\AiDraftRepository;
use UniversalTelegram\Conversations\ConversationRepository;
use UniversalTelegram\Conversations\VisitorTokenGenerator;
use UniversalTelegram\Core\Security\CredentialVault;
use UniversalTelegram\Persistence\Migrator;
use UniversalTelegram\Persistence\SchemaHealth;
use WP_UnitTestCase;

/**
 * Docs/adr/0028 decision 6: the operator-facing review/approve/discard
 * surface, with a fixed "NOT SENT" banner and no automatic send of any
 * kind — approving here only changes a status column.
 */
final class ConversationDraftPanelTest extends WP_UnitTestCase {

	/**
	 * Explicit reset, not an assumption: the singleton ai_config row and
	 * the ai_drafts table are not reliably rolled back by
	 * WP_UnitTestCase's per-test transaction wrapping once a DDL
	 * statement elsewhere in the same run has forced an implicit commit.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reset_ai_state();
	}

	protected function tearDown(): void {
		$this->reset_ai_state();
		parent::tearDown();
	}

	private function reset_ai_state(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}universal_telegram_ai_drafts" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE {$wpdb->prefix}universal_telegram_ai_config SET enabled = 0, model = '', api_key_ciphertext = NULL WHERE id = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function drafts(): AiDraftRepository {
		return new AiDraftRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function provider(): AIProviderRepository {
		return new AIProviderRepository( new SchemaHealth(), new CredentialVault() );
	}

	private function conversation_with_ack(): \UniversalTelegram\Conversations\Conversation {
		$this->provider()->set_credential( 'sk-test-key' );
		$this->provider()->update_settings( 'gpt-4o-mini', true );
		$ack_version = $this->provider()->get()->ack_policy_version();

		$conversations = new ConversationRepository( new SchemaHealth(), new CredentialVault(), new VisitorTokenGenerator() );
		$conversation  = $conversations->create( wp_generate_uuid4(), 'hashed-secret', 1, null );

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . Migrator::CONVERSATIONS_TABLE,
			array( 'ai_ack_policy_version' => $ack_version ),
			array( 'id' => $conversation->id() )
		);

		return $conversations->find( $conversation->id() );
	}

	public function test_renders_the_request_button_when_eligible_and_no_draft_exists(): void {
		$conversation = $this->conversation_with_ack();
		$panel        = new ConversationDraftPanel( $this->drafts(), $this->provider() );

		ob_start();
		$panel->render( $conversation );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Request AI Draft', $output );
	}

	public function test_renders_the_not_sent_banner_and_body_for_a_generated_draft(): void {
		$conversation = $this->conversation_with_ack();
		$drafts       = $this->drafts();
		$draft        = $drafts->create( $conversation->id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim        = $drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'Suggested reply text.', '[]', str_repeat( 'a', 64 ), 'v1' );

		$panel = new ConversationDraftPanel( $drafts, $this->provider() );

		ob_start();
		$panel->render( $conversation );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'NOT SENT', $output );
		$this->assertStringContainsString( 'Suggested reply text.', $output );
		$this->assertStringContainsString( 'Approve', $output );
		$this->assertStringContainsString( 'Discard', $output );
	}

	public function test_mark_approved_only_changes_status_never_dispatches_anything(): void {
		$conversation = $this->conversation_with_ack();
		$drafts       = $this->drafts();
		$draft        = $drafts->create( $conversation->id(), 1, 'openai', 'gpt-4o-mini', 'v1' );
		$claim        = $drafts->claim_for_generation( $draft->draft_uuid(), 90, 5 );
		$drafts->complete_generation( $claim['draft_id'], $draft->draft_uuid(), $claim['lease_token'], 'body', '[]', str_repeat( 'a', 64 ), 'v1' );

		$this->assertTrue( $drafts->mark_approved( $draft->id(), 7 ) );

		$approved = $drafts->find( $draft->id() );
		$this->assertSame( 'approved', $approved->status() );
		$this->assertSame( 7, $approved->reviewed_by_user_id() );
	}
}
